# 删除占位与旧共享 Token 支付驱动设计

日期：2026-08-05  
目标分支：`fix/p0-hardening`

## 1. 目标

从支付通道体系中彻底移除不具备真实生产能力的官方占位驱动，以及安全模型已经淘汰的旧共享 Token 驱动，使其不再被自动发现、接口调用、套餐绑定或管理端/商户端展示。

本次同时清理数据库中的活动通道记录。为保留审计能力，删除前将非敏感元数据写入只读归档表。

## 2. 删除范围

永久移除以下驱动标识：

- `alipay_official`
- `wxpay_official`
- `alipay_scan_bill`
- `wxpay_protocol_cloud`
- `qqpay_protocol_cloud`

物理删除对应目录：

- `app/payment/Drivers/AlipayOfficial/`
- `app/payment/Drivers/WxpayOfficial/`
- `app/payment/Drivers/AlipayScanBill/`
- `app/payment/Drivers/WxpayProtocolCloud/`
- `app/payment/Drivers/QqpayProtocolCloud/`

`sandbox_test` 不在本次范围内。它继续作为内部测试驱动存在，但不得进入生产支付分类或真实流量。

## 3. 核心驱动墓碑策略

仅删除目录不足以防止未来误恢复同名驱动。因此 `PaymentManager` 增加永久移除名单。

对名单中的标识：

- `has()` 恒为 `false`；
- `make()` 抛出明确的“支付驱动已永久移除”异常；
- `register()` 和 `registerPluginDriver()` 拒绝注册同名内置或插件驱动；
- `discoverDrivers()` 忽略同名目录；
- `getRegisteredDrivers()` 永不返回这些标识。

该名单是兼容性墓碑，不包含任何驱动实现代码。

## 4. 数据库归档与删除

### 4.1 归档表

创建 `cx_pay_channel_archive`，用于保存被删除活动通道的非敏感元数据。

字段至少包括：

- `archive_id`：归档表主键；
- `original_channel_id`：原 `cx_pay_channel.id`，唯一；
- `merchant_id`；
- `pay_category`；
- `title`；
- `c_type`；
- `remark`；
- `weight`；
- `single_min`；
- `single_max`；
- `day_max`；
- `today_money`；
- `today_count`；
- `total_money`；
- `online_status`；
- `status`；
- `archive_reason`；
- `archived_at`。

归档表不保存 `config`。旧 Token、Cookie、私钥、商户密钥等敏感配置不应继续长期保留。

### 4.2 迁移顺序

迁移在事务中执行：

1. 创建归档表；
2. 查询五类待删除活动通道；
3. 按 `original_channel_id` 幂等写入归档表；
4. 删除 `cx_poll_group_channel` 中对应 `channel_id` 的关联；
5. 删除 `cx_pay_channel` 中对应活动通道；
6. 清理套餐 `allowed_channels` 或等价字段中的五个驱动标识；
7. 输出归档数、关联删除数、活动通道删除数及残留数；
8. 任一步失败则事务回滚。

迁移必须可重复执行。第二次执行不得新增重复归档，也不得报错。

### 4.3 历史引用

不删除或改写：

- `cx_order.channel_id`；
- `cx_callbill.channel_id`；
- 其他历史交易或审计表中的原通道 ID。

历史记录通过 `cx_pay_channel_archive.original_channel_id` 追溯原通道。活动路由只读取 `cx_pay_channel`，归档记录不会重新参与轮询或支付。

## 5. 展示与接口行为

清理后，以下位置均不得出现五个驱动：

- 管理端平台通道列表与新增通道表单；
- 商户端通道列表与新增通道表单；
- 套餐允许通道选择器；
- 插件/驱动市场中由内置驱动生成的候选项；
- 任意返回 `PaymentManager::getRegisteredDrivers()` 的 API。

手工构造请求提交这些 `c_type` 时，接口应明确拒绝，不得降级为其他驱动。

## 6. 错误处理与部署安全

- 迁移执行前必须备份数据库；
- 迁移脚本先输出待归档通道数量和 ID，确认后再执行写入；
- 事务失败时保留原活动通道，不允许部分删除；
- 删除后若历史页面需要显示通道名称，应优先查询活动表，未命中时查询归档表；
- 不提供自动恢复旧驱动的回滚脚本。需要回退时只能恢复代码与数据库备份，以避免重新启用不安全通道。

## 7. 测试要求

### 7.1 驱动层

- 五个驱动的 `has()` 均为 `false`；
- 五个驱动的 `make()` 均抛出永久移除异常；
- 同名插件注册被拒绝；
- 其余可用驱动仍能发现和实例化；
- `sandbox_test` 保持原有测试用途。

### 7.2 列表与配置层

- 注册驱动列表不包含五个标识；
- 管理端、商户端和套餐候选列表不包含五个标识；
- 保存接口拒绝五个标识；
- 旧套餐中的五个标识经迁移后被清除，其他允许通道不变。

### 7.3 数据迁移层

- 首次执行归档并删除目标通道；
- 归档表不包含 `config`；
- 轮询组关联被删除；
- 历史订单和账单数量、状态及 `channel_id` 不变；
- 第二次执行保持幂等；
- 活动表目标通道残留数为零。

### 7.4 全量回归

- PHP 语法检查通过；
- 定向 PHPUnit 通过；
- 完整 PHPUnit 通过；
- 测试服务器页面确认五个驱动不再显示；
- 创建订单时不会选择已删除通道。

## 8. 成功标准

实施完成后：

1. 仓库中不再存在五个驱动实现目录；
2. 运行时不能注册、发现或实例化五个驱动；
3. 所有支付通道和套餐配置界面不再显示它们；
4. 活动数据库中对应通道和轮询组关联已物理删除；
5. 非敏感通道元数据已归档，历史订单与账单保持不变；
6. 迁移可重复执行；
7. 完整测试套件通过。
