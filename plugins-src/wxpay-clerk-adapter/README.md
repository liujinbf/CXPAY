# 微信店员到账适配器

该插件连接独立 `wxpay-clerk-service`，适用于个人微信收款码或赞赏码的店员到账通知场景。

## 能力

- HMAC 签名订单登记，只有服务返回 `accepted=true` 才展示收款码。
- 订单主动查询，可在异步回调故障期间恢复已支付订单。
- 签名到账回调与新旧密钥轮换。
- 店员账号扫码授权和在线能力检查。
- 未匹配到账人工复核与运维状态接口。

## 风险边界

本通道依赖个人微信店员账号和 Gewe/iPad 协议，不是微信官方商户支付接口。商户主账号无需在云端登录，但店员账号仍存在平台风控、协议升级、消息格式变化和服务中断风险。使用者应确认账号授权、平台规则和当地合规要求，并保留人工复核方案。

## 安全要求

- `monitor_base_url` 必须是可解析的公网 HTTPS 地址，客户端禁止跟随重定向。
- `client_secret` 与 `callback_secret` 必须分别使用 32–128 位随机密钥。
- 回调校验账单号、订单号、金额、到账时间、推送时间和 nonce；只接受 7 天内且不晚于未来 300 秒的到账事实。
- 服务端 Gewe Webhook 必须同时配置不可猜测路径令牌和来源 IP 白名单。

回调地址为 `/notify/wxpay_clerk_adapter`，字段包括 `source_bill_id`、`out_trade_no`、`money`、`occurred_at`、`timestamp`、`nonce` 和 `sign`。

## 构建

```bash
php tools/plugin/build.php plugins-src/wxpay-clerk-adapter /secure/private.pem dist/wxpay-clerk-adapter.cxpay-plugin
```
