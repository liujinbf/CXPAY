# 支付宝扫码免挂适配器

该插件面向支付宝个人收款码，不接入支付宝官方商户支付。它在订单展示固定个人收款码之前，先向经过授权的云账单服务登记平台订单；云服务确认真实到账并唯一匹配后，再向 CXPAY 发起签名回调。

插件本身不包含支付宝账号登录、Cookie 获取、App 逆向、风控绕过或账单抓取逻辑。没有合法云账单服务时，本插件不能独立实现免挂。

支付宝扫码登录由隔离部署的云账单服务执行，CXPAY 只接收授权会话状态和不可复用的 `account_id`，不得接收、展示或保存支付宝 Cookie。商户需先以停用状态保存通道，再点击“支付宝扫码登录”；登录确认并自动写入账号标识后，方可启用通道。

## 云服务协议

订单登记：`POST /v1/orders`，JSON 字段包括 `account_id`、`out_trade_no`、`amount`、`expires_at` 和固定的 `pay_type=alipay`。

扫码登录协议：

- `POST /v1/auth-sessions`：创建授权会话，请求包含 `reference` 和 `pay_type=alipay`。
- `GET /v1/auth-sessions/{session_id}`：返回 `PENDING`、`SCANNED`、`CONFIRMED`、`FAILED` 或 `EXPIRED`。
- 等待扫码时可以返回 `qr_url` 和 `expires_at`；确认成功时必须返回 16 至 64 位不可猜测的 `account_id`。
- 支付宝网页 Cookie 必须只保存在隔离云服务的加密凭据库中，任何响应及日志都不得包含 Cookie。

请求使用 `X-CXPAY-Client`、`X-CXPAY-Timestamp`、`X-CXPAY-Nonce`、`X-CXPAY-Signature`。签名规范串为：

```text
HTTP_METHOD\nREQUEST_PATH\nTIMESTAMP\nNONCE\nSHA256(RAW_BODY)
```

云服务必须使用回调密钥对原始响应 JSON 计算 HMAC-SHA256，并通过 `X-CXPAY-Signature` 返回。

云服务地址必须使用公网 HTTPS。订单登记未明确返回 `accepted=true` 时，插件不会展示收款码，避免产生无法监控的支付订单。

到账回调地址：`/notify/alipay_scan_monitor`。表单字段为 `source_bill_id`、`out_trade_no`、`money`、`occurred_at`、`timestamp`、`nonce`、`sign`。除 `sign` 外按字段名排序，使用 RFC3986 查询字符串编码后计算 HMAC-SHA256。

`money` 必须先规范为两位小数字符串再参与签名；`timestamp` 是本次推送时间，只允许与 CXPAY 相差 300 秒，`occurred_at` 是真实到账时间，最多允许延迟七天送达。相同签名回放只能再次指向原平台订单，最终结算仍由 CXPAY 的订单幂等约束裁决。

已有 `alipay_scan_bill` 通道不会被自动转换。安装新插件后应新建 `alipay_scan_monitor` 通道、完成云服务配置和小额真实到账验证，再停用并删除旧通道。

为了接入 CXPAY 统一运维页面，云服务还应实现：

- `GET /v1/review/events`
- `POST /v1/review/events/{event_id}/match`
- `POST /v1/review/events/{event_id}/ignore`
- `GET /v1/ops/status`

这些接口与订单登记使用同一请求鉴权和响应签名规则。异常账单必须由云服务再次校验账号、金额、订单状态和到账时间窗后才能进入回调队列。

`callback_secret_previous` 仅用于密钥轮换宽限期，切换稳定后应清空。

## 构建

```bash
php tools/plugin/build.php plugins-src/alipay-scan-monitor /secure/private.pem dist/alipay-scan-monitor.cxpay-plugin
```
