# 永久移除旧支付驱动：数据库清理操作手册

适用脚本：

`database/migrations/20260805_remove_legacy_payment_drivers.php`

永久移除：

- `alipay_official`
- `wxpay_official`
- `alipay_scan_bill`
- `wxpay_protocol_cloud`
- `qqpay_protocol_cloud`

## 安全边界

迁移会归档目标通道的非敏感元数据，并删除目标活动通道、轮询组关联及套餐中的精确旧驱动标识。

迁移不会删除或改写：

- `cx_order.channel_id`
- `cx_callbill.channel_id`
- 历史订单、账单状态及金额

归档表不得包含 `config`、Token、Cookie、私钥或其他密钥。

存在 `cx_order.status = 0` 的待支付订单引用目标通道时，清理必须停止。

## 1. 创建数据库备份

先在宝塔面板创建当前生产数据库的完整备份，并确认备份文件已经生成。

记录代码状态：

    cd /www/wwwroot/cs.fcwan.cn
    git rev-parse HEAD
    git status --short

必须保留 `.env`、`install.lock`、`CXPAY.rar` 和
`cxpay-webman.supervisor.conf`。

严禁执行：

    git clean -fd

## 2. 停止支付服务

    php start.php stop
    php start.php status

确认服务已经停止后再清理数据库。

## 3. 执行 dry-run

    php database/migrations/20260805_remove_legacy_payment_drivers.php

必须核对：

- `pending_orders=0`
- 每个通道 ID
- 每个 `merchant_id`
- 每个 `c_type`
- 每个通道标题

只有列出的驱动均属于本手册顶部五个标识，且数据库备份已经完成，才能继续。

## 4. 显式执行

    php database/migrations/20260805_remove_legacy_payment_drivers.php --apply

成功输出必须包含：

- `APPLY completed successfully`
- `remaining=0`

## 5. 验证幂等性

再次执行 dry-run：

    php database/migrations/20260805_remove_legacy_payment_drivers.php

预期输出：

- `channel_count=0`
- `poll_group_links=0`
- `plans_to_update=0`
- `pending_orders=0`

检查活动通道残留：

    SELECT id, merchant_id, c_type, title
    FROM cx_pay_channel
    WHERE c_type IN (
      'alipay_official',
      'wxpay_official',
      'alipay_scan_bill',
      'wxpay_protocol_cloud',
      'qqpay_protocol_cloud'
    );

查询结果必须为零行。

检查归档表结构：

    SHOW COLUMNS FROM cx_pay_channel_archive;

不得出现 `config`、`token`、`cookie`、`private_key`
或 `secret` 字段。

## 6. 运行测试

定向测试：

    php vendor/bin/phpunit --colors=never \
      tests/Integration/LegacyPaymentDriverCleanupServiceTest.php

完整测试：

    php vendor/bin/phpunit --colors=never

两组测试都必须通过。

## 7. 启动服务

    php start.php start -d
    php start.php status

确认 `CXPAY` 和 `channel_timer` 进程状态正常。

## 8. 浏览器验收

使用 `Ctrl+F5` 强制刷新并检查：

- 管理端平台通道列表
- 管理端新增通道表单
- 商户端通道列表与新增表单
- 套餐允许通道选择器
- 插件与驱动市场

五个旧驱动均不得显示。

创建一笔测试订单，确认路由不会选择已归档通道。

## 回退原则

本迁移不提供自动恢复旧驱动的脚本。

必须回退时：

1. 停止支付服务；
2. 恢复迁移前代码提交；
3. 恢复迁移前完整数据库备份；
4. 启动服务并重新验收。

不得只把归档数据复制回 `cx_pay_channel`。旧驱动源码已经删除，墓碑策略也会阻止同名驱动重新注册。
