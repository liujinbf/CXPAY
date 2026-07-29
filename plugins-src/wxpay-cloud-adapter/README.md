# 微信云监控适配器

这是 CXPAY 的主站适配插件，不包含微信协议登录、账号会话或账单采集实现。它必须连接独立部署且经过授权的 WX Monitor Cloud。

插件提供：

- 微信个人收款码出码；
- 收款单/小账本账号能力探测；
- HMAC-SHA256 签名到账回调；
- 云端异常账单查询、人工匹配和忽略；
- 网络异常与“明确未开通收款单”的状态区分。

没有配置真实云监控服务时，能力探测会返回 `TEMPORARY_ERROR`，不会把用户误判为未开通收款单。

## 到账回调

回调地址为 `/notify/wxpay_cloud_adapter`，表单参数包括：

- `source_bill_id`：云监控服务的稳定账单号；
- `out_trade_no`：CXPAY 平台流水号；
- `money`：两位小数的实际到账金额；
- `occurred_at`：到账 Unix 时间戳，允许与主站相差 300 秒；
- `timestamp`：本次回调推送时间，用于 300 秒防重放窗口；
- `nonce`：本次事件随机串；
- `sign`：HMAC-SHA256 签名。

签名时先按字段名排序（不包含 `sign`），使用 RFC3986 查询字符串编码，再用 `callback_secret` 计算十六进制 HMAC-SHA256。

## 能力探测

插件请求 `GET /v1/accounts/{account_id}/capabilities`。云服务必须对原始 JSON 响应体计算 HMAC-SHA256，并通过 `X-CXPAY-Signature` 返回。

允许的状态包括：`RECEIPT_AVAILABLE`、`RECEIPT_NOT_OPENED`、`BOOK_AVAILABLE`、`REAUTH_REQUIRED`、`TEMPORARY_ERROR`、`UNKNOWN`。

## 异常账单复核

插件 1.1.0 起会把云服务中的 `REVIEW_REQUIRED` 和 `UNMATCHED` 事件接入 CXPAY“到账账单复核”页面。人工匹配成功后，云服务通过可靠回调队列通知 CXPAY；插件本身不会绕过统一订单结算服务直接修改订单。

插件 1.2.0 增加 `callback_secret_previous`，用于响应和到账回调密钥的无中断轮换。该字段只能在轮换宽限期内保留，确认云服务已使用新密钥后应及时清空。

插件 1.3.0 增加签名运维状态接口，将账号能力、采集器最近鉴权、异常账单和回调积压接入 CXPAY 管理后台。状态响应不会包含密钥或原始密文。

构建前请准备与主站受信任公钥对应的离线 RSA 私钥：

```bash
php tools/plugin/build.php plugins-src/wxpay-cloud-adapter /secure/private.pem dist/wxpay-cloud-adapter.cxpay-plugin
```
