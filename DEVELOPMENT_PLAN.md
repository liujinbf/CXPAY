# CXPAY 后续开发与优化计划文档

> 本文档基于 CXPAY 系统深度分析报告与项目实际架构（个人码免签直收模式）制定，涵盖系统安全加固、商户与运营功能增强、性能优化与容灾机制等三阶段开发计划。

---

## 阶段一：安全加固（当前进行中）

### 1. 管理员二次登录验证码（简化版）
- **目标**：为管理员登录增加两阶段验证（静态验证码 PIN），提高后台访问安全性。
- **改动说明**：
  - 密码验证成功后，若开启二次验证，返回 `code=2` 和临时 `pending_token`。
  - 前端提示输入二次验证码，请求 `/api/admin/login/verify` 完成登录并获取正式 Token。
  - 管理员后台可进行二次验证码开关及密钥配置。
- **涉及文件**：
  - `database/patch_v7.sql`（配置项）
  - `app/controller/admin/AdminController.php`
  - `app/middleware/AdminAuthMiddleware.php`
  - `config/route.php`

### 2. 下单接口商户级限频 (Rate Limiting)
- **目标**：防止商户 API 密钥泄露后被恶意流量高频刷单。
- **实现方案**：在 `OrderService::createOrder()` 中引入 Redis 滑动窗口限频，限制单商户每分钟最高下单次数（默认 30 次/分钟）。
- **涉及文件**：`app/service/OrderService.php`

### 3. 商户 IP 白名单下单强校验
- **目标**：激活 `cx_merchant.white_ip` 校验逻辑。
- **实现方案**：在 API 验签中间件 `ApiAuthMiddleware` 中，若配置了白名单 IP，对非白名单来源请求直接拦截返回 HTTP 403。
- **涉及文件**：`app/middleware/ApiAuthMiddleware.php`

### 4. Admin Token 版本号吊销机制
- **目标**：管理员修改密码或主动登出时，使已发行的历史 JWT/Token 立即失效。
- **实现方案**：Token 载荷中增加 `v{version}`，修改密码时递增 `admin_token_version` 配置，中间件校验时比对版本号。
- **涉及文件**：
  - `app/controller/admin/AdminController.php`
  - `app/middleware/AdminAuthMiddleware.php`

---

## 阶段二：功能完善与数据可视化

### 5. 交易统计报表与 ECharts 前端可视化图表
- **目标**：提供多维度数据统计、趋势折线图/柱状图及 CSV 数据导出能力。
- **实现方案**：
  - 后端提供按日/周/月交易笔数、交易额、成功率、通道分布的统计 API 及 CSV 下载接口。
  - 前端（商户后台 & 总控台）接入 **ECharts** 实现实时可视化图表展现。
- **涉及文件**：
  - `app/controller/admin/ReportController.php`（新建）
  - `app/controller/api/MerchantReportController.php`（新建）
  - 管理员与商户前端 HTML/JS 页面

### 6. 主备通道自动故障转移 (Fallback Channels)
- **目标**：通道掉线或触发风控时自动无感切换至备用通道。
- **实现方案**：
  - `cx_pay_channel` 表增加 `fallback_channel_id` 字段。
  - `OrderService::selectChannel()` 中，当主通道不可用时，自动拉取状态正常的备用通道接单。
- **涉及文件**：
  - `database/patch_v7.sql`
  - `app/service/OrderService.php`
  - `app/controller/admin/AdminController.php`

### 7. 按支付类型分阶/差异化费率 (Rate Config)
- **目标**：支持针对微信、支付宝、QQ 钱包等不同支付类型设置独立费率。
- **实现方案**：
  - `cx_merchant` 增加 `rate_config` JSON 字段。
  - 计算手续费时优先取对应支付类型的费率，未配置时回退至全局 `rate`。
- **涉及文件**：
  - `database/patch_v7.sql`
  - `app/service/OrderService.php`

### 8. 商户端主动重发异步通知接口
- **目标**：商户可在商户后台手动触发订单异步回调补发。
- **实现方案**：
  - 提供 `/api/merchant/order/resend_notify` 接口。
  - 增加防刷限频（如单订单每小时最多补发 3 次）。
- **涉及文件**：
  - `app/controller/api/MerchantApiController.php`
  - `config/route.php`

---

## 阶段三：运营能力与系统拓扑增强

### 9. 多角色后台权限管理 (RBAC)
- **目标**：拆分超级管理员、运营、财务、客服等不同角色权限。
- **涉及文件**：
  - `database/patch_v8.sql`（新建 `cx_admin` 表）
  - `app/middleware/AdminAuthMiddleware.php`

### 10. 标准化错误码与 Trace ID 链路追踪
- **目标**：定义全局 Unified Error Code 并注入 `request_id` 贯穿异步日志。
- **涉及文件**：
  - `support/ErrorCode.php`（新建）
  - `app/middleware/RequestIdMiddleware.php`（新建）

### 11. 核心业务链路自动化测试集扩展
- **目标**：对并发下单、日限额边界、重构后的账单匹配等场景增加 PHPUnit 测试用例。
- **涉及文件**：`tests/` 目录下相关单元与集成测试。

### 12. 沙箱测试环境 (Sandbox Environment)
- **目标**：提供免真实扣款的沙箱通道，方便商户快速联调对接。
- **涉及文件**：`app/payment/Drivers/Sandbox/Driver.php`（新建）

---

## 数据库补丁计划

| 版本 | 文件 | 作用 |
| --- | --- | --- |
| v7 | `database/patch_v7.sql` | 管理员二次验证配置、Token 版本号、备用通道 ID、商户分类型费率配置 |
| v8 | `database/patch_v8.sql` | 子管理员表 (RBAC) 与沙箱测试通道数据 |

---

## 计划执行路线

```
[Phase 1] 安全加固 (进行中) ──> [Phase 2] 报表/ECharts/主备切换 ──> [Phase 3] RBAC/沙箱/链路追踪
```
