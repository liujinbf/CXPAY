# 支付宝扫码免挂部署说明

支付宝扫码免挂由三个隔离组件组成：

1. CXPAY 的 `alipay_scan_monitor` 支付插件；
2. `services/wx-monitor-cloud` 中的通用订单协调与签名回调服务；
3. `agents/wx-collector` 中的 `AlipayWebProviderAdapter` 支付宝网页采集器。

协调服务目录仍保留历史名称，但订单登记、授权会话、账单匹配和回调发件箱均与支付平台无关。支付宝建议独立部署一套实例、数据库和主密钥，不与微信账号混用。

## 部署顺序

1. 独立启动协调服务，使用新的数据库和 `WXMC_MASTER_KEY`。
2. 创建 CXPAY Client，回调地址填写 `https://支付站域名/notify/alipay_scan_monitor`。
3. 创建 Collector 身份。
4. 启动采集器并设置 `WXCOLLECTOR_ADAPTER_CLASS=WxCollector\AlipayWebProviderAdapter`。
5. 安装并启用 `alipay_scan_monitor` 插件。
6. 先以停用状态保存通道，点击“支付宝扫码登录”，确认成功后再启用。

采集器关键配置：

```dotenv
WXCOLLECTOR_CLOUD_URL=https://alipay-monitor.example.com
WXCOLLECTOR_ID=alipay-collector-01
WXCOLLECTOR_SECRET=至少32位采集器请求密钥
WXCOLLECTOR_ADAPTER_CLASS=WxCollector\AlipayWebProviderAdapter
ALIC_MASTER_KEY=Base64编码的32字节随机密钥
ALIC_STATE_DIR=/var/lib/cxpay/alipay-sessions
```

PHP 必须配置可信 CA，例如 `curl.cainfo` 和 `openssl.cafile`。禁止通过关闭 TLS 校验解决证书错误。

## 当前可用范围

- 已实现真实支付宝二维码生成；
- 已实现等待扫码、已扫码及确认登录状态；
- 已实现 Cookie 本地 AES-256-GCM 加密保存；
- 已实现临时授权会话到云端正式 `account_id` 的幂等凭据绑定；
- 已实现 `CONFIRMED → BOUND` 两阶段确认，采集器落盘失败后可自动重试；
- CXPAY 和协调服务只接收不可复用的账号 ID；
- 尚未启用自动到账事件。

自动到账暂时关闭是有意的安全限制。余额增量没有稳定支付宝流水号，可能受到转账、退款、提现、手续费及并发到账影响。只有取得真实、稳定、可去重且经用户授权访问的交易流水后，才应实现账单上报和自动核销。
