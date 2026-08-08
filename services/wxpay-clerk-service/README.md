# 微信店员到账服务

`wxpay-clerk-service` 通过个人微信店员账号与 Gewe/iPad 协议接收到账消息，并与 CXPAY 的 `wxpay-clerk-adapter` 配合完成订单核销。

这不是微信官方商户支付接口。店员账号仍可能遇到平台风控、协议变化、消息格式变化或第三方服务中断；上线后必须保留人工复核和小额灰度策略。

## 可靠性流程

```text
Gewe Webhook
  → 路径令牌 + 来源 IP 校验
  → 到账事件幂等持久化
  → 事务内匹配订单、写复核审计和 callback_outbox
  → 独立 outbox-worker 租约领取、指数退避
  → CXPAY 插件验签并统一结算
```

Webhook 请求不再同步调用 CXPAY。回调暂时失败时，`GET /v1/orders/{out_trade_no}` 仍可返回可信的 `paid=true`，供主站主动查询恢复。

## 部署

1. 复制配置和 Compose 文件。

   ```bash
   cp config.example.php config.php
   cp docker-compose.example.yml docker-compose.yml
   ```

2. 创建 `.env`，至少设置：

   ```dotenv
   WXCLERK_CLIENT_ID=client-id
   WXCLERK_CLIENT_SECRET=至少32位随机密钥
   WXCLERK_CALLBACK_SECRET=至少32位独立随机密钥
   WXCLERK_WEBHOOK_TOKEN=至少32位不可猜测随机令牌
   WXCLERK_BASE_URL=https://clerk.example.com
   CXPAY_NOTIFY_URL=https://pay.example.com
   WXCLERK_GEWE_ALLOWED_IPS=gewe容器实际来源IP或CIDR
   ```

3. 配置 Nginx TLS，公网只开放 HTTPS 443。Gewe 应通过 Docker 内网访问 `/wechat/message/{token}`，不要把 Gewe 管理端口暴露到公网。

4. 启动并检查两个进程。

   ```bash
   docker compose up -d --build
   docker compose ps
   docker compose logs wxpay-clerk-service outbox-worker
   ```

Web 服务与 `outbox-worker` 必须共享同一个 SQLite 数据卷。不得只启动 Web 服务，否则到账可以匹配但回调不会投递。

## API

除健康检查与 Gewe Webhook 外，所有 `/v1` 请求都使用 HMAC、300 秒时间窗和持久化 nonce 防重放。

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/health` | 健康检查 |
| POST | `/v1/orders` | 幂等登记订单，成功返回 `accepted=true` |
| GET | `/v1/orders/{out_trade_no}` | 查询可信支付和回调状态 |
| POST | `/v1/auth-sessions` | 创建店员账号登录会话 |
| GET | `/v1/auth-sessions/{id}` | 查询登录会话 |
| GET | `/v1/accounts/{id}/capabilities` | 查询账号在线能力 |
| GET | `/v1/review/events` | 查询待复核到账 |
| POST | `/v1/review/events/{event_id}/match` | 人工匹配并创建可靠回调 |
| POST | `/v1/review/events/{event_id}/ignore` | 带原因忽略到账 |
| GET | `/v1/ops/status` | 账号和发件箱运维状态 |
| POST | `/wechat/message/{token}` | Gewe 内网 Webhook |

## 匹配规则

- 备注命中前仍验证账号、金额、订单状态、创建时间和有效期。
- 同账号同金额只有一个候选时自动匹配。
- 多个候选始终进入人工复核，不存在“自动取最早订单”配置。
- 缺少稳定微信消息号的事件使用原始摘要幂等，但绝不自动核销。
- 自动匹配和人工匹配都在同一事务内更新事件、订单、审计和发件箱。

## 运维与恢复

- 监控 `/v1/ops/status` 的 `outbox.pending_count`、`failed_count`、`oldest_pending_at` 和 `last_error`。
- `FAILED` 表示已达到最大投递次数，应先排查 CXPAY HTTPS、证书、插件密钥和响应正文，再由受控运维流程重新排队。
- 定期备份 `storage/clerk.sqlite`。建议使用 SQLite 在线备份或短暂停写后复制数据库及 WAL/SHM，恢复演练必须在测试环境完成。
- 发布前导入历史数据库验证迁移；先做小额灰度，观察账号在线率、未匹配事件和回调积压后再扩大使用。
- 日志不得记录 Cookie、密钥、完整原始微信消息或数据库连接信息。
