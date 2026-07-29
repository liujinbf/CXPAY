# CXPAY 开发说明

## 主要调用链

```text
/submit.php
  -> SubmitController
  -> OrderService（验签、幂等、选通道、建单）
  -> PaymentManager / PaymentDriverInterface
  -> 个人收款码收银台

/api/appasst/push
  -> AppasstController（HMAC、设备、时间戳、nonce 校验）
  -> CallbillService（来源账单幂等、通道金额与支付窗口匹配）
  -> OrderService::markAsPaid（事务结算）
  -> MerchantNotifyService（Redis 重试队列）

/api/bill-source/ingest
  -> BillSourceController（独立写入令牌、采集端ID、支付类型、IP白名单校验）
  -> cx_bill_source_event（channel_id + source_bill_id 幂等暂存）
  -> /api/bill-source/poll（PC按设备绑定和单调游标拉取）
  -> /api/appasst/push（PC使用独立HMAC密钥完成最终上报）

/notify/{cType}
  -> NotifyController（仅供已配置的外部账单服务回调）
  -> 驱动 notify（共享凭据校验与账单解析）
  -> OrderService::markAsPaid
```

## 分层

- `app/controller`：HTTP 参数与响应，不承载余额结算。
- `app/service`：订单、账单匹配、通知、风控和巡检。
- `app/payment`：支付驱动接口、驱动发现与各渠道实现。
- `app/model`：Eloquent 模型，表名均显式带 `cx_` 前缀。
- `config`：Webman、数据库、Redis、Session、路由和进程配置。
- `database`：全新安装 SQL 与升级补丁。

## 驱动约定

驱动位于 `app/payment/Drivers/{StudlyName}/Driver.php`，实现 `PaymentDriverInterface`。个人码监控驱动还应实现 `MonitorableDriverInterface`，明确声明 `push`、`callback`、`server` 或 `none`，禁止根据驱动名称猜测能力。`getMeta()` 至少返回 `name`、`title`、`inputs`、`pay_category` 和 `collection_mode`；非个人收款码驱动必须设置 `available => false`，避免被调度。

- `upchannel()` 必须验证所有必需配置和 URL/枚举范围，失败返回 `['code' => -1, 'msg' => '...']`。
- `pay()` 必须返回真实配置的个人收款码内容，禁止使用看似可用的默认二维码或占位链接。
- `notify()` 必须完成渠道要求的签名验证，返回平台订单号、渠道流水号和实际到账金额；不得在缺少密钥时降级放行。
- `query()` 未实现时应明确返回 `paid => false`，不能伪造成功。

## 关键不变量

- 同一商户的 `out_trade_no` 唯一。
- 回调通道必须与订单绑定通道一致，到账金额必须与 `price` 精确到分一致。
- 助手账单的 `pay_type` 必须参与签名并与通道支付分类一致，禁止跨钱包类型核销。
- 助手账单必须携带稳定的来源账单ID；同一通道的同一来源账单只能落库一次。
- 账单源写入令牌、PC拉取令牌和最终核销HMAC密钥必须相互隔离，任何接口均不得返回已存储密钥的明文。
- 账单发生时间必须落在订单有效支付窗口内；延迟或多候选账单只能进入人工复核。
- 订单识别金额在订单结束后保留冷却期，禁止立即分配给新订单。
- 新订单创建时预占手续费，支付成功时核销，超时或人工关闭时仅释放一次；旧订单继续兼容支付时扣费。
- 同一订单同时只能有一个有效的支付出码初始化请求；陈旧认领可以恢复，但旧请求不得覆盖新认领结果。
- 订单状态、商户余额、余额流水和通道统计在同一数据库事务中更新。
- 商户通知只在事务提交后加入重试队列。
- 商户通道 CRUD 必须从 Session 上下文取得商户身份，不能相信请求中的 `merchant_id` 或 `pid`。
- 配置密文使用 `APP_KEY` 派生的 AES-256-GCM；修改 `APP_KEY` 会导致既有 v2 密文无法解密。

## 自动化测试

当前 PHPUnit 已覆盖签名、配置加密、IP 白名单、回调 URL 防护、驱动可用性，以及基于 SQLite 的订单手续费预占、幂等创建、支付核销、超时释放和支付初始化认领。上线前仍应在 MySQL/Redis 测试环境补充真实并发下单、重复回调、通知重试、Redis 故障关闭、商户越权和数据库补丁升级验证。
