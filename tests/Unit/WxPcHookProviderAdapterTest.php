<?php

declare(strict_types=1);

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use WxCollector\WxPcBillStore;
use WxCollector\WxPcCallbackReceiver;
use WxCollector\WxPcHookProviderAdapter;

final class WxPcHookProviderAdapterTest extends TestCase
{
    private string $tempDir;
    private string $dbPath;
    private string $masterKey;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wxpc-test-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0700, true);
        $this->dbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test-bills.sqlite';
        $this->masterKey = base64_encode(random_bytes(32));
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '*');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->tempDir);
    }

    public function testBillStoreInsertAndAck(): void
    {
        $store = new WxPcBillStore($this->dbPath, $this->masterKey);
        self::assertFalse($store->exists('10000378012026073000001'));

        $store->insert('10000378012026073000001', 'wpc_testref001', '88.88', 1753000000, ['test' => 'data']);
        self::assertTrue($store->exists('10000378012026073000001'));

        $pending = $store->pullPending(10);
        self::assertCount(1, $pending);
        self::assertSame('88.88', $pending[0]['amount']);
        self::assertSame('10000378012026073000001', $pending[0]['bill_id']);

        $store->ack($pending[0]['ack_token']);
        self::assertCount(0, $store->pullPending(10));
    }

    public function testCallbackReceiverParsesPaymentXml(): void
    {
        $store = new WxPcBillStore($this->dbPath, $this->masterKey);
        $receiver = new WxPcCallbackReceiver($store, 'wpc_testref001');

        $xml = '<msg><appmsg><type>2000</type><wcpayinfo>'
            . '<paysubtype>1</paysubtype>'
            . '<feedesc>¥128.50</feedesc>'
            . '<transcationid>10000378012026073000002</transcationid>'
            . '</wcpayinfo></appmsg></msg>';

        $payload = json_encode([
            'Type' => 49,
            'StrContent' => $xml,
            'CreateTime' => 1753000050,
            'MsgSvrID' => 987654321,
        ]);

        $res = $receiver->handle($payload);
        self::assertSame('ok', $res);
        self::assertTrue($store->exists('10000378012026073000002'));

        $pending = $store->pullPending(10);
        self::assertCount(1, $pending);
        self::assertSame('128.50', $pending[0]['amount']);
    }

    public function testAdapterStartAuthorizationWhenNotLoggedIn(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['IsLogin' => 0, 'hWeixin' => 0])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $adapter = new WxPcHookProviderAdapter(
            'http://127.0.0.1:30001',
            $this->dbPath,
            $this->masterKey,
            $this->tempDir,
            $client
        );

        $state = $adapter->startAuthorization(['id' => 'was_1234567890123456']);
        self::assertNotNull($state);
        self::assertSame('QR_READY', $state['status']);
    }
}
