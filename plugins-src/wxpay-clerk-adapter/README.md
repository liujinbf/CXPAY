# 微信云端店员免挂适配器

这是 CXPAY 面向微信个人收款码/赞赏码的官方店员模式免挂适配插件。商户只需将服务商的统一店员接收号添加为收款店员，即可完成全自动免挂机回调。

## 优势与特性

- **零挂机**：商户不需要在自己的电脑或手机上后台运行微信。
- **零风险**：商户不需要在云端登录微信主号，完全使用微信官方店员授权，无封号风险。
- **广兼容**：完美支持个人收款码、赞赏码与小账本。
- **安全校验**：到账回调采用 HMAC-SHA256 签名，支持密钥无缝轮换和防重放校验。

## 到账回调

回调地址：`/notify/wxpay_clerk_adapter`

请求参数包括：
- `source_bill_id`：云监控服务的稳定账单号；
- `out_trade_no`：CXPAY 平台流水号；
- `money`：两位小数的实际到账金额；
- `occurred_at`：到账 Unix 时间戳；
- `timestamp`：推送时间；
- `nonce`：随机串；
- `sign`：HMAC-SHA256 签名。

## 插件构建

构建插件安装包（需准备离线 RSA 私钥）：

```bash
php tools/plugin/build.php plugins-src/wxpay-clerk-adapter /secure/private.pem dist/wxpay-clerk-adapter.cxpay-plugin
```
