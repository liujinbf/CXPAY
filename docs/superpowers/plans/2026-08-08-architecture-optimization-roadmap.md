# CXPAY 架构优化实施路线图

## 总体策略

优化采用“风险优先、随改随拆”。每个里程碑必须形成可独立测试、审查、提交和回滚的交付，不允许用一次性全仓重构替代渐进治理。

详细设计见 `docs/superpowers/specs/2026-08-08-architecture-optimization-design.md`。

## 全局约束

- 保持现有 URL、请求参数、签名算法和响应 JSON 兼容。
- 资金操作使用定点小数、数据库事务、行锁和不可变流水。
- 权限、授权、签名和 TLS 校验失败时拒绝访问。
- 新功能和缺陷修复先写失败测试，再写生产代码。
- 每个里程碑完成后执行完整 PHPUnit、PHP 语法检查和 Composer 配置校验。
- 当前工作区已有修改属于用户，优化提交不得意外包含这些修改。
- 核心生产类原则上控制在约 300 行以内；超过时必须保持单一职责并说明原因。

## 里程碑与顺序

| 顺序 | 里程碑 | 主要交付 | 大文件治理 | 验收重点 |
|---|---|---|---|---|
| 1 | 订单创建与手续费可靠性 | 安全订单号、手续费来源拆分、原路冲正 | `OrderService` 提取通道路由、支付初始化、订单创建和关闭组件 | 多进程安全、资金不串账、接口兼容 |
| 2 | 结算与可靠通知 | 结算结果枚举、支付来源唯一约束、数据库 Outbox | `OrderService` 提取结算服务；`CallbillService` 提取匹配策略 | 重复账单不误匹配、Redis故障不丢通知 |
| 3 | 数据库迁移与安装 | 版本表、迁移执行器、完整初始化结构、缺失财务模型 | `InstallController` 拆为检查、连接、迁移、配置和编排服务 | 空库安装、升级幂等、最低 MySQL 版本兼容 |
| 4 | 云授权与插件市场安全 | 密钥脱敏、请求主体校验、关闭无支付发证、严格 TLS | `CloudLicenseController` 拆为站点信息、授权管理、插件市场控制器 | 公共接口无法泄密或免费发证 |
| 5 | 后台与商户控制器拆分 | 按业务用例拆控制器、统一异常映射 | 拆分 `AdminController`、`MerchantApiController` | 路由和响应契约保持不变 |
| 6 | 微信云监控模块化 | 路由入口与认证、订单、事件、审核、状态处理器分离 | 拆分 `CloudApplication` 及对应测试 | 协议、角色隔离和 Outbox 行为不变 |
| 7 | 部署与仓库治理 | Redis 会话、持久卷、插件平滑重启、制品清理 | 清理运行时和构建职责 | 容器重建不丢状态、仓库不携带制品 |

## 大文件拆分目标

### `app/service/OrderService.php`

最终保留为兼容门面，委托给：

- `CreateOrderService`
- `PaymentInitializationService`
- `OrderSettlementService`
- `CloseOrderService`
- `FeeReservationService`
- `ChannelRoutingService`
- `OrderNumberGenerator`

### `app/controller/admin/AdminController.php`

拆为：

- `AdminAuthController`
- `AdminDashboardController`
- `AdminMerchantController`
- `AdminChannelController`
- `AdminSecurityController`
- `SystemUpdateController`

### `app/controller/api/MerchantApiController.php`

拆为：

- `MerchantAuthController`
- `MerchantProfileController`
- `MerchantDashboardController`
- `MerchantPlanController`
- `MerchantAlertController`
- `MerchantNotifyController`

### `app/controller/api/InstallController.php`

保留薄控制器，委托给：

- `EnvironmentInspector`
- `DatabaseConnectionTester`
- `MigrationRunner`
- `EnvironmentFileWriter`
- `InstallationLock`
- `InstallApplicationService`

### `services/wx-monitor-cloud/src/CloudApplication.php`

保留 `handle()` 路由入口，委托给：

- `AuthSessionHandler`
- `CloudOrderHandler`
- `PaymentEventHandler`
- `ReviewEventHandler`
- `OperationsStatusHandler`

## 每个里程碑的质量门禁

1. 相关测试在生产修改前出现预期失败。
2. 目标测试通过。
3. 完整 PHPUnit 通过。
4. 所有生产和测试 PHP 文件语法检查通过。
5. `composer validate --strict` 通过。
6. `git diff --check` 无新增空白错误。
7. 只提交该里程碑明确列出的文件。
8. 更新路线图状态和后续风险说明。

## 计划文档索引

1. `docs/superpowers/plans/2026-08-08-order-creation-reliability.md`：订单创建、手续费可靠性及 `OrderService` 第一轮拆分。
2. 后续里程碑在前一里程碑验收后分别形成独立详细计划，文件名使用同一日期和对应领域名称。

