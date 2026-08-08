# 微信店员通道可靠性 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将 `wxpay_clerk_adapter` 与 `wxpay-clerk-service` 改造成具备到账幂等、事务匹配、防重放、可靠回调、人工复核和主动查询恢复能力的微信店员通道。

**Architecture:** 保留插件、独立店员服务和 Gewe 接入边界，把现有同步回调流程改成“Webhook 持久化与事务匹配 → 回调发件箱 → 独立投递器”。服务内部按数据库、仓储、认证、匹配、发件箱和 HTTP 应用拆分；主站通过明确能力接口发现复核与运维通道。

**Tech Stack:** PHP 8.1+、PDO SQLite、PHPUnit 10、Guzzle 7、cURL、CXPAY/Webman 插件契约。

## Global Constraints

- 不删除、不自动启用或停用现有通道，不破坏已有配置字段。
- 不把店员服务迁移到 `wx-monitor-cloud`，不修改 Gewe 容器或实现新微信协议。
- 所有生产行为变更必须先有失败测试，并严格执行 RED → GREEN → REFACTOR。
- 金额以两位小数字符串持久化和比较，禁止用浮点数决定订单是否匹配。
- 自动匹配必须拥有稳定 `source_bill_id`；歧义或不稳定事件只进入人工复核。
- 订单匹配、事件状态、审计和发件箱写入必须位于同一 SQLite 事务。
- 云服务和回调地址必须是公网 HTTPS，HTTP 客户端禁止重定向。
- 保留主工作区已有 `getAccountByGeweAppId()` 的 O(1) 索引查询语义。
- 文案不得使用“官方、100% 零封号、零风险”等无法保证的表述。

---

## File Structure

新增的店员服务文件及职责：

```text
services/wxpay-clerk-service/
├── bin/dispatch-outbox.php              # 独立回调投递进程入口
└── src/
    ├── ApiApplication.php               # 可测试的路由与业务响应编排
    ├── ApiException.php                 # 带 HTTP 状态码的安全业务异常
    ├── HttpResponse.php                 # 状态码、头和原始 JSON 正文
    ├── Database.php                     # PDO 连接和事务
    ├── SchemaMigrator.php               # 版本化无损迁移
    ├── RequestAuthenticator.php         # HMAC、时间窗和 nonce 防重放
    ├── AccountRepository.php            # 账号及 gewe_app_id 索引查询
    ├── AuthSessionRepository.php        # 授权会话持久化
    ├── OrderRepository.php              # 订单登记、候选、查询和占用
    ├── PaymentEventRepository.php       # 到账事件幂等和状态
    ├── ReviewRepository.php             # 人工复核和审计
    ├── NonceRepository.php              # nonce 唯一登记与清理
    ├── OutboxRepository.php             # 回调任务租约和状态
    ├── PaymentMatchingService.php       # 自动/人工匹配事务
    ├── CallbackTransportInterface.php   # 可替换 HTTP 投递边界
    ├── PublicHttpsUrlGuard.php          # 公网 HTTPS 和 DNS 地址校验
    ├── CallbackPayloadSigner.php        # CXPAY 回调字段和签名
    ├── CurlCallbackTransport.php        # 生产 HTTPS 回调实现
    └── OutboxDispatcher.php             # 签名、退避和租约恢复
```

`OrderStore.php` 在全部调用迁移完成后删除；不得同时保留两套写路径。

---

### Task 1: 建立可测试的数据库与版本化迁移

**Files:**
- Create: `services/wxpay-clerk-service/src/Database.php`
- Create: `services/wxpay-clerk-service/src/SchemaMigrator.php`
- Create: `tests/Support/WxpayClerkDatabaseTestCase.php`
- Create: `tests/Unit/WxpayClerkDatabaseTest.php`
- Modify: `composer.json`

**Interfaces:**
- Produces: `Database::__construct(string $sqlitePath)`、`Database::pdo(): PDO`、`Database::transaction(callable $callback): mixed`。
- Produces: `SchemaMigrator::migrate(PDO $pdo): void`，迁移完成后包含版本表及设计文档定义的全部业务表。

- [ ] **Step 1: 写失败的历史数据库迁移测试**

```php
public function testMigratesLegacyDatabaseWithoutLosingOrdersOrAccounts(): void
{
    $legacy = new PDO('sqlite:' . $this->databasePath);
    $legacy->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id TEXT NOT NULL, channel_id TEXT NOT NULL, out_trade_no TEXT NOT NULL UNIQUE, amount TEXT NOT NULL, expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'PENDING', matched_at INTEGER, source_bill_id TEXT)");
    $legacy->exec("CREATE TABLE accounts (id TEXT PRIMARY KEY, nickname TEXT NOT NULL DEFAULT '', gewe_app_id TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'OFFLINE', last_seen_at INTEGER, created_at INTEGER NOT NULL)");
    $legacy->exec("INSERT INTO orders(account_id,channel_id,out_trade_no,amount,expires_at,created_at,status) VALUES('acc_1','ch_1','CX1001','12.30',4102444800,1700000000,'PENDING')");
    $legacy->exec("INSERT INTO accounts(id,nickname,gewe_app_id,status,created_at) VALUES('acc_1','店员','gewe_1','ONLINE',1700000000)");

    $database = new Database($this->databasePath);

    self::assertSame('CX1001', $database->pdo()->query('SELECT out_trade_no FROM orders')->fetchColumn());
    self::assertSame('gewe_1', $database->pdo()->query('SELECT gewe_app_id FROM accounts')->fetchColumn());
    self::assertSame(1, (int)$database->pdo()->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    self::assertSame(1, (int)$database->pdo()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='callback_outbox'")->fetchColumn());
}
```

- [ ] **Step 2: 运行测试并确认因 `Database` 不存在而失败**

Run: `php vendor/bin/phpunit tests/Unit/WxpayClerkDatabaseTest.php`

Expected: FAIL，错误包含 `Class "WxpayClerk\Database" not found`。

- [ ] **Step 3: 添加根项目自动加载与最小数据库实现**

在根 `composer.json` 的 PSR-4 中加入：

```json
"WxpayClerk\\": "services/wxpay-clerk-service/src/"
```

实现：

```php
final class Database
{
    private PDO $pdo;

    public function __construct(string $sqlitePath)
    {
        $this->pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        (new SchemaMigrator())->migrate($this->pdo);
    }

    public function pdo(): PDO { return $this->pdo; }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $callback($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }
}
```

`SchemaMigrator` 使用 `schema_migrations(version INTEGER PRIMARY KEY, applied_at INTEGER NOT NULL)`，通过 `CREATE TABLE IF NOT EXISTS` 和 `CREATE UNIQUE INDEX IF NOT EXISTS` 无损增加 `payment_events`、`request_nonces`、`callback_outbox`，并使用 `PRAGMA table_info` 为旧 `review_events` 增加 `payment_event_id`。

- [ ] **Step 4: 生成自动加载并运行数据库测试**

Run: `composer dump-autoload && php vendor/bin/phpunit tests/Unit/WxpayClerkDatabaseTest.php`

Expected: PASS；重复创建 `Database` 两次仍只有一个迁移版本且历史数据存在。

- [ ] **Step 5: 提交数据库基础**

```bash
git add composer.json services/wxpay-clerk-service/src/Database.php services/wxpay-clerk-service/src/SchemaMigrator.php tests/Support/WxpayClerkDatabaseTestCase.php tests/Unit/WxpayClerkDatabaseTest.php
git commit -m "refactor: add clerk database migrations"
```

---

### Task 2: 拆分账号、授权、订单和事件仓储

**Files:**
- Create: `services/wxpay-clerk-service/src/AccountRepository.php`
- Create: `services/wxpay-clerk-service/src/AuthSessionRepository.php`
- Create: `services/wxpay-clerk-service/src/OrderRepository.php`
- Create: `services/wxpay-clerk-service/src/PaymentEventRepository.php`
- Create: `services/wxpay-clerk-service/src/ReviewRepository.php`
- Create: `services/wxpay-clerk-service/src/ApiException.php`
- Create: `tests/Unit/WxpayClerkRepositoryTest.php`

**Interfaces:**
- Consumes: `Database::pdo()` 和 Task 1 创建的表。
- Produces: `AccountRepository::findByGeweAppId(string): ?array`，必须使用 `idx_accounts_gewe_app_id`。
- Produces: `OrderRepository::register(array): array{accepted:bool,idempotent:bool}`、`find(string): ?array`、`candidates(string,string,int): array`。
- Produces: `PaymentEventRepository::createOrFind(array): array{event:array,created:bool}`。
- Produces: `ReviewRepository::pending(): array`、`find(int): ?array`、`recordResolution(...)`。
- Produces: `ApiException::__construct(int $status, string $safeMessage)`，异常码等于 HTTP 状态码且只暴露安全消息。

- [ ] **Step 1: 写仓储失败测试**

```php
public function testAccountLookupAndPaymentEventAreIndexedAndIdempotent(): void
{
    $accounts = new AccountRepository($this->database->pdo());
    $events = new PaymentEventRepository($this->database->pdo());
    $accounts->save('acc_1', '店员', 'gewe_app_1', 'ONLINE');

    self::assertSame('acc_1', $accounts->findByGeweAppId('gewe_app_1')['id']);

    $first = $events->createOrFind([
        'account_id' => 'acc_1', 'source_bill_id' => 'bill_stable_001',
        'amount' => '10.00', 'payer_name' => '付款人', 'remark' => '',
        'occurred_at' => 1700000010, 'raw_hash' => str_repeat('a', 64),
    ]);
    $again = $events->createOrFind([
        'account_id' => 'acc_1', 'source_bill_id' => 'bill_stable_001',
        'amount' => '10.00', 'payer_name' => '付款人', 'remark' => '',
        'occurred_at' => 1700000010, 'raw_hash' => str_repeat('a', 64),
    ]);

    self::assertTrue($first['created']);
    self::assertFalse($again['created']);
    self::assertSame($first['event']['id'], $again['event']['id']);
}
```

另加测试：相同订单号、相同参数幂等成功；相同订单号、不同金额抛出状态码为 `409` 的 `ApiException`。

- [ ] **Step 2: 运行测试并确认仓储类缺失**

Run: `php vendor/bin/phpunit tests/Unit/WxpayClerkRepositoryTest.php`

Expected: FAIL，首个缺失类为 `AccountRepository`。

- [ ] **Step 3: 实现聚焦仓储**

订单登记接口固定为：

```php
public function register(string $accountId, string $channelId, string $outTradeNo, string $amount, int $expiresAt): array
{
    $existing = $this->find($outTradeNo);
    if ($existing !== null) {
        $same = hash_equals($existing['account_id'], $accountId)
            && hash_equals($existing['amount'], $amount)
            && (int)$existing['expires_at'] === $expiresAt;
        if (!$same) { throw new ApiException(409, '订单号已存在且参数不一致'); }
        return ['accepted' => true, 'idempotent' => true];
    }
    // INSERT PENDING；SQLite 唯一约束承担并发最终保护。
    return ['accepted' => true, 'idempotent' => false];
}
```

`PaymentEventRepository::createOrFind()` 捕获唯一约束冲突后按 `(account_id, source_bill_id)` 重新读取；若重复事件的金额或发生时间不同，抛出 `409`。

- [ ] **Step 4: 运行仓储与迁移测试**

Run: `php vendor/bin/phpunit tests/Unit/WxpayClerkRepositoryTest.php tests/Unit/WxpayClerkDatabaseTest.php`

Expected: PASS。

- [ ] **Step 5: 提交仓储拆分**

```bash
git add services/wxpay-clerk-service/src/*Repository.php services/wxpay-clerk-service/src/ApiException.php tests/Unit/WxpayClerkRepositoryTest.php
git commit -m "refactor: split clerk persistence repositories"
```

---

### Task 3: 实现请求认证和 nonce 防重放

**Files:**
- Create: `services/wxpay-clerk-service/src/NonceRepository.php`
- Create: `services/wxpay-clerk-service/src/RequestAuthenticator.php`
- Create: `services/wxpay-clerk-service/src/HttpResponse.php`
- Modify: `services/wxpay-clerk-service/src/SignatureHelper.php`
- Create: `tests/Unit/WxpayClerkRequestAuthenticatorTest.php`

**Interfaces:**
- Produces: `RequestAuthenticator::authenticate(string $method, string $path, array $headers, string $body, int $now): string`，成功返回 client ID。
- Produces: `SignatureHelper::signResponse(string $body): string`，继续兼容插件响应验签。
- Produces: `HttpResponse::json(array $data, int $status = 200): self` 和 `withHeader(string,string): self`。

- [ ] **Step 1: 写重放失败测试**

```php
public function testRejectsSecondUseOfSameNonceInsideClockWindow(): void
{
    $now = 1700000000;
    $headers = $this->signedHeaders('POST', '/v1/orders', '{}', $now, '0123456789abcdef');

    self::assertSame('client_1', $this->authenticator->authenticate('POST', '/v1/orders', $headers, '{}', $now));

    $this->expectException(ApiException::class);
    $this->expectExceptionCode(409);
    $this->authenticator->authenticate('POST', '/v1/orders', $headers, '{}', $now);
}
```

再覆盖时间偏差 301 秒、nonce 少于 16 位、签名错误和过期 nonce 清理。

- [ ] **Step 2: 运行认证测试并确认重复 nonce 未被拒绝**

Run: `php vendor/bin/phpunit tests/Unit/WxpayClerkRequestAuthenticatorTest.php`

Expected: FAIL，第二次调用未抛出 `409` 或类尚不存在。

- [ ] **Step 3: 实现认证顺序**

```php
public function authenticate(string $method, string $path, array $headers, string $body, int $now): string
{
    $clientId = trim((string)($headers['x-cxpay-client'] ?? ''));
    $timestamp = (int)($headers['x-cxpay-timestamp'] ?? 0);
    $nonce = trim((string)($headers['x-cxpay-nonce'] ?? ''));
    $signature = strtolower(trim((string)($headers['x-cxpay-signature'] ?? '')));
    if (!hash_equals($this->clientId, $clientId) || abs($now - $timestamp) > 300) {
        throw new ApiException(401, '请求身份或时间戳不合法');
    }
    if (!preg_match('/^[A-Za-z0-9_.:-]{16,128}$/', $nonce)) {
        throw new ApiException(401, '请求 nonce 不合法');
    }
    $canonical = implode("\n", [strtoupper($method), $path, (string)$timestamp, $nonce, hash('sha256', $body)]);
    if (!preg_match('/^[a-f0-9]{64}$/', $signature)
        || !hash_equals(hash_hmac('sha256', $canonical, $this->clientSecret), $signature)) {
        throw new ApiException(401, '请求签名无效');
    }
    if (!$this->nonces->claim($clientId, $nonce, $now, $now + 300)) {
        throw new ApiException(409, '请求已重放');
    }
    return $clientId;
}
```

- [ ] **Step 4: 运行认证测试和原插件验签测试**

Run: `php vendor/bin/phpunit tests/Unit/WxpayClerkRequestAuthenticatorTest.php tests/Unit/WxpayClerkAdapterPluginTest.php`

Expected: PASS。

- [ ] **Step 5: 提交防重放认证**

```bash
git add services/wxpay-clerk-service/src/NonceRepository.php services/wxpay-clerk-service/src/RequestAuthenticator.php services/wxpay-clerk-service/src/HttpResponse.php services/wxpay-clerk-service/src/SignatureHelper.php tests/Unit/WxpayClerkRequestAuthenticatorTest.php
git commit -m "feat: prevent clerk api request replay"
```

---

### Task 4: 实现事务化自动匹配和人工复核

**Files:**
- Create: `services/wxpay-clerk-service/src/PaymentMatchingService.php`
- Create: `services/wxpay-clerk-service/src/OutboxRepository.php`
- Modify: `services/wxpay-clerk-service/src/OrderMatcher.php`
- Create: `tests/Unit/WxpayClerkPaymentMatchingServiceTest.php`

**Interfaces:**
- Consumes: Task 2 仓储和 `Database::transaction()`。
- Produces: `PaymentMatchingService::ingest(array $event): array{event_id:int,status:string,out_trade_no:?string}`。
- Produces: `PaymentMatchingService::matchReview(int $eventId, string $outTradeNo, string $operator, string $note): array`。
- Produces: `PaymentMatchingService::ignoreReview(int $eventId, string $operator, string $reason): array`。
- Produces: `OutboxRepository::create(int $paymentEventId, string $outTradeNo, int $now): void`；唯一键 `payment_event_id` 保证重复调用只产生一条任务。

- [ ] **Step 1: 写到账幂等和歧义失败测试**

```php
public function testDuplicateEventMatchesOnceAndCreatesOneOutboxTask(): void
{
    $this->orders->register('acc_1', 'ch_1', 'CX2001', '8.88', 1700000600);
    $event = ['account_id'=>'acc_1','source_bill_id'=>'bill_2001','amount'=>'8.88','payer_name'=>'张三','remark'=>'','occurred_at'=>1700000100,'raw_hash'=>str_repeat('b',64)];

    $first = $this->matching->ingest($event);
    $again = $this->matching->ingest($event);

    self::assertSame('MATCHED', $first['status']);
    self::assertSame($first['event_id'], $again['event_id']);
    self::assertSame(1, $this->countRows('callback_outbox'));
}
```

另加两个同金额订单必须进入 `REVIEW_REQUIRED` 的测试，以及人工匹配金额不一致返回 `409` 的测试。

- [ ] **Step 2: 运行测试并确认同步 `OrderMatcher` 无法保证事务与幂等**

Run: `php vendor/bin/phpunit tests/Unit/WxpayClerkPaymentMatchingServiceTest.php`

Expected: FAIL，`PaymentMatchingService` 不存在。

- [ ] **Step 3: 实现单事务匹配**

```php
return $this->database->transaction(function () use ($event): array {
    $stored = $this->events->createOrFind($event);
    if (!$stored['created']) {
        return $this->result($stored['event']);
    }
    $candidates = $this->orders->candidates($event['account_id'], $event['amount'], $event['occurred_at']);
    if (count($candidates) !== 1) {
        $status = $candidates === [] ? 'UNMATCHED' : 'REVIEW_REQUIRED';
        $this->events->markReviewRequired((int)$stored['event']['id'], $status);
        $this->reviews->create((int)$stored['event']['id'], $status);
        return ['event_id'=>(int)$stored['event']['id'],'status'=>$status,'out_trade_no'=>null];
    }
    $order = $candidates[0];
    $this->orders->markMatched((string)$order['out_trade_no'], (int)$stored['event']['id']);
    $this->events->markMatched((int)$stored['event']['id'], (string)$order['out_trade_no']);
    $this->outbox->create((int)$stored['event']['id'], (string)$order['out_trade_no']);
    return ['event_id'=>(int)$stored['event']['id'],'status'=>'MATCHED','out_trade_no'=>(string)$order['out_trade_no']];
});
```

备注命中仍必须验证账号、金额、状态和有效期。删除“歧义时自动取最早订单”的分支。

- [ ] **Step 4: 运行匹配、仓储和迁移测试**

Run: `php vendor/bin/phpunit tests/Unit/WxpayClerkPaymentMatchingServiceTest.php tests/Unit/WxpayClerkRepositoryTest.php tests/Unit/WxpayClerkDatabaseTest.php`

Expected: PASS。

- [ ] **Step 5: 提交事务匹配**

```bash
git add services/wxpay-clerk-service/src/PaymentMatchingService.php services/wxpay-clerk-service/src/OutboxRepository.php services/wxpay-clerk-service/src/OrderMatcher.php tests/Unit/WxpayClerkPaymentMatchingServiceTest.php
git commit -m "fix: make clerk payment matching transactional"
```

---

### Task 5: 增加可靠回调发件箱和投递进程

**Files:**
- Modify: `services/wxpay-clerk-service/src/OutboxRepository.php`
- Create: `services/wxpay-clerk-service/src/CallbackTransportInterface.php`
- Create: `services/wxpay-clerk-service/src/PublicHttpsUrlGuard.php`
- Create: `services/wxpay-clerk-service/src/CallbackPayloadSigner.php`
- Create: `services/wxpay-clerk-service/src/CurlCallbackTransport.php`
- Create: `services/wxpay-clerk-service/src/OutboxDispatcher.php`
- Create: `services/wxpay-clerk-service/bin/dispatch-outbox.php`
- Delete: `services/wxpay-clerk-service/src/CxpayCallback.php`
- Create: `tests/Unit/WxpayClerkOutboxDispatcherTest.php`

**Interfaces:**
- Produces: `OutboxRepository::claimDue(int $now, int $leaseSeconds): ?array`、`markSent(int,int): void`、`reschedule(int,int,int,string): void`、`markFailed(int,int,string): void`。
- Produces: `CallbackTransportInterface::post(string $url, array $fields): array{status:int,body:string}`。
- Produces: `PublicHttpsUrlGuard::assertAllowed(string $url): void` 和 `CallbackPayloadSigner::fields(array $task, int $timestamp): array`。
- Produces: `OutboxDispatcher::dispatchOne(int $now): bool`。

- [ ] **Step 1: 写失败后恢复测试**

```php
public function testFailedDeliveryIsRetriedAndEventuallyMarkedSent(): void
{
    $transport = new SequenceCallbackTransport([
        ['status'=>503,'body'=>'unavailable'],
        ['status'=>200,'body'=>'success'],
    ]);
    $dispatcher = $this->dispatcher($transport);

    self::assertTrue($dispatcher->dispatchOne(1700000000));
    self::assertSame('PENDING', $this->outboxRow()['status']);
    $retryAt = (int)$this->outboxRow()['next_attempt_at'];
    self::assertTrue($dispatcher->dispatchOne($retryAt));
    self::assertSame('SENT', $this->outboxRow()['status']);
}
```

再覆盖租约过期恢复、正文不是 `success`、超过最大次数进入 `FAILED`、回调签名字段与插件一致。

- [ ] **Step 2: 运行发件箱测试并确认缺少持久化重试**

Run: `php vendor/bin/phpunit tests/Unit/WxpayClerkOutboxDispatcherTest.php`

Expected: FAIL，`OutboxDispatcher` 不存在。

- [ ] **Step 3: 实现投递与退避**

退避固定为 `min(3600, 5 * 2 ** (attempts - 1))` 秒；默认最大尝试 12 次。成功条件必须同时满足 HTTP 2xx 和 `trim($body) === 'success'`。

```php
public function dispatchOne(int $now): bool
{
    $task = $this->outbox->claimDue($now, 60);
    if ($task === null) { return false; }
    try {
        $result = $this->transport->post($this->notifyUrl, $this->signedFields($task, $now));
        if ($result['status'] < 200 || $result['status'] >= 300 || trim($result['body']) !== 'success') {
            throw new RuntimeException('CXPAY 未确认回调');
        }
        $this->outbox->markSent((int)$task['id'], $now);
    } catch (Throwable $e) {
        $this->retryOrFail($task, $now, $e->getMessage());
    }
    return true;
}
```

`PublicHttpsUrlGuard` 要求 URL scheme 为 `https`、端口为 443 或未指定、不得含 userinfo；通过 `dns_get_record()` 解析全部 A/AAAA 记录，并用 `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` 拒绝任一私有、保留、回环或链路本地地址。`CurlCallbackTransport` 每次投递前调用该 guard，设置 `CURLOPT_FOLLOWLOCATION=false`、`CURLOPT_SSL_VERIFYPEER=true`、`CURLOPT_SSL_VERIFYHOST=2`、连接超时 3 秒、总超时 10 秒。

将旧 `CxpayCallback` 的字段生成和 HMAC 逻辑迁移到 `CallbackPayloadSigner` 后删除旧类，确保生产代码不存在同步发送入口。`dispatch-outbox.php` 循环调用 `dispatchOne(time())`；无任务时休眠 500 毫秒，并用 `pcntl_signal`（扩展存在时）响应 `SIGTERM/SIGINT` 后退出。

- [ ] **Step 4: 运行发件箱和插件回调测试**

Run: `php vendor/bin/phpunit tests/Unit/WxpayClerkOutboxDispatcherTest.php tests/Unit/WxpayClerkAdapterPluginTest.php`

Expected: PASS。

- [ ] **Step 5: 提交可靠回调**

```bash
git add services/wxpay-clerk-service/src/OutboxRepository.php services/wxpay-clerk-service/src/CallbackTransportInterface.php services/wxpay-clerk-service/src/PublicHttpsUrlGuard.php services/wxpay-clerk-service/src/CallbackPayloadSigner.php services/wxpay-clerk-service/src/CurlCallbackTransport.php services/wxpay-clerk-service/src/OutboxDispatcher.php services/wxpay-clerk-service/bin/dispatch-outbox.php tests/Unit/WxpayClerkOutboxDispatcherTest.php
git add -u services/wxpay-clerk-service/src/CxpayCallback.php
git commit -m "feat: add reliable clerk callback outbox"
```

---

### Task 6: 重写可测试 API 并补齐订单查询和人工复核

**Files:**
- Create: `services/wxpay-clerk-service/src/ApiApplication.php`
- Delete: `services/wxpay-clerk-service/src/ApiServer.php`
- Modify: `services/wxpay-clerk-service/src/AuthSessionManager.php`
- Modify: `services/wxpay-clerk-service/src/WechatWebhookHandler.php`
- Modify: `services/wxpay-clerk-service/index.php`
- Create: `tests/Unit/WxpayClerkApiApplicationTest.php`

**Interfaces:**
- Consumes: Tasks 2–5 的认证、仓储、匹配和发件箱。
- Produces: `ApiApplication::handle(string $method, string $path, array $headers, string $body, string $remoteIp, int $now): HttpResponse`。
- Produces: `POST /wechat/message/{webhook_token}` 只在持久化成功后返回 200。
- Produces: `AuthSessionManager::__construct(GeweApiClient $client, AuthSessionRepository $sessions, AccountRepository $accounts, string $webhookBaseUrl, int $ttl)`，不再依赖 `OrderStore`。

- [ ] **Step 1: 写缺失路由和人工匹配失败测试**

```php
public function testOrderQueryReturnsMatchedPaymentWhileCallbackIsPending(): void
{
    $this->seedMatchedOrder('CX3001', '9.99', 1700000100, 'PENDING');
    $response = $this->signedRequest('GET', '/v1/orders/CX3001');

    self::assertSame(200, $response->status);
    self::assertSame([
        'paid'=>true, 'out_trade_no'=>'CX3001', 'amount'=>'9.99',
        'occurred_at'=>1700000100, 'callback_status'=>'PENDING',
    ], json_decode($response->body, true));
    self::assertTrue($this->responseSignatureIsValid($response));
}
```

另加：人工匹配产生发件箱任务；重复 Webhook 十次只有一个事件；错误业务响应仍带有效签名；错误 webhook token 返回 401。

- [ ] **Step 2: 运行 API 测试并确认订单查询为 404**

Run: `php vendor/bin/phpunit tests/Unit/WxpayClerkApiApplicationTest.php`

Expected: FAIL，`GET /v1/orders/CX3001` 返回 404 或 `ApiApplication` 不存在。

- [ ] **Step 3: 实现纯应用路由并瘦身入口**

`ApiApplication` 先对所有 `/v1/` 请求执行认证，再派发业务；业务 `ApiException` 转成签名 JSON 响应。Webhook 路由必须同时使用常量时间比较验证路径 token，并根据配置的 CIDR/IP 白名单校验 `$remoteIp`。`index.php` 只负责读取配置、规范化请求头、构造依赖并发送 `HttpResponse`；删除旧 `ApiServer`，避免保留第二套路由实现。

订单查询响应固定为：

```php
return HttpResponse::json([
    'paid' => $order['status'] === 'MATCHED',
    'out_trade_no' => $order['out_trade_no'],
    'amount' => $order['amount'],
    'occurred_at' => (int)($event['occurred_at'] ?? 0),
    'callback_status' => (string)($outbox['status'] ?? ''),
]);
```

Webhook 处理器删除同步 `CxpayCallback::send()`，只调用 `PaymentMatchingService::ingest()`。

- [ ] **Step 4: 运行 API、匹配、发件箱和授权测试**

Run: `php vendor/bin/phpunit tests/Unit/WxpayClerkApiApplicationTest.php tests/Unit/WxpayClerkPaymentMatchingServiceTest.php tests/Unit/WxpayClerkOutboxDispatcherTest.php`

Expected: PASS。

- [ ] **Step 5: 提交完整服务 API**

```bash
git add services/wxpay-clerk-service/src/ApiApplication.php services/wxpay-clerk-service/src/AuthSessionManager.php services/wxpay-clerk-service/src/WechatWebhookHandler.php services/wxpay-clerk-service/index.php tests/Unit/WxpayClerkApiApplicationTest.php
git add -u services/wxpay-clerk-service/src/ApiServer.php
git commit -m "feat: complete clerk service api contract"
```

---

### Task 7: 强化店员插件客户端和回调校验

**Files:**
- Modify: `plugins-src/wxpay-clerk-adapter/src/ProviderClient.php`
- Modify: `plugins-src/wxpay-clerk-adapter/src/Driver.php`
- Modify: `plugins-src/wxpay-clerk-adapter/manifest.json`
- Modify: `tests/Unit/WxpayClerkAdapterPluginTest.php`

**Interfaces:**
- Consumes: Task 6 完整 API。
- Produces: 只有服务返回 `accepted=true` 才出码；所有云服务请求强制公网 HTTPS、禁止重定向；回调字段限制与支付宝插件一致。
- Produces: `ProviderClient::__construct(?\Psr\Http\Client\ClientInterface $http = null)`，测试可注入 Guzzle `MockHandler`，生产默认客户端固定 `allow_redirects=false`。

- [ ] **Step 1: 写失败的插件安全测试**

```php
public function testRejectsHttpProviderAndMalformedFreshCallback(): void
{
    $driver = new Driver();
    $config = $this->validConfig();
    $config['monitor_base_url'] = 'http://public.example.com';
    self::assertSame(-1, $driver->upchannel(['status'=>0], $config)['code']);

    $params = $this->signedCallback(['source_bill_id'=>'x', 'occurred_at'=>(string)(time()+600)]);
    self::assertFalse($driver->notify($params, $this->validConfig())['success']);
}
```

增加 HTTP 客户端测试：通过 `MockHandler` 和 history middleware 注入 `ProviderClient`，断言 HTTP 请求不跟随 302、`accepted=false` 时 `pay()` 抛出异常，且 HTTPS 之外的 base URL 在发出请求前被拒绝。

- [ ] **Step 2: 运行插件测试并确认 HTTP 仍被接受**

Run: `php vendor/bin/phpunit tests/Unit/WxpayClerkAdapterPluginTest.php`

Expected: FAIL，`upchannel()` 接受 HTTP URL。

- [ ] **Step 3: 实现严格配置和回调形状**

复用 `alipay_scan_monitor` 的限制：订单号 4–128 位、金额 0.01–50000.00、订单有效期不超过一小时、`source_bill_id` 与 nonce 16–128 位、到账时间最多延迟七天且不得超过未来 300 秒、推送时间误差 300 秒。

```php
$registered = (new ProviderClient())->registerOrder($config, $tradeNo, $amount, $expiresAt);
if (($registered['accepted'] ?? false) !== true) {
    throw new RuntimeException('店员服务没有确认订单登记');
}
```

在 ProviderClient Guzzle 配置中加入 `'allow_redirects' => false`，并在请求前强制 `https`。

- [ ] **Step 4: 运行店员插件和服务契约测试**

Run: `php vendor/bin/phpunit tests/Unit/WxpayClerkAdapterPluginTest.php tests/Unit/WxpayClerkApiApplicationTest.php`

Expected: PASS。

- [ ] **Step 5: 提交插件强化**

```bash
git add plugins-src/wxpay-clerk-adapter tests/Unit/WxpayClerkAdapterPluginTest.php
git commit -m "fix: harden wxpay clerk adapter contract"
```

---

### Task 8: 用能力接口替代主站硬编码通道列表

**Files:**
- Create: `app/payment/Contracts/PaymentEventReviewInterface.php`
- Create: `app/payment/Contracts/OperationsStatusInterface.php`
- Modify: `plugins-src/wxpay-clerk-adapter/src/Driver.php`
- Modify: `plugins-src/wxpay-cloud-adapter/src/Driver.php`
- Modify: `plugins-src/alipay-scan-monitor/src/Driver.php`
- Modify: `app/controller/admin/CallbillAdminController.php`
- Modify: `app/controller/admin/CloudMonitorAdminController.php`
- Create: `tests/Unit/PaymentOperationsCapabilityTest.php`

**Interfaces:**
- Produces: `PaymentEventReviewInterface::reviewEvents()`、`matchReviewEvent()`、`ignoreReviewEvent()`。
- Produces: `OperationsStatusInterface::operationsStatus(array $config): array`。

- [ ] **Step 1: 写能力发现失败测试**

```php
public function testClerkDriverDeclaresReviewAndOperationsCapabilities(): void
{
    $driver = new \plugin\cxpay\wxpay_clerk_adapter\Driver();
    self::assertInstanceOf(PaymentEventReviewInterface::class, $driver);
    self::assertInstanceOf(OperationsStatusInterface::class, $driver);
}
```

增加控制器辅助查询测试：已启用且实现接口的 `wxpay_clerk_adapter` 被列出；未实现接口的普通二维码驱动被排除。

- [ ] **Step 2: 运行测试并确认店员驱动未实现接口**

Run: `php vendor/bin/phpunit tests/Unit/PaymentOperationsCapabilityTest.php`

Expected: FAIL，`PaymentEventReviewInterface` 不存在。

- [ ] **Step 3: 定义接口并按 `instanceof` 发现能力**

```php
interface PaymentEventReviewInterface
{
    public function reviewEvents(array $config): array;
    public function matchReviewEvent(array $config, int $eventId, string $tradeNo, string $operator, string $note): array;
    public function ignoreReviewEvent(array $config, int $eventId, string $operator, string $note): array;
}

interface OperationsStatusInterface
{
    public function operationsStatus(array $config): array;
}
```

控制器读取候选通道后使用 `PaymentManager::make($cType) instanceof ...` 过滤，不再使用固定 `whereIn('c_type', [...])`。

- [ ] **Step 4: 运行能力、插件和管理控制器相关测试**

Run: `php vendor/bin/phpunit tests/Unit/PaymentOperationsCapabilityTest.php tests/Unit/WxpayClerkAdapterPluginTest.php tests/Unit/WxpayCloudAdapterPluginTest.php tests/Unit/AlipayScanMonitorPluginTest.php`

Expected: PASS。

- [ ] **Step 5: 提交主站能力发现**

```bash
git add app/payment/Contracts/PaymentEventReviewInterface.php app/payment/Contracts/OperationsStatusInterface.php app/controller/admin/CallbillAdminController.php app/controller/admin/CloudMonitorAdminController.php plugins-src/wxpay-clerk-adapter/src/Driver.php plugins-src/wxpay-cloud-adapter/src/Driver.php plugins-src/alipay-scan-monitor/src/Driver.php tests/Unit/PaymentOperationsCapabilityTest.php
git commit -m "refactor: discover payment operations capabilities"
```

---

### Task 9: 完成端到端恢复测试、部署和风险文档

**Files:**
- Create: `tests/Integration/WxpayClerkLifecycleTest.php`
- Modify: `services/wxpay-clerk-service/config.example.php`
- Modify: `services/wxpay-clerk-service/docker-compose.example.yml`
- Modify: `services/wxpay-clerk-service/README.md`
- Modify: `plugins-src/wxpay-clerk-adapter/README.md`
- Modify: `plugins-src/wxpay-clerk-adapter/manifest.json`
- Modify: `.github/workflows/ci.yml`
- Delete: `services/wxpay-clerk-service/src/OrderStore.php`

**Interfaces:**
- Consumes: 完整插件、服务和主站能力接口。
- Produces: 从订单登记到回调恢复的自动化验收，以及独立 outbox Worker 部署说明。

- [ ] **Step 1: 写端到端失败恢复测试**

```php
public function testRegisteredOrderSurvivesDuplicateWebhookAndCallbackOutage(): void
{
    $registered = $this->api->handleSigned('POST', '/v1/orders', [
        'account_id'=>'acc_e2e','out_trade_no'=>'CX-E2E-1001','amount'=>'6.66',
        'expires_at'=>1700000600,'mode'=>'clerk',
    ], 1700000000);
    self::assertTrue($registered['accepted']);

    for ($i = 0; $i < 10; $i++) {
        $this->api->handleWebhook($this->validWebhook('stable_bill_e2e'), $this->webhookToken, '127.0.0.1', 1700000100);
    }
    self::assertSame(1, $this->countRows('payment_events'));
    self::assertSame(1, $this->countRows('callback_outbox'));

    $this->transport->queue(503, 'down');
    $this->transport->queue(200, 'success');
    $this->dispatcher->dispatchOne(1700000110);
    $this->dispatcher->dispatchOne($this->nextAttemptAt());

    self::assertSame('SENT', $this->outboxStatus());
    self::assertTrue($this->api->queryOrder('CX-E2E-1001')['paid']);
}
```

`handleSigned()`、`handleWebhook()` 和 `queryOrder()` 是 `WxpayClerkLifecycleTest` 内的测试辅助方法：它们分别生成真实 HMAC 请求头并调用 `ApiApplication::handle()`、构造真实 webhook 路径和 JSON 请求、解析订单查询 `HttpResponse`；生产类不增加测试专用 API。

- [ ] **Step 2: 运行端到端测试并确认旧同步路径或旧 `OrderStore` 仍造成失败**

Run: `php vendor/bin/phpunit tests/Integration/WxpayClerkLifecycleTest.php`

Expected: FAIL，直到入口和全部依赖只使用新仓储及发件箱。

- [ ] **Step 3: 删除旧写路径并更新部署配置**

`config.example.php` 新增并校验：

```php
'webhook_token' => getenv('WXCLERK_WEBHOOK_TOKEN') ?: '',
'client_secret' => getenv('WXCLERK_CLIENT_SECRET') ?: '',
'callback_secret' => getenv('WXCLERK_CALLBACK_SECRET') ?: '',
'outbox_max_attempts' => 12,
'outbox_lease_seconds' => 60,
```

Docker Compose 增加独立 `outbox-worker` 服务，执行 `php bin/dispatch-outbox.php`，与 Web 服务挂载同一 SQLite 数据卷。README 明确 Gewe/iPad 协议依赖、HTTPS、IP 白名单、路径令牌、备份、灰度和失败队列检查方法。

CI 语法扫描增加 `plugins-src` 和 `services/wxpay-clerk-service`；PHPUnit 自动包含新单元及集成测试。

- [ ] **Step 4: 运行完整验证**

Run:

```powershell
composer validate --strict
php vendor/bin/phpunit
Get-ChildItem services/wxpay-clerk-service -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName; if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE } }
git diff --check
```

Expected: Composer 有效；全量测试通过；所有店员服务 PHP 文件语法通过；无空白错误。

- [ ] **Step 5: 提交端到端验收和文档**

```bash
git add tests/Integration/WxpayClerkLifecycleTest.php services/wxpay-clerk-service plugins-src/wxpay-clerk-adapter .github/workflows/ci.yml
git commit -m "test: verify wxpay clerk recovery lifecycle"
```

---

## Final Verification

- [ ] 确认 `git status --short` 只包含计划内文件。
- [ ] 确认每个生产行为都有曾经失败的测试记录。
- [ ] 确认主工作区 `getAccountByGeweAppId()` 优化已经由 `AccountRepository` 和索引覆盖。
- [ ] 确认四个插件现有测试无回归。
- [ ] 确认店员服务不再包含同步回调丢弃路径。
- [ ] 确认 README、manifest、运行时接口和主站展示一致。
