# 云端控制面 M1A 身份核心实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 建立可独立安装和测试的云端控制面后端骨架，完成邮箱验证注册、官方 QQ/微信 OAuth 身份绑定、账号激活和 TOTP 身份核心。

**Architecture:** 在 `services/cloud-control-plane/` 创建独立 Webman 2 子项目，使用模块化应用服务、仓储接口、PDO MySQL 实现和 Redis 短期状态存储。M1A 不签发正式 Portal/Ops Session，而是输出一次性 `IdentityCompletion`，由 M1B 的会话服务消费；这样身份事务不依赖尚未实现的会话层。

**Tech Stack:** PHP 8.2、Webman Framework 2.2、PDO MySQL 8、Redis 7、Libsodium、Guzzle 7、PHPMailer 7、Ramsey UUID 4、PHPUnit 10.5。

## Global Constraints

- 必须遵守 `docs/superpowers/specs/2026-08-09-cloud-control-plane-m1-identity-tenant-design.md`。
- 云端子项目不得加载 CXPAY 根项目的 Composer、配置、数据库、Redis、Session 或密钥。
- 生产命名空间固定为 `CloudControl\`；测试假实现只能位于 `CloudControl\Tests\`。
- 邮箱必须验证，且至少绑定一个 QQ 或微信后用户才能变为 `ACTIVE`。
- QQ/微信登录不能自动注册；未配置官方凭据必须返回稳定错误，不得模拟成功。
- 密码使用 Argon2id；TOTP 密钥使用 XChaCha20-Poly1305；所有时间使用 UTC。
- Controller 不开启事务；应用服务负责事务，仓储负责持久化。
- 单个业务文件原则上不超过 300 行。
- 每个任务遵循红—绿—重构循环并形成独立提交。
- `CXPAY.rar` 不得加入任何提交。

## M1 分片说明

- 本计划 M1A：服务骨架、共享安全原语、M1 数据库骨架、邮箱注册、OAuth 绑定、账号激活、TOTP。
- 后续 M1B：Portal/Ops 会话、租户上下文、RBAC、邀请、代理关系、审计和 HTTP API。
- 后续 M1C：Vue Portal/Ops、Docker 生产编排、CI 和端到端验收。

M1B 只能依赖本计划明确产出的接口，不能直接访问 M1A 仓储内部实现。

---

### Task 1: 独立 Webman 服务骨架与运行边界

**Files:**
- Create: `services/cloud-control-plane/composer.json`
- Create: `services/cloud-control-plane/start.php`
- Copy unchanged: `support/bootstrap.php` -> `services/cloud-control-plane/support/bootstrap.php`
- Create: `services/cloud-control-plane/config/app.php`
- Create: `services/cloud-control-plane/config/autoload.php`
- Create: `services/cloud-control-plane/config/bootstrap.php`
- Create: `services/cloud-control-plane/config/container.php`
- Create: `services/cloud-control-plane/config/exception.php`
- Create: `services/cloud-control-plane/config/log.php`
- Create: `services/cloud-control-plane/config/middleware.php`
- Create: `services/cloud-control-plane/config/process.php`
- Create: `services/cloud-control-plane/config/route.php`
- Create: `services/cloud-control-plane/config/server.php`
- Create: `services/cloud-control-plane/config/static.php`
- Create: `services/cloud-control-plane/app/Shared/Http/HealthController.php`
- Create: `services/cloud-control-plane/tests/Unit/ServiceIsolationTest.php`
- Create: `services/cloud-control-plane/tests/Unit/HealthControllerTest.php`
- Create: `services/cloud-control-plane/phpunit.xml`
- Create: `services/cloud-control-plane/.env.example`
- Modify: `.gitignore`

**Interfaces:**
- Produces: 独立 `services/cloud-control-plane/vendor/autoload.php`
- Produces: `GET /health -> {code:1,data:{status:"ok",service:"cloud-control-plane"}}`
- Guarantees: 生产 Composer 只自动加载 `CloudControl\ => app/`

- [ ] **Step 1: 编写服务隔离失败测试**

```php
<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ServiceIsolationTest extends TestCase
{
    public function testServiceOwnsItsRuntimeAndNeverLoadsCxpayApplication(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode(
            (string)file_get_contents($root . '/composer.json'),
            true,
            32,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(['CloudControl\\' => 'app/'], $composer['autoload']['psr-4']);
        self::assertFileExists($root . '/start.php');
        self::assertFileExists($root . '/support/bootstrap.php');
        self::assertStringNotContainsString('../../../vendor', (string)file_get_contents($root . '/start.php'));
        self::assertStringNotContainsString('app\\', json_encode($composer, JSON_THROW_ON_ERROR));
    }
}
```

- [ ] **Step 2: 运行测试并确认因子项目不存在而失败**

Run from repository root:

```powershell
Test-Path services/cloud-control-plane/composer.json
```

Expected: 输出 `False`。此时不能借用根目录 PHPUnit 把测试伪装成通过。

- [ ] **Step 3: 创建独立 Composer 和启动入口**

`composer.json` 必须包含：

```json
{
  "name": "cxpay/cloud-control-plane",
  "type": "project",
  "license": "proprietary",
  "require": {
    "php": ">=8.2",
    "ext-json": "*",
    "ext-intl": "*",
    "ext-mbstring": "*",
    "ext-openssl": "*",
    "ext-pdo": "*",
    "ext-pdo_mysql": "*",
    "ext-redis": "*",
    "ext-sodium": "*",
    "guzzlehttp/guzzle": "^7.10",
    "monolog/monolog": "^3.0",
    "phpmailer/phpmailer": "^7.1",
    "ramsey/uuid": "^4.9",
    "vlucas/phpdotenv": "^5.6",
    "workerman/webman-framework": "^2.2"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.5"
  },
  "autoload": {
    "psr-4": {
      "CloudControl\\": "app/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "CloudControl\\Tests\\": "tests/"
    }
  },
  "config": {
    "optimize-autoloader": true,
    "sort-packages": true
  }
}
```

`start.php`：

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(__DIR__);
require __DIR__ . '/vendor/autoload.php';
support\App::run();
```

`config/app.php` 的环境变量只能使用 `CLOUD_` 前缀：

```php
<?php

declare(strict_types=1);

return [
    'version' => '0.1.0-m1a',
    'debug' => filter_var(env('CLOUD_APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    'url' => rtrim((string)env('CLOUD_API_URL', 'http://127.0.0.1:8890'), '/'),
    'default_timezone' => 'UTC',
    'public_path' => base_path() . '/public',
    'runtime_path' => base_path() . '/runtime',
];
```

- [ ] **Step 4: 创建最小 Webman 配置与健康控制器**

`HealthController`：

```php
<?php

declare(strict_types=1);

namespace CloudControl\Shared\Http;

use support\Response;

final class HealthController
{
    public function __invoke(): Response
    {
        return json([
            'code' => 1,
            'data' => [
                'status' => 'ok',
                'service' => 'cloud-control-plane',
            ],
        ]);
    }
}
```

`config/route.php`：

```php
<?php

declare(strict_types=1);

use CloudControl\Shared\Http\HealthController;
use Webman\Route;

Route::disableDefaultRoute();
Route::get('/health', [HealthController::class, '__invoke']);
```

其余最小配置固定为：

```php
// config/autoload.php
return ['files' => []];

// config/bootstrap.php
return [];

// config/container.php
return new Webman\Container();

// config/exception.php
return ['' => support\exception\Handler::class];

// config/middleware.php
return ['' => []];

// config/process.php
return [];

// config/static.php
return ['enable' => false, 'middleware' => []];
```

`config/server.php` 从环境变量 `CLOUD_HOST`、`CLOUD_PORT`、`CLOUD_WEBMAN_WORKERS` 读取监听参数，默认 `127.0.0.1:8890`、4 个 Worker，服务名为 `CXPAY Cloud Control Plane`，PID/status/stdout/log 文件全部位于子项目 `runtime/`。`config/log.php` 使用 `RotatingFileHandler` 写 `runtime/logs/cloud-control-plane.log`，保留 14 天、默认 INFO。

`support/bootstrap.php` 必须从当前仓库根 `support/bootstrap.php` 原样复制；复制后测试扫描其中不得出现 CXPAY 的 `app\\`、数据库或支付模块类名。

`phpunit.xml` 必须使用子项目自己的 `vendor/autoload.php`，并把测试套件分成 Unit、Integration、Contract。

`.env.example` 在 M1A 固定提供以下非真实示例值，密钥字段留空：

```dotenv
CLOUD_APP_ENV=development
CLOUD_APP_DEBUG=false
CLOUD_API_URL=http://127.0.0.1:8890
CLOUD_HOST=127.0.0.1
CLOUD_PORT=8890
CLOUD_WEBMAN_WORKERS=4
CLOUD_DB_HOST=127.0.0.1
CLOUD_DB_PORT=3306
CLOUD_DB_DATABASE=cxpay_cloud
CLOUD_DB_USERNAME=cxpay_cloud
CLOUD_DB_PASSWORD=
CLOUD_REDIS_HOST=127.0.0.1
CLOUD_REDIS_PORT=6379
CLOUD_REDIS_PASSWORD=
CLOUD_REDIS_DB=0
CLOUD_EMAIL_CODE_PEPPER=
CLOUD_OAUTH_STATE_HMAC_KEY=
CLOUD_TOTP_ENCRYPTION_KEY=
CLOUD_SMTP_HOST=
CLOUD_SMTP_PORT=465
CLOUD_SMTP_USERNAME=
CLOUD_SMTP_PASSWORD=
CLOUD_SMTP_FROM=no-reply@cxpay.com
CLOUD_QQ_CLIENT_ID=
CLOUD_QQ_CLIENT_SECRET=
CLOUD_QQ_PORTAL_REDIRECT_URI=https://cloud.cxpay.com/api/public/v1/oauth/portal/qq/callback
CLOUD_QQ_OPS_REDIRECT_URI=https://ops.cloud.cxpay.com/api/public/v1/oauth/ops/qq/callback
CLOUD_WECHAT_APP_ID=
CLOUD_WECHAT_APP_SECRET=
CLOUD_WECHAT_PORTAL_REDIRECT_URI=https://cloud.cxpay.com/api/public/v1/oauth/portal/wechat/callback
CLOUD_WECHAT_OPS_REDIRECT_URI=https://ops.cloud.cxpay.com/api/public/v1/oauth/ops/wechat/callback
```

- [ ] **Step 5: 增加子项目依赖忽略规则并安装依赖**

在根 `.gitignore` 增加：

```gitignore
/services/cloud-control-plane/vendor/
/services/cloud-control-plane/node_modules/
/services/cloud-control-plane/runtime/
/services/cloud-control-plane/frontend/*/dist/
```

Run:

```powershell
Push-Location services/cloud-control-plane
composer install
Pop-Location
```

Expected: 生成子项目 `composer.lock` 和被忽略的 `vendor/`，不修改根目录 `composer.lock`。

- [ ] **Step 6: 运行骨架测试与独立性检查**

Run:

```powershell
Push-Location services/cloud-control-plane
php vendor/bin/phpunit tests/Unit/ServiceIsolationTest.php tests/Unit/HealthControllerTest.php
composer validate --strict
Pop-Location
git status --short
```

Expected: 2 tests PASS；Composer valid；根 `composer.lock` 未变化；`CXPAY.rar` 仍未跟踪。

- [ ] **Step 7: 提交服务骨架**

```powershell
git add .gitignore services/cloud-control-plane
git commit -m "feat: scaffold isolated cloud control service"
```

---

### Task 2: 共享安全原语和稳定错误契约

**Files:**
- Create: `services/cloud-control-plane/app/Shared/Clock/Clock.php`
- Create: `services/cloud-control-plane/app/Shared/Clock/SystemClock.php`
- Create: `services/cloud-control-plane/tests/Support/FrozenClock.php`
- Create: `services/cloud-control-plane/app/Shared/Id/IdGenerator.php`
- Create: `services/cloud-control-plane/app/Shared/Id/UuidV7Generator.php`
- Create: `services/cloud-control-plane/app/Shared/Error/ErrorCode.php`
- Create: `services/cloud-control-plane/app/Shared/Error/CloudException.php`
- Create: `services/cloud-control-plane/app/Shared/Http/ApiErrorResponder.php`
- Create: `services/cloud-control-plane/app/Shared/Security/SecretCipher.php`
- Create: `services/cloud-control-plane/app/Shared/Security/SodiumSecretCipher.php`
- Create: `services/cloud-control-plane/app/Shared/Security/EncryptedSecret.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/EmailAddress.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/PasswordPolicy.php`
- Create: `services/cloud-control-plane/tests/Unit/Shared/SodiumSecretCipherTest.php`
- Create: `services/cloud-control-plane/tests/Unit/Identity/EmailAddressTest.php`
- Create: `services/cloud-control-plane/tests/Unit/Identity/PasswordPolicyTest.php`
- Create: `services/cloud-control-plane/tests/Unit/Shared/ApiErrorResponderTest.php`

**Interfaces:**
- Produces: `Clock::now(): DateTimeImmutable`
- Produces: `IdGenerator::new(): string`
- Produces: `SecretCipher::encrypt(string): EncryptedSecret`
- Produces: `EmailAddress::canonical(): string`
- Produces: `PasswordPolicy::hash(string): string`
- Produces: `CloudException(errorCode, safeMessage, httpStatus, retryable, data)`

- [ ] **Step 1: 编写邮箱、密码和加密失败测试**

```php
public function testEmailCanonicalizesWholeAddressAndIdnaDomain(): void
{
    $email = EmailAddress::fromString('  User@例子.测试  ');
    self::assertSame('user@xn--fsqu00a.xn--0zwm56d', $email->canonical());
    self::assertSame('User@例子.测试', $email->display());
}

public function testPasswordPolicyUsesArgon2idAndRejectsShortPassword(): void
{
    $policy = new PasswordPolicy();
    $hash = $policy->hash('Correct-Horse-2026!');
    self::assertTrue(password_verify('Correct-Horse-2026!', $hash));
    self::assertSame(PASSWORD_ARGON2ID, password_get_info($hash)['algo']);

    $this->expectException(CloudException::class);
    $policy->hash('short');
}

public function testCipherRoundTripsAndUsesFreshNonce(): void
{
    $cipher = new SodiumSecretCipher(str_repeat('k', 32));
    $first = $cipher->encrypt('JBSWY3DPEHPK3PXP');
    $second = $cipher->encrypt('JBSWY3DPEHPK3PXP');

    self::assertNotSame($first->nonce, $second->nonce);
    self::assertSame('JBSWY3DPEHPK3PXP', $cipher->decrypt($first));
}
```

- [ ] **Step 2: 运行测试并确认类不存在**

Run:

```powershell
Push-Location services/cloud-control-plane
php vendor/bin/phpunit tests/Unit/Identity tests/Unit/Shared
Pop-Location
```

Expected: FAIL，首个错误为 `Class EmailAddress not found` 或同类缺失错误。

- [ ] **Step 3: 实现值对象与安全接口**

`SecretCipher` 固定接口：

```php
interface SecretCipher
{
    public function encrypt(string $plaintext): EncryptedSecret;
    public function decrypt(EncryptedSecret $secret): string;
}
```

`SodiumSecretCipher` 必须使用：

```php
$nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
    $plaintext,
    'cxpay-cloud-totp-v1',
    $nonce,
    $this->key
);
```

解密失败统一抛出 `CloudException(ErrorCode::INTERNAL_ERROR, '安全数据无法解密', 500, false)`，不得返回底层 Sodium 错误。

`CLOUD_TOTP_ENCRYPTION_KEY`、`CLOUD_EMAIL_CODE_PEPPER` 和 `CLOUD_OAUTH_STATE_HMAC_KEY` 都使用无填充 Base64URL 注入；配置加载时解码后必须恰好 32 字节，否则启动失败。测试中可以直接构造 32 字节固定值，生产代码不得内置默认密钥。

- [ ] **Step 4: 固定错误码枚举与响应格式**

`ErrorCode` 必须包含 M1A 使用的：

```php
enum ErrorCode: string
{
    case EMAIL_CODE_INVALID = 'CLOUD_EMAIL_CODE_INVALID';
    case EMAIL_CODE_EXPIRED = 'CLOUD_EMAIL_CODE_EXPIRED';
    case EMAIL_DELIVERY_FAILED = 'CLOUD_EMAIL_DELIVERY_FAILED';
    case REGISTRATION_INCOMPLETE = 'CLOUD_REGISTRATION_INCOMPLETE';
    case IDENTITY_NOT_BOUND = 'CLOUD_IDENTITY_NOT_BOUND';
    case IDENTITY_ALREADY_BOUND = 'CLOUD_IDENTITY_ALREADY_BOUND';
    case LAST_IDENTITY_REQUIRED = 'CLOUD_LAST_IDENTITY_REQUIRED';
    case CREDENTIALS_INVALID = 'CLOUD_CREDENTIALS_INVALID';
    case TOTP_REQUIRED = 'CLOUD_TOTP_REQUIRED';
    case TOTP_SETUP_REQUIRED = 'CLOUD_TOTP_SETUP_REQUIRED';
    case TOTP_INVALID = 'CLOUD_TOTP_INVALID';
    case OAUTH_NOT_CONFIGURED = 'CLOUD_OAUTH_NOT_CONFIGURED';
    case OAUTH_STATE_INVALID = 'CLOUD_OAUTH_STATE_INVALID';
    case RATE_LIMITED = 'CLOUD_RATE_LIMITED';
    case INTERNAL_ERROR = 'CLOUD_INTERNAL_ERROR';
}
```

`ApiErrorResponder` 输出 `code`、`error_code`、`msg`、`request_id`、`retryable` 和 `data`，测试必须确认异常对象的 trace 不进入 JSON。

- [ ] **Step 5: 运行共享单元测试**

Run:

```powershell
Push-Location services/cloud-control-plane
php vendor/bin/phpunit tests/Unit/Identity tests/Unit/Shared
Pop-Location
```

Expected: PASS，且无 warning/risky。

- [ ] **Step 6: 提交安全原语**

```powershell
git add services/cloud-control-plane/app/Shared services/cloud-control-plane/app/Identity/Domain services/cloud-control-plane/tests/Unit
git commit -m "feat: add cloud identity security primitives"
```

---

### Task 3: 版本化迁移器与 M1 数据库骨架

**Files:**
- Create: `services/cloud-control-plane/app/Shared/Database/ConnectionFactory.php`
- Create: `services/cloud-control-plane/app/Shared/Database/TransactionManager.php`
- Create: `services/cloud-control-plane/app/Shared/Database/PdoTransactionManager.php`
- Create: `services/cloud-control-plane/app/Shared/Database/MigrationRunner.php`
- Create: `services/cloud-control-plane/app/Shared/Database/MigrationReport.php`
- Create: `services/cloud-control-plane/bin/migrate.php`
- Create: `services/cloud-control-plane/migrations/001_m1_identity_tenant.sql`
- Create: `services/cloud-control-plane/docker-compose.test.yml`
- Create: `services/cloud-control-plane/tests/Integration/MigrationRunnerTest.php`
- Create: `services/cloud-control-plane/tests/Integration/SchemaConstraintTest.php`
- Create: `services/cloud-control-plane/tests/Support/MySqlTestCase.php`

**Interfaces:**
- Produces: `TransactionManager::run(callable $callback): mixed`
- Produces: `MigrationRunner::migrate(string $directory): MigrationReport`
- Produces tables: 设计第 8 节全部 `cloud_*` 表

- [ ] **Step 1: 编写迁移幂等和校验和失败测试**

```php
public function testMigrationRunsOnceAndRejectsChangedChecksum(): void
{
    $directory = $this->temporaryMigrationDirectory([
        '001_create_probe.sql' => 'CREATE TABLE cloud_probe (id CHAR(36) PRIMARY KEY)',
    ]);
    $runner = new MigrationRunner($this->pdo());

    self::assertSame(['001_create_probe.sql'], $runner->migrate($directory)->applied);
    self::assertSame([], $runner->migrate($directory)->applied);

    file_put_contents($directory . '/001_create_probe.sql', 'CREATE TABLE changed_probe (id INT)');
    $this->expectExceptionMessage('已执行迁移的校验和发生变化');
    $runner->migrate($directory);
}
```

- [ ] **Step 2: 启动测试 MySQL 并确认迁移类不存在**

`docker-compose.test.yml` 固定为只用于本地/CI 的隔离依赖：

```yaml
services:
  cloud-test-mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: cxpay_cloud_test
      MYSQL_USER: cxpay_test
      MYSQL_PASSWORD: cxpay_test_password
      MYSQL_ROOT_PASSWORD: cxpay_test_root_password
    ports:
      - "127.0.0.1:13316:3306"
    healthcheck:
      test: ["CMD-SHELL", "mysqladmin ping -h 127.0.0.1 -uroot -pcxpay_test_root_password --silent"]
      interval: 2s
      timeout: 3s
      retries: 30

  cloud-test-redis:
    image: redis:7-alpine
    ports:
      - "127.0.0.1:16379:6379"
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 2s
      timeout: 3s
      retries: 30
```

Run:

```powershell
Push-Location services/cloud-control-plane
docker compose -f docker-compose.test.yml up -d cloud-test-mysql
$env:CLOUD_TEST_DB_DSN='mysql:host=127.0.0.1;port=13316;dbname=cxpay_cloud_test;charset=utf8mb4'
$env:CLOUD_TEST_DB_USERNAME='cxpay_test'
$env:CLOUD_TEST_DB_PASSWORD='cxpay_test_password'
php vendor/bin/phpunit tests/Integration/MigrationRunnerTest.php
Pop-Location
```

Expected: FAIL，`MigrationRunner` 尚不存在；MySQL healthcheck 必须成功。

- [ ] **Step 3: 实现迁移器**

迁移器算法固定为：

1. `SELECT GET_LOCK('cxpay_cloud_schema_migration', 10)`；
2. 创建 `cloud_schema_migrations(version VARCHAR(191) PRIMARY KEY, checksum CHAR(64), executed_at DATETIME(6))`；
3. 按文件名字典序读取 `*.sql`；
4. 对已执行文件比较 SHA-256，变化则拒绝；
5. 未执行文件在事务中执行并写版本；
6. `finally` 调用 `RELEASE_LOCK`。

任何失败都保留原迁移文件名，但对外消息不包含 DSN、用户名或 SQL 内容。

- [ ] **Step 4: 创建 M1 表和约束**

`001_m1_identity_tenant.sql` 必须创建：

```sql
CREATE TABLE cloud_users (
  id CHAR(36) PRIMARY KEY,
  email VARCHAR(320) NOT NULL,
  email_canonical VARCHAR(320) NOT NULL,
  display_name VARCHAR(100) NULL,
  password_hash VARCHAR(255) NULL,
  status VARCHAR(32) NOT NULL,
  email_verified_at DATETIME(6) NULL,
  totp_secret_ciphertext VARBINARY(512) NULL,
  totp_secret_nonce VARBINARY(32) NULL,
  totp_enabled_at DATETIME(6) NULL,
  failed_login_count INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME(6) NULL,
  last_login_at DATETIME(6) NULL,
  last_login_ip VARCHAR(45) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  UNIQUE KEY uq_cloud_users_email (email_canonical)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cloud_user_identities (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  provider VARCHAR(16) NOT NULL,
  issuer VARCHAR(191) NOT NULL,
  subject VARCHAR(191) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  avatar_url VARCHAR(2048) NULL,
  bound_at DATETIME(6) NOT NULL,
  last_login_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_identity_user FOREIGN KEY (user_id) REFERENCES cloud_users(id),
  UNIQUE KEY uq_external_identity (provider, issuer, subject),
  UNIQUE KEY uq_user_provider (user_id, provider, issuer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

同一迁移继续使用以下明确结构：

```sql
CREATE TABLE cloud_email_verifications (
  id CHAR(36) PRIMARY KEY,
  email_canonical VARCHAR(320) NOT NULL,
  purpose VARCHAR(32) NOT NULL,
  delivery_status VARCHAR(32) NOT NULL,
  code_digest CHAR(64) NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME(6) NOT NULL,
  consumed_at DATETIME(6) NULL,
  requested_ip VARCHAR(45) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  KEY idx_email_verification_lookup (email_canonical, purpose, delivery_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cloud_tenants (
  id CHAR(36) PRIMARY KEY,
  type VARCHAR(16) NOT NULL,
  name VARCHAR(150) NOT NULL,
  status VARCHAR(16) NOT NULL,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_tenant_creator FOREIGN KEY (created_by_user_id) REFERENCES cloud_users(id),
  KEY idx_tenant_type_status (type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cloud_tenant_members (
  id CHAR(36) PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  role VARCHAR(32) NOT NULL,
  status VARCHAR(16) NOT NULL,
  joined_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_member_tenant FOREIGN KEY (tenant_id) REFERENCES cloud_tenants(id),
  CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES cloud_users(id),
  UNIQUE KEY uq_tenant_member (tenant_id, user_id),
  KEY idx_member_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cloud_tenant_relations (
  id CHAR(36) PRIMARY KEY,
  agent_tenant_id CHAR(36) NOT NULL,
  customer_tenant_id CHAR(36) NOT NULL,
  status VARCHAR(16) NOT NULL,
  effective_from DATETIME(6) NOT NULL,
  effective_until DATETIME(6) NULL,
  created_by_user_id CHAR(36) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  active_customer_tenant_id CHAR(36)
    GENERATED ALWAYS AS (CASE WHEN status = 'ACTIVE' THEN customer_tenant_id ELSE NULL END) STORED,
  CONSTRAINT fk_relation_agent FOREIGN KEY (agent_tenant_id) REFERENCES cloud_tenants(id),
  CONSTRAINT fk_relation_customer FOREIGN KEY (customer_tenant_id) REFERENCES cloud_tenants(id),
  CONSTRAINT fk_relation_creator FOREIGN KEY (created_by_user_id) REFERENCES cloud_users(id),
  UNIQUE KEY uq_active_customer_agent (active_customer_tenant_id),
  KEY idx_relation_agent_status (agent_tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cloud_agent_profiles (
  tenant_id CHAR(36) PRIMARY KEY,
  status VARCHAR(16) NOT NULL,
  level_code VARCHAR(32) NULL,
  credit_status VARCHAR(16) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_agent_profile_tenant FOREIGN KEY (tenant_id) REFERENCES cloud_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cloud_sessions (
  id CHAR(36) PRIMARY KEY,
  token_digest CHAR(64) NOT NULL,
  user_id CHAR(36) NOT NULL,
  audience VARCHAR(16) NOT NULL,
  current_tenant_id CHAR(36) NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent VARCHAR(512) NOT NULL,
  last_activity_at DATETIME(6) NOT NULL,
  idle_expires_at DATETIME(6) NOT NULL,
  absolute_expires_at DATETIME(6) NOT NULL,
  revoked_at DATETIME(6) NULL,
  revoke_reason VARCHAR(100) NULL,
  created_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES cloud_users(id),
  CONSTRAINT fk_session_tenant FOREIGN KEY (current_tenant_id) REFERENCES cloud_tenants(id),
  UNIQUE KEY uq_session_token_digest (token_digest),
  KEY idx_session_user_active (user_id, revoked_at, absolute_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cloud_invitations (
  id CHAR(36) PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  email_canonical VARCHAR(320) NOT NULL,
  role VARCHAR(32) NOT NULL,
  token_digest CHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  accepted_at DATETIME(6) NULL,
  invited_by_user_id CHAR(36) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_invitation_tenant FOREIGN KEY (tenant_id) REFERENCES cloud_tenants(id),
  CONSTRAINT fk_invitation_actor FOREIGN KEY (invited_by_user_id) REFERENCES cloud_users(id),
  UNIQUE KEY uq_invitation_token_digest (token_digest),
  KEY idx_invitation_email_status (email_canonical, status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cloud_audit_events (
  id CHAR(36) PRIMARY KEY,
  occurred_at DATETIME(6) NOT NULL,
  actor_user_id CHAR(36) NULL,
  actor_tenant_id CHAR(36) NULL,
  event_type VARCHAR(64) NOT NULL,
  target_type VARCHAR(64) NOT NULL,
  target_id CHAR(36) NULL,
  reason VARCHAR(500) NULL,
  request_id VARCHAR(64) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  metadata_json JSON NOT NULL,
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES cloud_users(id),
  CONSTRAINT fk_audit_tenant FOREIGN KEY (actor_tenant_id) REFERENCES cloud_tenants(id),
  KEY idx_audit_time (occurred_at),
  KEY idx_audit_actor_time (actor_user_id, occurred_at),
  KEY idx_audit_target (target_type, target_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

迁移专用 PDO 连接允许执行仓库内受信任的多语句 SQL；应用连接必须禁用 `PDO::MYSQL_ATTR_MULTI_STATEMENTS`。领域服务另外验证租户类型与成员角色的兼容性，因为 MySQL CHECK 不能跨表判断。

- [ ] **Step 5: 编写并通过数据库约束测试**

`SchemaConstraintTest` 必须验证：

```php
public function testEmailAndExternalIdentityAreDatabaseUnique(): void
{
    $this->insertUser('u1', 'user@example.com');
    $this->expectDuplicateKey(fn() => $this->insertUser('u2', 'user@example.com'));

    $this->insertIdentity('i1', 'u1', 'QQ', 'qq-client', 'openid-1');
    $this->insertUser('u3', 'other@example.com');
    $this->expectDuplicateKey(
        fn() => $this->insertIdentity('i2', 'u3', 'QQ', 'qq-client', 'openid-1')
    );
}
```

Run:

```powershell
Push-Location services/cloud-control-plane
php vendor/bin/phpunit tests/Integration/MigrationRunnerTest.php tests/Integration/SchemaConstraintTest.php
Pop-Location
```

Expected: PASS。

- [ ] **Step 6: 提交迁移骨架**

```powershell
git add services/cloud-control-plane/app/Shared/Database services/cloud-control-plane/bin services/cloud-control-plane/migrations services/cloud-control-plane/docker-compose.test.yml services/cloud-control-plane/tests/Integration services/cloud-control-plane/tests/Support
git commit -m "feat: add versioned cloud identity schema"
```

---

### Task 4: 邮件验证码与强制邮箱注册

**Files:**
- Create: `services/cloud-control-plane/app/Identity/Application/RequestEmailCode.php`
- Create: `services/cloud-control-plane/app/Identity/Application/RequestEmailCodeCommand.php`
- Create: `services/cloud-control-plane/app/Identity/Application/CompleteEmailRegistration.php`
- Create: `services/cloud-control-plane/app/Identity/Application/CompleteEmailRegistrationCommand.php`
- Create: `services/cloud-control-plane/app/Identity/Application/RegistrationChallenge.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/User.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/UserStatus.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/EmailVerification.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/EmailVerificationPurpose.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/EmailDeliveryStatus.php`
- Create: `services/cloud-control-plane/app/Identity/Port/UserRepository.php`
- Create: `services/cloud-control-plane/app/Identity/Port/EmailVerificationRepository.php`
- Create: `services/cloud-control-plane/app/Identity/Port/EmailSender.php`
- Create: `services/cloud-control-plane/app/Identity/Port/RateLimiter.php`
- Create: `services/cloud-control-plane/app/Identity/Port/RegistrationChallengeStore.php`
- Create: `services/cloud-control-plane/app/Identity/Infrastructure/PdoUserRepository.php`
- Create: `services/cloud-control-plane/app/Identity/Infrastructure/PdoEmailVerificationRepository.php`
- Create: `services/cloud-control-plane/app/Identity/Infrastructure/SmtpEmailSender.php`
- Create: `services/cloud-control-plane/app/Identity/Infrastructure/RedisRateLimiter.php`
- Create: `services/cloud-control-plane/app/Identity/Infrastructure/RedisRegistrationChallengeStore.php`
- Create: `services/cloud-control-plane/tests/Unit/Identity/RequestEmailCodeTest.php`
- Create: `services/cloud-control-plane/tests/Unit/Identity/CompleteEmailRegistrationTest.php`
- Create: `services/cloud-control-plane/tests/Integration/EmailRegistrationPersistenceTest.php`
- Create: `services/cloud-control-plane/tests/Integration/RedisRateLimiterTest.php`
- Create: `services/cloud-control-plane/tests/Fakes/FakeEmailSender.php`
- Create: `services/cloud-control-plane/tests/Fakes/InMemoryUserRepository.php`
- Create: `services/cloud-control-plane/tests/Fakes/InMemoryEmailVerificationRepository.php`
- Create: `services/cloud-control-plane/tests/Fakes/InMemoryRateLimiter.php`

**Interfaces:**
- Produces: `RequestEmailCode::handle(RequestEmailCodeCommand): void`
- Produces: `CompleteEmailRegistration::handle(CompleteEmailRegistrationCommand): RegistrationChallenge`
- Guarantees: 返回挑战的用户状态只能是 `PENDING_IDENTITY`

- [ ] **Step 1: 编写投递状态和注册状态失败测试**

```php
public function testUserCannotContinueUntilEmailDeliveryAndVerificationSucceed(): void
{
    $sender = new FakeEmailSender();
    $request = $this->requestEmailCode(sender: $sender);
    $request->handle(new RequestEmailCodeCommand('User@Example.com', '127.0.0.1'));

    $code = $sender->lastCodeFor('user@example.com');
    $challenge = $this->completeRegistration()->handle(
        new CompleteEmailRegistrationCommand(
            'user@example.com',
            $code,
            '客户用户',
            'Correct-Horse-2026!',
            '127.0.0.1'
        )
    );

    self::assertSame(UserStatus::PENDING_IDENTITY, $challenge->status);
    self::assertSame('user@example.com', $challenge->emailCanonical);
    self::assertFalse($challenge->isActive());
}

public function testFailedDeliveryNeverCreatesUsableVerification(): void
{
    $sender = FakeEmailSender::alwaysFail();
    $this->expectExceptionObject(CloudException::emailDeliveryFailed());
    $this->requestEmailCode(sender: $sender)
        ->handle(new RequestEmailCodeCommand('user@example.com', '127.0.0.1'));

    self::assertFalse($this->verifications->hasReadyCode('user@example.com'));
}
```

- [ ] **Step 2: 运行测试并确认应用服务不存在**

Run:

```powershell
Push-Location services/cloud-control-plane
php vendor/bin/phpunit tests/Unit/Identity/RequestEmailCodeTest.php tests/Unit/Identity/CompleteEmailRegistrationTest.php
Pop-Location
```

Expected: FAIL，缺失 `RequestEmailCode`。

- [ ] **Step 3: 实现验证码请求事务和邮件适配器**

验证码规则：

```php
$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$digest = hash_hmac('sha256', $email->canonical() . "\n" . $code, $emailCodePepper);
$expiresAt = $clock->now()->modify('+10 minutes');
```

`RequestEmailCode` 顺序固定：限流预占 → 创建/复用 `PENDING_EMAIL` 用户 → 保存 `PENDING_DELIVERY` 记录 → SMTP 投递 → 标记 `READY`。捕获邮件异常时标记 `INVALIDATED` 并抛 `CLOUD_EMAIL_DELIVERY_FAILED`。

`SmtpEmailSender` 使用 PHPMailer SMTP、TLS、5 秒连接超时和 10 秒总超时；日志只记录请求 ID 和 SMTP 错误类别，不记录验证码或密码。

- [ ] **Step 4: 实现验证码消费和密码保存**

`CompleteEmailRegistration` 在单一数据库事务中：

1. `SELECT ... FOR UPDATE` 读取最新 `READY` 验证记录；
2. 校验未过期、未消费、尝试数小于 5；
3. 使用 `hash_equals` 比较摘要；失败时递增尝试数；
4. 成功时设置 `consumed_at`；
5. 写 Argon2id 密码摘要和 `email_verified_at`；
6. 用户状态变为 `PENDING_IDENTITY`；
7. 生成 32 字节随机注册 Token，把 HMAC 摘要、用户 ID 和过期时间写入 Redis；
8. 返回 15 分钟有效的 `RegistrationChallenge`。

`RegistrationChallenge` 只包含一次性原始 Token、用户 ID、规范邮箱、状态和过期时间，不包含密码摘要或验证码。M1B 只能把原始 Token 放入 `Secure`、`HttpOnly`、`SameSite=Lax` 的短期 Cookie；Redis 只保存 HMAC 摘要。`BeginOAuth` 必须先验证该 Token 才能为 `REGISTER_BIND` 创建 OAuth State，激活成功后删除挑战。

- [ ] **Step 5: 通过单元与 MySQL 事务测试**

`EmailRegistrationPersistenceTest` 必须证明错误验证码不会消费记录、第五次失败后拒绝、成功后重复消费失败、事务异常时用户仍为 `PENDING_EMAIL`。

Run:

```powershell
Push-Location services/cloud-control-plane
docker compose -f docker-compose.test.yml up -d cloud-test-mysql cloud-test-redis
$env:CLOUD_TEST_REDIS_HOST='127.0.0.1'
$env:CLOUD_TEST_REDIS_PORT='16379'
php vendor/bin/phpunit tests/Unit/Identity/RequestEmailCodeTest.php tests/Unit/Identity/CompleteEmailRegistrationTest.php tests/Integration/EmailRegistrationPersistenceTest.php tests/Integration/RedisRateLimiterTest.php
Pop-Location
```

Expected: PASS。

- [ ] **Step 6: 提交邮箱注册核心**

```powershell
git add services/cloud-control-plane/app/Identity services/cloud-control-plane/tests
git commit -m "feat: add verified email registration core"
```

---

### Task 5: 官方 QQ/微信 OAuth、强制绑定与账号激活

**Files:**
- Create: `services/cloud-control-plane/app/Identity/Application/BeginOAuth.php`
- Create: `services/cloud-control-plane/app/Identity/Application/BeginOAuthCommand.php`
- Create: `services/cloud-control-plane/app/Identity/Application/CompleteOAuth.php`
- Create: `services/cloud-control-plane/app/Identity/Application/CompleteOAuthCommand.php`
- Create: `services/cloud-control-plane/app/Identity/Application/OAuthRedirect.php`
- Create: `services/cloud-control-plane/app/Identity/Application/IdentityCompletion.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/OAuthAudience.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/OAuthPurpose.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/OAuthState.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/OAuthCallback.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/ExternalIdentity.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/IdentityProvider.php`
- Create: `services/cloud-control-plane/app/Identity/Port/OAuthProvider.php`
- Create: `services/cloud-control-plane/app/Identity/Port/OAuthStateStore.php`
- Create: `services/cloud-control-plane/app/Identity/Port/ExternalIdentityRepository.php`
- Create: `services/cloud-control-plane/app/Identity/Infrastructure/RedisOAuthStateStore.php`
- Create: `services/cloud-control-plane/app/Identity/Infrastructure/PdoExternalIdentityRepository.php`
- Create: `services/cloud-control-plane/app/Identity/Infrastructure/QqConnectOAuthProvider.php`
- Create: `services/cloud-control-plane/app/Identity/Infrastructure/WechatOpenPlatformOAuthProvider.php`
- Create: `services/cloud-control-plane/app/Tenant/Port/TenantProvisioner.php`
- Create: `services/cloud-control-plane/app/Tenant/Infrastructure/PdoCustomerTenantProvisioner.php`
- Create: `services/cloud-control-plane/tests/Unit/Identity/BeginOAuthTest.php`
- Create: `services/cloud-control-plane/tests/Unit/Identity/CompleteOAuthTest.php`
- Create: `services/cloud-control-plane/tests/Unit/Identity/QqConnectOAuthProviderTest.php`
- Create: `services/cloud-control-plane/tests/Unit/Identity/WechatOpenPlatformOAuthProviderTest.php`
- Create: `services/cloud-control-plane/tests/Integration/IdentityActivationTransactionTest.php`
- Create: `services/cloud-control-plane/tests/Fakes/FakeOAuthProvider.php`

**Interfaces:**
- Produces: `OAuthProvider::authorizationUrl(OAuthState): string`
- Produces: `OAuthProvider::exchangeCallback(OAuthCallback): ExternalIdentity`
- Produces: `BeginOAuth::handle(BeginOAuthCommand): OAuthRedirect`
- Produces: `CompleteOAuth::handle(CompleteOAuthCommand): IdentityCompletion`
- Produces: `IdentityCompletion(userId, audience, tenantId, completedAt)` for M1B

- [ ] **Step 1: 编写强制绑定和禁止自动注册失败测试**

```php
public function testRegistrationBindingActivatesUserAndCreatesCustomerTenantAtomically(): void
{
    $pending = $this->users->pendingIdentity('user@example.com');
    $challenge = $this->registrationChallenges->issueFor($pending->id);
    $this->oauth->willReturn(new ExternalIdentity('QQ', 'qq-app', 'openid-1', 'QQ用户', null));
    $this->beginOAuth()->handle(
        BeginOAuthCommand::registration('QQ', OAuthAudience::PORTAL, $challenge->token)
    );

    $completion = $this->completeOAuth()->handle(
        CompleteOAuthCommand::registration($this->oauthStates->lastIssuedRaw(), 'code-1')
    );

    self::assertSame(UserStatus::ACTIVE, $this->users->get($pending->id)->status);
    self::assertSame('CUSTOMER', $this->tenants->get($completion->tenantId)->type);
    self::assertSame('OWNER', $this->members->role($completion->tenantId, $pending->id));
}

public function testOAuthLoginNeverCreatesUnknownUser(): void
{
    $this->oauth->willReturn(new ExternalIdentity('WECHAT', 'wx-app', 'unionid-unknown', '微信用户', null));
    $this->expectExceptionObject(CloudException::identityNotBound());
    $this->completeOAuth()->handle(CompleteOAuthCommand::login('state-2', 'code-2'));
    self::assertSame(0, $this->users->count());
}
```

- [ ] **Step 2: 运行测试并确认 OAuth 服务不存在**

Run:

```powershell
Push-Location services/cloud-control-plane
php vendor/bin/phpunit tests/Unit/Identity/BeginOAuthTest.php tests/Unit/Identity/CompleteOAuthTest.php
Pop-Location
```

Expected: FAIL，缺失 `BeginOAuth`。

- [ ] **Step 3: 实现一次性 OAuth State**

State 载荷固定为：

```php
new OAuthState(
    digest: hash_hmac('sha256', $rawState, $stateHmacKey),
    audience: OAuthAudience::PORTAL,
    purpose: OAuthPurpose::REGISTER_BIND,
    subjectId: $registrationChallenge->userId,
    redirectPath: '/registration/complete',
    expiresAt: $clock->now()->modify('+10 minutes')
);
```

Redis 使用 Lua 或 `GETDEL` 原子消费；不存在、过期、audience 不匹配或重复消费都抛 `CLOUD_OAUTH_STATE_INVALID`。原始 State 至少 32 字节随机值，只进入 OAuth URL，不写日志。

- [ ] **Step 4: 实现官方 OAuth 适配器**

QQ 固定使用官方端点：

```text
https://graph.qq.com/oauth2.0/authorize
https://graph.qq.com/oauth2.0/token
https://graph.qq.com/oauth2.0/me
https://graph.qq.com/user/get_user_info
```

微信固定使用官方端点：

```text
https://open.weixin.qq.com/connect/qrconnect
https://api.weixin.qq.com/sns/oauth2/access_token
https://api.weixin.qq.com/sns/userinfo
```

适配器必须：5 秒连接超时、10 秒总超时、校验 HTTP 状态、解析提供商错误、拒绝空 Subject。微信优先使用 `unionid`；未返回时使用当前 App ID 作为 issuer、`openid` 作为 subject。测试用 Guzzle `MockHandler` 验证请求参数和错误转换。

若 Client ID/Secret/Redirect URI 缺失，`BeginOAuth` 在发起网络请求前抛 `CLOUD_OAUTH_NOT_CONFIGURED`。

- [ ] **Step 5: 实现绑定激活事务和 IdentityCompletion**

注册绑定事务固定为：

1. 锁定 `PENDING_IDENTITY` 用户；
2. 验证第三方身份全局未绑定；
3. 插入 `cloud_user_identities`；
4. 用户切换 `ACTIVE`；
5. 创建 `CUSTOMER` 租户；
6. 创建 `OWNER` 成员；
7. 返回 `IdentityCompletion`。

任何唯一键冲突转换为 `CLOUD_IDENTITY_ALREADY_BOUND`，事务整体回滚。登录用途只查已绑定身份并返回 completion，不创建用户或租户。

- [ ] **Step 6: 通过单元、HTTP 适配器和事务测试**

Run:

```powershell
Push-Location services/cloud-control-plane
docker compose -f docker-compose.test.yml up -d cloud-test-mysql cloud-test-redis
$env:CLOUD_TEST_REDIS_HOST='127.0.0.1'
$env:CLOUD_TEST_REDIS_PORT='16379'
php vendor/bin/phpunit tests/Unit/Identity tests/Integration/IdentityActivationTransactionTest.php
Pop-Location
```

Expected: PASS；扫描生产目录不存在 `FakeOAuthProvider`。

Run scan:

```powershell
$matches = Get-ChildItem services/cloud-control-plane/app -Recurse -File -Filter '*.php' | Select-String -Pattern 'FakeOAuth|mock success|模拟成功'
if (@($matches).Count -ne 0) { $matches; exit 1 }
```

- [ ] **Step 7: 提交 OAuth 和账号激活**

```powershell
git add services/cloud-control-plane/app/Identity services/cloud-control-plane/app/Tenant services/cloud-control-plane/tests
git commit -m "feat: activate cloud users through official oauth"
```

---

### Task 6: TOTP 设置、验证和官方强制策略

**Files:**
- Create: `services/cloud-control-plane/app/Identity/Application/BeginTotpSetup.php`
- Create: `services/cloud-control-plane/app/Identity/Application/TotpSetupView.php`
- Create: `services/cloud-control-plane/app/Identity/Application/ConfirmTotpSetup.php`
- Create: `services/cloud-control-plane/app/Identity/Application/VerifyTotp.php`
- Create: `services/cloud-control-plane/app/Identity/Application/DisableTotp.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/Totp.php`
- Create: `services/cloud-control-plane/app/Identity/Domain/PendingTotpSetup.php`
- Create: `services/cloud-control-plane/app/Shared/Security/Base32.php`
- Create: `services/cloud-control-plane/app/Identity/Port/TotpSetupStore.php`
- Create: `services/cloud-control-plane/app/Identity/Port/TotpReplayGuard.php`
- Create: `services/cloud-control-plane/app/Identity/Infrastructure/RedisTotpSetupStore.php`
- Create: `services/cloud-control-plane/app/Identity/Infrastructure/RedisTotpReplayGuard.php`
- Modify: `services/cloud-control-plane/app/Identity/Port/UserRepository.php`
- Modify: `services/cloud-control-plane/app/Identity/Infrastructure/PdoUserRepository.php`
- Create: `services/cloud-control-plane/tests/Unit/Identity/TotpTest.php`
- Create: `services/cloud-control-plane/tests/Unit/Identity/TotpSetupTest.php`
- Create: `services/cloud-control-plane/tests/Integration/TotpPersistenceTest.php`

**Interfaces:**
- Produces: `BeginTotpSetup::handle(userId, issuer, account): TotpSetupView`
- Produces: `ConfirmTotpSetup::handle(userId, code): void`
- Produces: `VerifyTotp::handle(userId, code, at): bool`
- Guarantees: 正式 TOTP 密钥只以加密密文和 nonce 落库

- [ ] **Step 1: 编写 RFC 6238 向量和确认前不可用测试**

```php
public function testTotpMatchesRfc6238Sha1Vector(): void
{
    $totp = new Totp(period: 30, digits: 8, algorithm: 'sha1');
    $secret = '12345678901234567890';
    self::assertSame('94287082', $totp->at($secret, 59));
    self::assertSame('07081804', $totp->at($secret, 1111111109));
}

public function testPendingTotpSecretIsNotEnabledBeforeConfirmation(): void
{
    $setup = $this->begin->handle('user-1', 'CXPAY Cloud', 'user@example.com');
    self::assertStringStartsWith('otpauth://totp/', $setup->provisioningUri);
    self::assertFalse($this->users->get('user-1')->totpEnabled());
}
```

- [ ] **Step 2: 运行测试并确认 TOTP 类不存在**

Run:

```powershell
Push-Location services/cloud-control-plane
php vendor/bin/phpunit tests/Unit/Identity/TotpTest.php tests/Unit/Identity/TotpSetupTest.php
Pop-Location
```

Expected: FAIL，缺失 `Totp`。

- [ ] **Step 3: 实现 TOTP 与短期设置状态**

生产配置固定为 SHA-1、6 位、30 秒。验证允许当前时间片前后各 1 个时间片，并通过 Redis 原子记录 `user_id + time_step` 防止同一码重放。

密钥生成：

```php
$secretBytes = random_bytes(20);
$secretBase32 = Base32::encodeUnpadded($secretBytes);
```

待确认密钥只存 Redis 10 分钟；确认成功后用 `SecretCipher` 加密并写入 `cloud_users`，再删除 Redis 待确认状态。

- [ ] **Step 4: 实现官方强制和停用规则**

M1A 提供策略方法：

```php
public function requiredForTenantType(string $tenantType): bool
{
    return $tenantType === 'OFFICIAL';
}
```

`DisableTotp` 对 OFFICIAL 成员始终抛 `CLOUD_TOTP_SETUP_REQUIRED`；客户/代理停用必须同时验证当前密码和当前 TOTP。成功后清空密文、nonce、启用时间，并返回需要 M1B 轮换会话的安全事件。

- [ ] **Step 5: 通过单元和密文持久化测试**

`TotpPersistenceTest` 必须断言数据库不包含 Base32 明文，错误动态码不启用，重复时间片被拒绝。

Run:

```powershell
Push-Location services/cloud-control-plane
docker compose -f docker-compose.test.yml up -d cloud-test-mysql cloud-test-redis
$env:CLOUD_TEST_REDIS_HOST='127.0.0.1'
$env:CLOUD_TEST_REDIS_PORT='16379'
php vendor/bin/phpunit tests/Unit/Identity/TotpTest.php tests/Unit/Identity/TotpSetupTest.php tests/Integration/TotpPersistenceTest.php
Pop-Location
```

Expected: PASS。

- [ ] **Step 6: 提交 TOTP 核心**

```powershell
git add services/cloud-control-plane/app/Identity services/cloud-control-plane/tests
git commit -m "feat: add encrypted totp identity verification"
```

---

### Task 7: M1A 集成门禁与交接契约

**Files:**
- Create: `services/cloud-control-plane/tests/Contract/IdentityCompletionContractTest.php`
- Create: `services/cloud-control-plane/tests/Integration/M1aIdentityLifecycleTest.php`
- Create: `services/cloud-control-plane/README.md`
- Create: `docs/contracts/cloud-control-plane-identity-completion-v1.md`

**Interfaces:**
- Freezes: `IdentityCompletion` 给 M1B 的字段和不变量
- Verifies: 邮箱 → OAuth → ACTIVE → CUSTOMER/OWNER → TOTP 的完整身份生命周期

- [ ] **Step 1: 编写跨模块生命周期失败测试**

```php
public function testVerifiedEmailAndOauthBindingProduceOneActiveCustomerIdentity(): void
{
    $email = $this->identityHarness->requestAndCompleteEmail('user@example.com');
    self::assertSame('PENDING_IDENTITY', $email->status->value);

    $completion = $this->identityHarness->bindQqAndActivate($email->userId, 'qq-openid-1');

    self::assertSame('PORTAL', $completion->audience->value);
    self::assertSame('ACTIVE', $this->users->get($completion->userId)->status->value);
    self::assertSame('CUSTOMER', $this->tenants->get($completion->tenantId)->type->value);
    self::assertSame('OWNER', $this->members->find($completion->tenantId, $completion->userId)->role->value);
    self::assertSame(1, $this->identities->countForUser($completion->userId));
}
```

- [ ] **Step 2: 运行测试并确认交接契约尚未冻结**

Run:

```powershell
Push-Location services/cloud-control-plane
php vendor/bin/phpunit tests/Contract/IdentityCompletionContractTest.php tests/Integration/M1aIdentityLifecycleTest.php
Pop-Location
```

Expected: FAIL，缺少契约文档或序列化字段断言。

- [ ] **Step 3: 固定 IdentityCompletion v1**

文档和对象字段固定为：

```json
{
  "version": "identity-completion-v1",
  "user_id": "UUIDv7",
  "audience": "PORTAL|OPS",
  "tenant_id": "UUIDv7|null",
  "totp_required": false,
  "completed_at": "UTC RFC3339 microseconds"
}
```

规则：

- Portal 注册激活必须有 CUSTOMER `tenant_id`。
- 已绑定身份登录在多租户情况下允许 `tenant_id=null`，由 M1B 选择租户。
- Ops completion 必须 `totp_required=true`，且不能直接产生已认证 Session。
- 对象不得包含邮箱验证码、OAuth Token、密码摘要、TOTP 密钥或第三方 Subject。

- [ ] **Step 4: 编写独立运行说明**

`README.md` 必须包含：子项目依赖安装、测试 MySQL/Redis 启动、迁移、单元/集成测试、环境变量说明，以及明确声明 M1A 还不对外开放正式注册 HTTP API，HTTP Controller 和 Session 在 M1B 接入。

- [ ] **Step 5: 运行 M1A 完整验证**

Run:

```powershell
Push-Location services/cloud-control-plane
docker compose -f docker-compose.test.yml up -d cloud-test-mysql cloud-test-redis
$env:CLOUD_TEST_DB_DSN='mysql:host=127.0.0.1;port=13316;dbname=cxpay_cloud_test;charset=utf8mb4'
$env:CLOUD_TEST_DB_USERNAME='cxpay_test'
$env:CLOUD_TEST_DB_PASSWORD='cxpay_test_password'
$env:CLOUD_TEST_REDIS_HOST='127.0.0.1'
$env:CLOUD_TEST_REDIS_PORT='16379'
composer validate --strict
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit --testsuite Integration
php vendor/bin/phpunit --testsuite Contract
$phpFiles = Get-ChildItem app,config,tests,bin -Recurse -File -Filter '*.php'
$failures = @()
foreach ($file in $phpFiles) {
    php -l $file.FullName | Out-Null
    if ($LASTEXITCODE -ne 0) { $failures += $file.FullName }
}
if ($failures.Count -ne 0) { $failures; exit 1 }
Pop-Location
git diff --check
git status --short
docker compose -f docker-compose.test.yml down -v
```

Expected: Composer、Unit、Integration、Contract 和语法全部通过；只允许 `CXPAY.rar` 保持未跟踪，不存在其他未提交文件。

- [ ] **Step 6: 运行根 CXPAY 回归测试证明隔离**

Run from repository root:

```powershell
php vendor/bin/phpunit --display-warnings
```

Expected: 根 CXPAY 全量测试通过，测试数量不少于实施前 262 个；根进程不自动加载 `CloudControl\`。

- [ ] **Step 7: 提交 M1A 验收结果**

```powershell
git add services/cloud-control-plane/README.md services/cloud-control-plane/tests/Contract services/cloud-control-plane/tests/Integration/M1aIdentityLifecycleTest.php docs/contracts/cloud-control-plane-identity-completion-v1.md
git commit -m "test: verify cloud identity core lifecycle"
```

## M1A Completion Gate

规格覆盖映射：独立部署边界由 Task 1/3 覆盖；邮箱、密码和注册状态由 Task 2/4 覆盖；QQ/微信和强制绑定由 Task 5 覆盖；TOTP 由 Task 6 覆盖；租户、成员、会话、邀请和审计的数据库边界由 Task 3 固定。会话/RBAC/邀请/审计行为与 HTTP API 明确进入 M1B，基础前端与生产部署进入 M1C，不在 M1A 中制造临时实现。

只有同时满足以下条件才能编写并执行 M1B：

- 云端子项目有独立 Composer lock 和测试入口；
- 所有迁移可重复检查且校验和不可变；
- 邮箱投递失败不会产生可用验证码；
- 未完成邮箱验证或 OAuth 绑定的用户不能 ACTIVE；
- QQ/微信登录不自动创建用户；
- 第三方身份唯一约束同时由领域规则和数据库保证；
- OAuth State 一次性消费且 Portal/Ops audience 不可混用；
- 正式目录没有测试 OAuth 假实现；
- TOTP 密钥不以明文落库，官方策略要求 TOTP；
- IdentityCompletion v1 已冻结且不含敏感信息；
- 子项目测试与根 CXPAY 回归测试全部通过；
- 每个任务形成独立提交，除用户现有 `CXPAY.rar` 外工作区干净。
