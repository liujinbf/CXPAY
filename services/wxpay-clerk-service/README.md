# 微信云端店员免挂服务（wxpay-clerk-service）

个人微信号作为收款店员的云端托管接入服务，与 CXPAY `wxpay-clerk-adapter` 插件配合使用。

## 工作原理

```
商户 → 将服务商微信号添加为【收款店员】
        ↓ 微信官方推送
gewe 容器（iPad 协议）
        ↓ Webhook
本服务（wxpay-clerk-service）
  ├── 解析到账通知消息
  ├── 匹配 CXPAY 已登记订单
  └── HMAC 签名回调 → CXPAY → 自动结算
```

## 快速部署

### 前置条件

- Docker + Docker Compose
- 一个公网域名（用于 HTTPS，gewe Webhook 和 CXPAY 回调必须 HTTPS）
- 一个专用个人微信号（建议注册时间 > 3 个月的微信号）

### 1. 克隆并配置

```bash
cd services/wxpay-clerk-service
cp config.example.php config.php
vim config.php  # 填写以下必填项：
                # client_id / client_secret / callback_secret
                # base_url（本服务对外地址）
                # cxpay_notify_url（CXPAY 实例地址）
```

### 2. 安装依赖

```bash
composer install --no-dev -o
```

### 3. 启动服务

```bash
cp docker-compose.example.yml docker-compose.yml
vim docker-compose.yml  # 修改域名等配置
docker-compose up -d
```

### 4. 绑定微信店员账号

在 CXPAY 后台打开通道配置，点击「微信添加店员绑定」，用手机微信扫码完成登录。

登录成功后，account_id 将自动写入通道配置。

### 5. 商户操作

商户进入 **微信 → 我 → 支付 → 收款码 → 收款小账本 → 店员管理**，添加您的服务商微信号为收款店员。

完成后，所有向该收款码的付款将同时通知到店员账号，触发自动结算。

---

## 目录结构

```
├── index.php                         服务入口路由
├── config.example.php                配置模板
├── composer.json                     PHP 依赖
├── docker-compose.example.yml        Docker 部署参考
└── src/
    ├── SignatureHelper.php            HMAC-SHA256 双向签名
    ├── OrderStore.php                 SQLite 数据层（订单/审核/账号/会话）
    ├── PaymentNotificationParser.php  解析微信到账通知消息
    ├── OrderMatcher.php               四级优先匹配策略
    ├── CxpayCallback.php              带签名的 CXPAY 回调发送
    ├── GeweApiClient.php              gewe HTTP API 客户端
    ├── AuthSessionManager.php         微信登录会话管理
    ├── WechatWebhookHandler.php       gewe Webhook 分发处理
    └── ApiServer.php                  CXPAY 插件 API 服务端
```

---

## API 端点

| 方法 | 路径 | 调用方 | 说明 |
|------|------|--------|------|
| GET | `/health` | 监控 | 无需签名健康检查 |
| POST | `/v1/orders` | CXPAY | 登记待匹配订单 |
| POST | `/v1/auth-sessions` | CXPAY | 创建微信登录会话 |
| GET | `/v1/auth-sessions/{id}` | CXPAY | 轮询登录/绑定状态 |
| GET | `/v1/accounts/{id}/capabilities` | CXPAY | 账号在线能力检测 |
| GET | `/v1/ops/status` | CXPAY | 全局运维状态 |
| GET | `/v1/review/events` | CXPAY | 待人工审核事件 |
| POST | `/v1/review/events/{id}/match` | CXPAY | 手动关联到账 |
| POST | `/v1/review/events/{id}/ignore` | CXPAY | 忽略审核事件 |
| POST | `/wechat/message` | gewe（内部） | 微信消息 Webhook |

---

## 订单匹配策略

到账通知到达后按以下优先级匹配：

1. **备注直接命中**：备注内容为 `out_trade_no` 格式 → 100% 置信度自动结算
2. **金额唯一命中**：该账号下同金额 PENDING 订单唯一 → 自动结算
3. **金额多笔歧义**：配置 `auto_review_on_ambiguous=true` → 进入人工审核
4. **无匹配订单** → 创建审核事件，可在 CXPAY 后台手动关联

---

## 安全注意事项

- `config.php` 中的 `gewe_allowed_ips` 务必设置为 gewe 容器 IP，防止外部伪造到账通知
- CXPAY API 端点建议设置 `allowed_ips` 白名单
- 所有对外端点必须通过 HTTPS，防止 HMAC 签名被截获
