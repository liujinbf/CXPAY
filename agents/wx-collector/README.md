# 微信授权采集器 SDK

本目录提供 WX Monitor Cloud 的采集器协议客户端和守护进程。它不包含微信逆向协议，也不会自行生成二维码或账单。

真实数据源接入方必须实现 `WxCollector\ProviderAdapterInterface`：

- `startAuthorization()`：从合法数据源创建授权二维码；
- `pollAuthorization()`：查询扫码和授权状态；
- `pullPaymentEvents()`：读取尚未确认的真实到账账单；
- `acknowledgePaymentEvent()`：云服务确认接收后推进本地游标。

默认使用 `UnavailableProviderAdapter`，此时不会产生任何二维码或账单。

## 支付宝网页扫码适配器

同一采集器协议也提供实验性的 `WxCollector\AlipayWebProviderAdapter`，供支付宝扫码免挂插件使用。它会直接访问支付宝网页版登录流程，Cookie 使用 AES-256-GCM 加密后仅保存在采集器本机，不会上报 WX Monitor Cloud 或 CXPAY。

```bash
export WXCOLLECTOR_ADAPTER_CLASS="WxCollector\\AlipayWebProviderAdapter"
export ALIC_MASTER_KEY="$(php -r 'echo base64_encode(random_bytes(32));')"
export ALIC_STATE_DIR="/var/lib/cxpay/alipay-sessions"
```

该适配器当前只实现二维码生成、扫码状态轮询和登录会话加密保存。由于支付宝网页版余额变化不具备稳定账单号，当前不会生成自动核销事件；必须在获得真实、稳定且授权可访问的交易流水后再实现 `pullPaymentEvents()`。网页接口可能随支付宝调整而失效，部署方需自行评估服务条款及账号风控风险。

扫码确认后，Runner 会读取协调服务生成的正式 `account_id`，再把临时授权状态原子迁移为账号凭据。重复回执保持幂等；如果相同账号已绑定其他会话则拒绝覆盖，避免串号。

合法服务商实现标准签名 HTTPS 接口后，可以直接使用内置 `SignedHttpProviderAdapter`，无需修改 Runner。

## 独立安装

```bash
cd agents/wx-collector
composer install --no-dev --classmap-authoritative
```

配置环境变量：

```bash
export WXCOLLECTOR_CLOUD_URL="https://monitor.example.com"
export WXCOLLECTOR_ID="authorized-collector-01"
export WXCOLLECTOR_SECRET="provision命令生成的采集器密钥"
export WXCOLLECTOR_ADAPTER_CLASS="Company\\AuthorizedWechatAdapter"
php collector.php start
```

标准 HTTPS 适配器配置：

```bash
export WXCOLLECTOR_ADAPTER_CLASS="WxCollector\\SignedHttpProviderAdapter"
export WXPROVIDER_BASE_URL="https://authorized-provider.example.com"
export WXPROVIDER_CLIENT_ID="provider-client-01"
export WXPROVIDER_REQUEST_SECRET="请求签名密钥"
export WXPROVIDER_RESPONSE_SECRET="响应验签密钥"
```

服务商需要实现：

- `POST /v1/authorization-sessions`
- `GET /v1/authorization-sessions/{session_id}`
- `GET /v1/payment-events?limit=50`
- `POST /v1/payment-events/{ack_token}/ack`

请求使用 `X-Provider-Client`、`X-Provider-Timestamp`、`X-Provider-Nonce` 和 `X-Provider-Signature`。规范串与 WX Monitor Cloud 相同。服务商必须使用响应密钥对原始 JSON 响应体签名，并通过 `X-Provider-Signature` 返回。

生产环境必须使用 HTTPS。只有本机测试时才能显式设置 `WXCOLLECTOR_ALLOW_HTTP=true`。

## 授权状态

适配器可以返回：

- `QR_READY`：必须包含真实授权二维码 `qr_url`；
- `SCANNED`：用户已扫码，等待确认；
- `CONFIRMED`：必须包含真实账号信息及能力状态；
- `FAILED`：授权失败，并提供不含敏感凭据的说明。

Runner 会先向云端提交内部状态 `CLAIMED`，原子领取成功后才调用适配器。适配器必须使用云端 `session_id` 保证服务商侧创建操作幂等。

确认登录采用两阶段完成：采集器先提交 `CONFIRMED` 获取云端正式 `account_id`，本地凭据绑定成功后再提交内部状态 `BOUND`。如果本地绑定失败，`CONFIRMED` 会继续投递供采集器重试；客户端查询时 `BOUND` 仍统一显示为 `CONFIRMED`。

确认示例结构：

```php
return [
    'status' => 'CONFIRMED',
    'external_ref' => '数据源中的稳定账号引用',
    'display_name' => '脱敏显示名称',
    'capability_status' => 'RECEIPT_AVAILABLE',
    'capabilities' => [
        'receipt' => true,
        'book' => true,
    ],
];
```

只有数据源明确证明未开通收款单时，才能上报 `RECEIPT_NOT_OPENED`。网络错误、接口变化、权限不足或无法判断必须上报 `TEMPORARY_ERROR` 或 `UNKNOWN`。

## 账单事件

```php
return [[
    'ack_token' => '本地稳定游标或消息ID',
    'account_id' => 'WX Monitor Cloud 返回的账号ID',
    'source_bill_id' => '数据源稳定账单号',
    'amount' => '10.01',
    'occurred_at' => 1760000000,
]];
```

`ack_token` 不会上报云端。只有云端返回 `accepted=true` 后，Runner 才调用 `acknowledgePaymentEvent()`。失败时账单保留在数据源，下一周期重试，并由云端幂等键阻止重复核销。

## 安全要求

- 禁止记录 Cookie、会话票据、完整用户身份信息和请求密钥；
- 采集器凭据与服务商凭据分开保存；
- 每个账单必须有数据源稳定唯一编号；
- 不能用通知文本摘要或随机数冒充真实账单号；
- 适配器必须遵守数据源服务条款和用户授权范围；
- 数据源异常时停止产生账单，不得猜测授权结果。
