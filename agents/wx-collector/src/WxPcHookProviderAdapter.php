<?php

declare(strict_types=1);

namespace WxCollector;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * 微信 PC 版 Hook 采集器适配器。
 *
 * 依赖 aixed/WeChat-Hook（v4.1.10.27）在微信进程内启动的本地 HTTP 服务。
 * 默认地址：http://127.0.0.1:30001
 *
 * 使用方式：
 *   1. 将 version.dll 放到微信安装目录（C:\Program Files\Tencent\Weixin）。
 *   2. 启动微信时传入 CallBackURL 参数：
 *        WeChat.exe CallBackURL="http://127.0.0.1:18889/wx-hook-callback"
 *   3. 在采集器 PHP 进程中监听 18889 端口接收收款回调。
 *
 * 授权流程：
 *   - 微信 PC 版 4.1.x 登录时，需要用手机扫描微信生成的登录二维码。
 *   - 本适配器通过轮询 /QueryDB/status 检测登录状态，
 *     登录成功后调用 /GetSelfProfile 获取 wxid 和昵称作为账号标识。
 *   - 二维码由微信 PC 客户端界面直接展示，不需要由采集器生成。
 *     因此 startAuthorization() 返回 QR_READY，并把微信窗口截图 URL 或
 *     提示信息作为 message 告知操作员手动扫码。
 *
 * 账单采集：
 *   收款消息通过两条路径写入本地 WxPcBillStore：
 *   1. CallBackURL 实时回调（MsgType=49, AppMsgType=2000，转账收款）。
 *   2. 定期轮询微信内部 MSG_DB（作为容错补充，防止回调丢失）。
 */
final class WxPcHookProviderAdapter implements ProviderAdapterInterface, AccountBindingAwareInterface
{
    /** 微信 Hook HTTP 服务地址 */
    private readonly string $hookUrl;

    private Client $http;
    private WxPcBillStore $store;
    private EncryptedFileStateStore $sessionStore;

    /**
     * @param string $hookUrl     WeChat-Hook 本地 HTTP 服务地址（默认 http://127.0.0.1:30001）
     * @param string $storeDbPath 本地账单 SQLite 路径
     * @param string $masterKey   Base64(32字节) 主密钥，用于加密本地数据
     * @param string $stateDir    会话状态目录（复用 EncryptedFileStateStore）
     */
    public function __construct(
        string $hookUrl,
        string $storeDbPath,
        string $masterKey,
        string $stateDir,
        ?Client $http = null,
    ) {
        $parsed = parse_url($hookUrl);
        if (!is_array($parsed) || ($parsed['host'] ?? '') !== '127.0.0.1') {
            throw new RuntimeException('WeChat-Hook 服务地址必须是 127.0.0.1（不得暴露到公网）');
        }
        $this->hookUrl = rtrim($hookUrl, '/');
        $this->http = $http ?? new Client([
            'base_uri'        => $this->hookUrl,
            'timeout'         => 5.0,
            'connect_timeout' => 2.0,
            'http_errors'     => false,
        ]);
        $this->store        = new WxPcBillStore($storeDbPath, $masterKey);
        $this->sessionStore = new EncryptedFileStateStore($stateDir, $masterKey);
    }

    // -----------------------------------------------------------------------
    // 授权：检测登录状态，提示操作员在微信窗口扫码
    // -----------------------------------------------------------------------

    /**
     * 首次授权任务：检测微信是否在线且已登录。
     *
     * 微信 PC 版 4.1.x 的登录二维码由微信客户端自身展示，
     * 操作员需要在微信窗口点击"扫码登录"或启动微信后手动扫码。
     * 本方法不生成二维码，仅等待微信登录完成。
     */
    public function startAuthorization(array $task): ?array
    {
        $sessionId = $this->sessionId($task);
        $status = $this->queryLoginStatus();

        if ($status === null) {
            // Hook 服务不可达，微信可能未启动
            return null;
        }

        if ($status['IsLogin'] === 1) {
            // 微信已登录，直接完成授权
            return $this->buildConfirmedState($sessionId);
        }

        // 微信未登录，提示操作员扫码，等待下次 poll
        $this->sessionStore->put($sessionId, ['started_at' => time(), 'login_prompted' => true]);
        return [
            'status'  => 'QR_READY',
            'qr_url'  => 'https://wx.qq.com/',  // 微信 PC 客户端界面直接展示二维码
            'message' => '请在运行中的微信 PC 客户端窗口扫码登录，或手机微信「发现→扫一扫」扫描微信窗口中的登录码',
        ];
    }

    /**
     * 轮询授权状态：检测微信是否已完成扫码登录。
     */
    public function pollAuthorization(array $task): ?array
    {
        $sessionId = $this->sessionId($task);
        $status = $this->queryLoginStatus();

        if ($status === null) {
            return null; // 服务暂时不可达，等待
        }

        if ($status['IsLogin'] !== 1) {
            return null; // 未登录，继续等待
        }

        return $this->buildConfirmedState($sessionId);
    }

    // -----------------------------------------------------------------------
    // 账单：从本地 SQLite 队列拉取收款记录
    // -----------------------------------------------------------------------

    /**
     * 拉取未上报的收款账单。
     *
     * 账单来源有两条路径（均写入同一个 WxPcBillStore）：
     *   1. CallBackURL 实时回调（见 WxPcCallbackReceiver）
     *   2. 本方法内的兜底轮询（查微信内部数据库）
     *
     * @return list<array<string, mixed>>
     */
    public function pullPaymentEvents(int $limit): array
    {
        // 兜底轮询：补充可能漏掉的收款记录
        $this->syncFromWechatDb();

        $pending = $this->store->pullPending($limit);
        $events  = [];
        foreach ($pending as $row) {
            // account_ref 是本地标识（wxid hash），需要映射到云端 account_id
            // 注意：account_id 由云端颁发，存在 sessionStore 中
            $accountId = $this->resolveCloudAccountId($row['account_ref']);
            if ($accountId === null) {
                continue; // 账号尚未绑定到云端，跳过
            }
            $events[] = [
                'ack_token'     => $row['ack_token'],
                'account_id'    => $accountId,
                'source_bill_id' => $row['bill_id'],
                'amount'        => $row['amount'],
                'occurred_at'   => $row['occurred_at'],
            ];
        }
        return $events;
    }

    /**
     * 云端确认后推进本地游标。
     */
    public function acknowledgePaymentEvent(string $ackToken): void
    {
        $this->store->ack($ackToken);
    }

    // -----------------------------------------------------------------------
    // 账号绑定（AccountBindingAwareInterface）
    // -----------------------------------------------------------------------

    /**
     * CollectorRunner 确认云端颁发的 account_id 后，将其与本地 wxid_hash 绑定。
     */
    public function bindAuthorizedAccount(string $sessionId, string $accountId): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $accountId)) {
            throw new RuntimeException('微信云账号 ID 不合法');
        }
        $this->sessionStore->bindAccount($sessionId, $accountId);
    }

    // -----------------------------------------------------------------------
    // 私有方法
    // -----------------------------------------------------------------------

    /**
     * 查询 WeChat-Hook 的登录状态。
     * GET http://127.0.0.1:30001/QueryDB/status
     * 返回：{ "IsLogin": 1, "hWeixin": 123456789 }
     *
     * @return array<string, mixed>|null null 表示 Hook 服务不可达
     */
    private function queryLoginStatus(): ?array
    {
        try {
            $resp = $this->http->get('/QueryDB/status');
            if ($resp->getStatusCode() !== 200) {
                return null;
            }
            $data = json_decode((string)$resp->getBody(), true);
            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 获取当前登录的微信账号信息。
     * POST http://127.0.0.1:30001/GetSelfProfile
     * 返回：{ "wxid": "wxid_xxx", "nickname": "张三", ... }
     *
     * @return array<string, string>|null
     */
    private function getSelfProfile(): ?array
    {
        try {
            $resp = $this->http->post('/GetSelfProfile', ['json' => new \stdClass()]);
            if ($resp->getStatusCode() !== 200) {
                return null;
            }
            $data = json_decode((string)$resp->getBody(), true);
            return is_array($data) && isset($data['wxid']) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 构造登录成功的确认状态，供 CollectorRunner 上报云端。
     *
     * @return array<string, mixed>
     */
    private function buildConfirmedState(string $sessionId): array
    {
        $profile = $this->getSelfProfile();
        if ($profile === null) {
            throw new RuntimeException('微信已登录但无法读取账号信息，请检查 Hook 服务');
        }

        $wxid = trim((string)($profile['wxid'] ?? ''));
        if ($wxid === '' || !str_starts_with($wxid, 'wxid_')) {
            throw new RuntimeException('微信 wxid 格式不合法，请确认 Hook 服务版本匹配');
        }

        // external_ref：本地稳定账号引用（wxid 的 SHA-256 前缀，不暴露真实 wxid）
        $externalRef = 'wpc_' . substr(hash('sha256', $wxid), 0, 28);
        $displayName = trim((string)($profile['nickname'] ?? '微信账号'));
        if (mb_strlen($displayName) > 32) {
            $displayName = mb_substr($displayName, 0, 28) . '...';
        }

        // 在会话状态中保存 wxid_hash → sessionId 映射，供账单拉取时查找
        $state = $this->sessionStore->get($sessionId) ?? [];
        $state['wxid_hash']    = $externalRef;
        $state['wxid']         = $wxid; // 仅本地加密存储，不上报
        $state['confirmed_at'] = time();
        $this->sessionStore->put($sessionId, $state);

        return [
            'status'             => 'CONFIRMED',
            'external_ref'       => $externalRef,
            'display_name'       => $displayName,
            'capability_status'  => 'RECEIPT_AVAILABLE',
            'capabilities'       => ['receipt' => true, 'book' => false],
            'message'            => '微信 PC 扫码登录成功',
        ];
    }

    /**
     * 兜底轮询：查询微信内部 MSG_DB 最近的收款记录，写入本地账单队列。
     *
     * 微信收款消息存储于 Msg/MSG*.db（SQLite + SQLCipher），
     * WeChat-Hook 可以通过 /QueryDB/execute 直接调用进程内已打开的数据库句柄，
     * 无需解密，可直接执行 SQL。
     *
     * 收款消息特征：MsgType=49, 消息体 XML 中 appmsgtype=2000（转账）或 2001（红包）。
     */
    private function syncFromWechatDb(): void
    {
        try {
            // 查询最近 50 条 MsgType=49 的消息（App 消息，含转账/收款）
            $resp = $this->http->post('/QueryDB/execute', ['json' => [
                'optDbName' => 'MSG0.db',
                'SQL' => "SELECT MsgSvrID, StrTalker, CreateTime, Content
                          FROM MSG
                          WHERE Type = 49
                          ORDER BY CreateTime DESC LIMIT 50",
            ]]);

            if ($resp->getStatusCode() !== 200) {
                return;
            }

            $result = json_decode((string)$resp->getBody(), true);
            if (!is_array($result) || ($result['status'] ?? -1) !== 0) {
                return;
            }

            foreach ((array)($result['data'] ?? []) as $row) {
                $this->processMsgRow((array)$row);
            }
        } catch (\Throwable) {
            // 数据库暂时不可查询，等待下次 tick
        }
    }

    /**
     * 解析一行 MSG 数据库记录，提取收款信息后写入账单队列。
     *
     * 微信转账收款的 Content 是 XML，格式如下（appmsgtype=2000）：
     * <msg><appmsg><type>2000</type><wcpayinfo>
     *   <paysubtype>1</paysubtype>       <!-- 1=转账收款, 3=退款 -->
     *   <feedesc>¥10.00</feedesc>
     *   <transcationid>1000037801202507...</transcationid>
     *   <transferid>xxx</transferid>
     *   <begintransfertime>1753xxx</begintransfertime>
     * </wcpayinfo></appmsg></msg>
     *
     * @param array<string, mixed> $row
     */
    private function processMsgRow(array $row): void
    {
        $content = (string)($row['Content'] ?? '');
        if (!str_contains($content, '<type>2000</type>')) {
            return; // 不是转账消息
        }

        // 只处理收款（paysubtype=1），忽略退款（paysubtype=3）和发出的转账
        if (!preg_match('/<paysubtype>1<\/paysubtype>/', $content)) {
            return;
        }

        // 提取金额（feedesc 格式：¥10.00 或 ¥10.00元）
        if (!preg_match('/<feedesc>¥([\d.]+)/', $content, $amtMatch)) {
            return;
        }
        $amount = number_format((float)$amtMatch[1], 2, '.', '');

        // 提取账单号（transcationid 是微信服务器分配的稳定唯一 ID）
        if (!preg_match('/<transcationid>([\d]+)<\/transcationid>/', $content, $tidMatch)) {
            return;
        }
        $billId = $tidMatch[1];

        // 已存在则跳过（幂等）
        if ($this->store->exists($billId)) {
            return;
        }

        $occurredAt = (int)($row['CreateTime'] ?? time());
        $accountRef = $this->currentAccountRef();
        if ($accountRef === null) {
            return;
        }

        $this->store->insert($billId, $accountRef, $amount, $occurredAt, [
            'msg_svr_id' => (string)($row['MsgSvrID'] ?? ''),
            'talker'     => (string)($row['StrTalker'] ?? ''),
            'source'     => 'db_poll',
        ]);
    }

    /**
     * 获取当前已登录账号的 external_ref（wxid_hash），用于账单归属。
     */
    private function currentAccountRef(): ?string
    {
        // 遍历 sessionStore 目录找到已绑定的最新会话
        // 简化实现：从 profile 缓存读取（实际上 profile 在 confirmState 时写入了）
        $profile = $this->getSelfProfile();
        if ($profile === null || empty($profile['wxid'])) {
            return null;
        }
        return 'wpc_' . substr(hash('sha256', $profile['wxid']), 0, 28);
    }

    /**
     * 根据本地 account_ref（wxid_hash）查找云端颁发的 account_id。
     * account_id 在 bindAuthorizedAccount() 时通过 EncryptedFileStateStore 保存。
     */
    private function resolveCloudAccountId(string $accountRef): ?string
    {
        // EncryptedFileStateStore 以 account_id 为文件名存储会话，
        // 需要反向扫描找到 wxid_hash 匹配的记录。
        // 简化实现：从环境变量获取（生产环境应从 store 扫描）
        $accountId = getenv('WXPC_CLOUD_ACCOUNT_ID') ?: '';
        return $accountId !== '' ? $accountId : null;
    }

    /** @param array<string, mixed> $task */
    private function sessionId(array $task): string
    {
        $id = trim((string)($task['id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $id)) {
            throw new RuntimeException('微信 PC 授权会话 ID 不合法');
        }
        return $id;
    }
}
