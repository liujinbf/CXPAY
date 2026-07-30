# Alipay Monitor Cloud (支付宝云监控独立协调服务)

`Alipay Monitor Cloud` 是与 CXPAY 主站分离部署的支付宝扫码免挂/个人账单监控协调服务。

它负责管理商户的扫码授权会话、账号能力、待匹配订单注册、真实到账事件接收及可靠回调。

---

## 架构与安全特性

1. **角色分离与安全隔离**：
   - CXPAY 主站通过 HMAC-SHA256 签名与云服务通讯。
   - 云服务内部采用 `AES-256-GCM` 密文保存商户的 Session 或密钥，绝不上报或泄漏 Cookie。
2. **防重放与防篡改**：
   - 300 秒请求时钟窗口与随机数防重放保护。
3. **退避重试发件箱**：
   - 到账事件经唯一匹配后通过可靠回调推送给 CXPAY 平台的 `/notify/alipay_scan_monitor` 接口。

---

## 快速启动

1. 生成主加密密钥：
   ```bash
   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
   ```

2. 设置环境变量并启动服务：
   ```bash
   export AMC_MASTER_KEY="<上一步生成的主密钥>"
   export AMC_DSN="sqlite:/absolute/path/alipay-monitor-cloud.sqlite"
   export AMC_LISTEN="http://127.0.0.1:8788"

   php server.php start
   ```

3. 配置 CXPAY 主站 `alipay_scan_monitor` 通道：
   在商户后台填入云服务地址 `https://your-domain.com:8788` 即可联调使用。
