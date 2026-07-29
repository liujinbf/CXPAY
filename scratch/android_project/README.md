# CXPAY Android到账监控端

该工程提供支付宝、微信、QQ钱包三种个人码通道的通知监听。一个安装实例只绑定一个通道和一种 `pay_type`，避免跨钱包通知被错误上报。

支持两种投递模式：

- `direct`：Android 直接按 v2 HMAC 协议上报 `/api/appasst/push`，需要设备ID和上报密钥。
- `feed`：Android 只把真实到账写入 `/api/bill-source/ingest`，由 PC 监控端负责拉取、心跳和最终上报；需要采集端ID和账单源写入令牌。

两类密钥均使用 Android Keystore 的 AES-GCM 密钥加密后保存。`feed` 模式使用商户中心“账单源令牌”功能生成的写入令牌，采集端ID必须与生成令牌时填写的值完全一致。

通知文案由支付客户端控制，版本变化可能导致识别失败。发布前必须在目标支付宝、微信、QQ版本上分别完成真实小额回归测试。工程目前没有离线重试队列，网络失败会记录失败状态但不会持久化重试，因此仍不应直接作为无人值守生产版本。

本机未附带 Gradle Wrapper。使用已安装 Android SDK/Gradle 的开发环境打开本目录，再执行：

```text
gradle :app:assembleDebug
```
