# CXPAY 云端控制面

本目录是与根 CXPAY 支付系统隔离部署的云端服务，负责账户、授权、源码交付和插件权益等控制面能力。它拥有独立的 Composer 依赖、数据库、Redis、配置与发布流程；根 CXPAY 进程不会自动加载 `CloudControl\` 命名空间。

当前版本为 **M1A 身份核心**：已经实现邮箱验证注册、密码摘要、QQ/微信官方 OAuth 身份绑定、客户租户初始化和 TOTP 核心。正式注册/登录 HTTP Controller、Session、RBAC、邀请和审计行为将在 M1B 接入，因此本阶段不能直接作为公网注册入口。

## 运行要求

- PHP 8.2 及 `json`、`mbstring`、`openssl`、`pdo_mysql` 扩展
- MySQL 8.0
- Redis 7
- Composer 2
- 生产环境必须安装原生 Sodium 扩展；`sodium_compat` 仅用于兼容和开发测试

## 安装与配置

所有命令均在本目录执行：

```powershell
composer install --no-interaction
Copy-Item .env.example .env
```

编辑 `.env`，至少设置独立的 MySQL、Redis、SMTP 和 OAuth 参数。以下三个变量必须分别使用独立生成的 32 字节 Base64URL 密钥，不得复用，也不得提交到版本库：

- `CLOUD_EMAIL_CODE_PEPPER`
- `CLOUD_OAUTH_STATE_HMAC_KEY`
- `CLOUD_TOTP_ENCRYPTION_KEY`

可分别执行三次以下命令生成密钥：

```powershell
php -r "echo rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), PHP_EOL;"
```

QQ 和微信必须配置各自的官方应用凭据，并分别登记 Portal、Ops 回调地址。两类受众的 OAuth State 不可混用；未配置凭据时服务会拒绝启动对应流程，不会退回测试实现。

## 数据库迁移

迁移连接读取 `.env` 中的 `CLOUD_DB_*` 变量：

```powershell
php bin/migrate.php
```

迁移按文件校验和记录。已经执行的迁移不可原地修改，应新增迁移文件演进结构。

## 本地测试

启动隔离的测试依赖：

```powershell
docker compose -f docker-compose.test.yml up -d cloud-test-mysql cloud-test-redis
```

设置测试连接：

```powershell
$env:CLOUD_TEST_DB_DSN='mysql:host=127.0.0.1;port=13316;dbname=cxpay_cloud_test;charset=utf8mb4'
$env:CLOUD_TEST_DB_USERNAME='cxpay_test'
$env:CLOUD_TEST_DB_PASSWORD='cxpay_test_password'
$env:CLOUD_TEST_REDIS_HOST='127.0.0.1'
$env:CLOUD_TEST_REDIS_PORT='16379'
```

执行分层或完整测试：

```powershell
composer validate --strict
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit --testsuite Integration
php vendor/bin/phpunit --testsuite Contract
php vendor/bin/phpunit --display-warnings
```

测试结束后仅停止容器可保留数据；需要完全清空测试数据时再显式删除测试卷：

```powershell
docker compose -f docker-compose.test.yml stop
# 确认不再需要测试数据后：docker compose -f docker-compose.test.yml down -v
```

## 部署边界

- 云端控制面与 CXPAY 实例使用不同域名、进程、数据库、Redis 和密钥。
- 云端网站允许已授权用户下载源码包和更新包。
- 支付通道插件只能由已安装的 CXPAY 通过插件商城接口下载和更新，不在云端用户网站提供插件文件直链。
- CXPAY 运行时不得依赖云端账户数据库；后续通过签名授权凭据和版本化 API 通信。
- `IdentityCompletion` 的 M1B 交接格式见 `docs/contracts/cloud-control-plane-identity-completion-v1.md`。

## M1A 尚未包含

- 对公网开放的注册、登录和 OAuth 回调路由
- Session 签发、刷新、吊销及设备管理
- RBAC、管理员邀请和审计行为实现
- 源码授权、续费、订单、下载以及插件商城交付业务
- 完整的生产就绪探针、限流、监控告警和部署清单

这些能力必须在后续里程碑基于已冻结契约接入，不应绕过身份核心直接拼接临时 Controller。
