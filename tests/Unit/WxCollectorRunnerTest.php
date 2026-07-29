<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use WxCollector\CloudGatewayInterface;
use WxCollector\CollectorRunner;
use WxCollector\AccountBindingAwareInterface;
use WxCollector\ProviderAdapterInterface;

final class WxCollectorRunnerTest extends TestCase
{
    public function testProcessesAuthorizationAndAcknowledgesAcceptedBill(): void
    {
        $cloud = new FakeCollectorCloud();
        $provider = new FakeCollectorProvider();
        $runner = new CollectorRunner('collector-01', $cloud, $provider);

        $stats = $runner->tick();

        self::assertSame(1, $stats['sessions']);
        self::assertSame(1, $stats['events']);
        self::assertSame(0, $stats['errors']);
        self::assertSame('CLAIMED', $cloud->sessionUpdates[0]['state']['status']);
        self::assertSame('QR_READY', $cloud->sessionUpdates[1]['state']['status']);
        self::assertSame(['local-cursor-1'], $provider->acknowledged);
        self::assertArrayNotHasKey('ack_token', $cloud->events[0]);
    }

    public function testDoesNotAcknowledgeBillWhenCloudRejects(): void
    {
        $cloud = new FakeCollectorCloud();
        $cloud->acceptEvents = false;
        $provider = new FakeCollectorProvider();

        $stats = (new CollectorRunner('collector-01', $cloud, $provider))->tick();

        self::assertSame(0, $stats['events']);
        self::assertSame(1, $stats['errors']);
        self::assertSame([], $provider->acknowledged);
    }

    public function testBindsConfirmedAuthorizationToCloudAccount(): void
    {
        $cloud = new FakeCollectorCloud();
        $cloud->sessions = [[
            'id' => 'was_1234567890123456',
            'status' => 'QR_READY',
            'collector_id' => 'collector-01',
        ]];
        $provider = new FakeBindingCollectorProvider();

        $stats = (new CollectorRunner('collector-01', $cloud, $provider))->tick();

        self::assertSame(0, $stats['errors']);
        self::assertSame([
            ['session_id' => 'was_1234567890123456', 'account_id' => 'wxa_1234567890123456'],
        ], $provider->bindings);
        self::assertSame('BOUND', $cloud->sessionUpdates[1]['state']['status']);
    }

    public function testRetriesLocalBindingForConfirmedCloudSession(): void
    {
        $cloud = new FakeCollectorCloud();
        $cloud->sessions = [[
            'id' => 'was_1234567890123456',
            'status' => 'CONFIRMED',
            'collector_id' => 'collector-01',
            'account_id' => 'wxa_1234567890123456',
        ]];
        $provider = new FakeBindingCollectorProvider();

        $stats = (new CollectorRunner('collector-01', $cloud, $provider))->tick();

        self::assertSame(0, $stats['errors']);
        self::assertSame('BOUND', $cloud->sessionUpdates[0]['state']['status']);
        self::assertCount(1, $provider->bindings);
    }
}

final class FakeCollectorCloud implements CloudGatewayInterface
{
    public bool $acceptEvents = true;
    public array $sessionUpdates = [];
    public array $events = [];
    public array $sessions = [];

    public function pendingSessions(): array
    {
        return $this->sessions ?: [[
            'id' => 'was_1234567890123456',
            'status' => 'WAITING_COLLECTOR',
            'collector_id' => '',
        ]];
    }

    public function updateSession(string $sessionId, array $state): array
    {
        $this->sessionUpdates[] = ['session_id' => $sessionId, 'state' => $state];
        return ($state['status'] ?? '') === 'CONFIRMED'
            ? ['accepted' => true, 'account_id' => 'wxa_1234567890123456']
            : ['accepted' => true];
    }

    public function submitPaymentEvent(array $event): array
    {
        $this->events[] = $event;
        return ['accepted' => $this->acceptEvents];
    }
}

final class FakeBindingCollectorProvider implements ProviderAdapterInterface, AccountBindingAwareInterface
{
    public array $bindings = [];

    public function startAuthorization(array $task): ?array { return null; }

    public function pollAuthorization(array $task): ?array
    {
        return [
            'status' => 'CONFIRMED',
            'external_ref' => 'authorized-account',
            'capability_status' => 'UNKNOWN',
        ];
    }

    public function pullPaymentEvents(int $limit): array { return []; }

    public function acknowledgePaymentEvent(string $ackToken): void {}

    public function bindAuthorizedAccount(string $sessionId, string $accountId): void
    {
        $this->bindings[] = ['session_id' => $sessionId, 'account_id' => $accountId];
    }
}

final class FakeCollectorProvider implements ProviderAdapterInterface
{
    public array $acknowledged = [];

    public function startAuthorization(array $task): ?array
    {
        return ['status' => 'QR_READY', 'qr_url' => 'https://provider.example/qr'];
    }

    public function pollAuthorization(array $task): ?array
    {
        return null;
    }

    public function pullPaymentEvents(int $limit): array
    {
        return [[
            'ack_token' => 'local-cursor-1',
            'account_id' => 'wxa_1234567890123456',
            'source_bill_id' => 'WX-BILL-10001',
            'amount' => '10.01',
            'occurred_at' => time(),
        ]];
    }

    public function acknowledgePaymentEvent(string $ackToken): void
    {
        $this->acknowledged[] = $ackToken;
    }
}
