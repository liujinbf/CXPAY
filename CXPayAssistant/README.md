# CXPAY 手机免挂监控助手 (Android App)

专为 **CXPAY 聚合支付系统** 定制的 Android 端个人收款码/店员免挂监控助手原生客户端。

---

## 核心特性

1. **无感通知监听**：
   - 基于 Android 原生 `NotificationListenerService`，无需 Root，低功耗稳定运行；
   - 自动识别并解析微信（`com.tencent.mm`）、微信收款助手、支付宝（`com.eg.android.AlipayGphone`）及 QQ 钱包到账通知。
2. **金融级安全上报**：
   - 参数字典升序规范化拼接；
   - 采用 **HMAC-SHA256** 摘要算法计算签名，内置防重放时间戳与唯一 Nonce；
   - 与 CXPAY 主站 `/api/appasst/push` 协议 100% 对齐。
3. **前台保活与智能心跳**：
   - 15 秒定时向主站发送通道心跳，主站后台实时显示“🟢 监控设备在线”。

---

## 编译与打包步骤

1. 使用 **Android Studio**（Hedgehog 或更高版本）打开 `CXPayAssistant` 目录；
2. 等待 Gradle 同步完成；
3. 点击菜单栏 **Build** -> **Build Bundle(s) / APK(s)** -> **Build APK(s)**；
4. 产物输出路径：`CXPayAssistant/app/build/outputs/apk/release/CXPayAssistant-v1.2.0.apk`。

---

## 手机端使用步骤

1. 安装 APK 到安卓手机；
2. 授予 **“通知使用权”**（在系统设置中允许 CXPAY 手机助手读取通知）；
3. 开启 **“允许后台运行 / 忽略电池优化”**；
4. 在 App 中输入：
   - **主站服务地址**：如 `https://cs.fcwan.cn`
   - **通道 ID**：主站后台对应的通道 ID（如 `1`）
   - **设备 ID**：如 `AND_DEVICE_01`
   - **上报密钥**：主站通道中设置的 HMAC 密钥
5. 点击 **“启动后台监控与保活服务”** 即可！
