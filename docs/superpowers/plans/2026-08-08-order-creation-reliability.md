# 订单创建与手续费可靠性实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 消除多进程订单号碰撞和手续费优惠额度转现金问题，并将 `OrderService` 的订单创建、通道路由、支付初始化及关闭职责拆为可独立测试的组件。

**Architecture:** 保留 `OrderService` 作为兼容门面，新增 `app\service\order` 命名空间下的应用服务。先用行为测试锁定外部契约，再引入安全订单号和手续费来源字段，最后逐步把现有方法体移动到单一职责组件中。

**Tech Stack:** PHP 8.1+、Webman 2、Workerman、Eloquent、MySQL/SQLite、Redis、PHPUnit 10。

## Global Constraints

- 保持 `OrderService::createOrder()`、`closePendingOrder()`、`expirePendingOrders()` 的公共签名和返回结构不变。
- 保持易支付协议字段、商户签名算法、支付驱动参数和现有错误消息兼容。
- 所有资金计算使用两位定点小数字符串和 BCMath。
- 所有生产改动先由失败测试证明，再实现最小代码。
- 当前工作区已有修改不得进入本计划的提交。
- 新增数据库字段必须同时进入版本迁移和完整初始化结构。
- 第一轮完成后 `OrderService` 只保留结算、通知兼容入口和门面委托，目标不超过 400 行；第二里程碑提取结算后降至约 150 行。

---

## 文件结构

### 新增生产文件

- `app/service/order/OrderNumberGenerator.php`：生成不可预测、跨进程安全的交易号。
- `app/service/order/FeeReservation.php`：手续费预留值对象。
- `app/service/order/FeeReservationService.php`：计算、预留、确认和释放手续费来源。
- `app/service/order/ChannelRoutingService.php`：选择通道并校验驱动可用性。
- `app/service/order/PaymentInitializationService.php`：领取初始化权并调用支付驱动。
- `app/service/order/CreateOrderService.php`：订单创建用例。
- `app/service/order/CloseOrderService.php`：订单关闭和手续费冲正用例。

### 修改生产文件

- `app/service/OrderService.php`：兼容门面及尚未提取的结算入口。
- `database/install.sql`：增加手续费来源字段。
- `database/patch_v11.sql`：为升级数据库增加手续费来源字段和索引。
- `app/model/Order.php`：增加金额和状态字段转换。

### 新增或拆分测试文件

- `tests/Unit/OrderNumberGeneratorTest.php`
- `tests/Unit/FeeReservationServiceTest.php`
- `tests/Integration/OrderCreationServiceTest.php`
- `tests/Integration/OrderClosingServiceTest.php`
- `tests/Integration/PaymentInitializationServiceTest.php`
- `tests/Support/OrderDatabaseTestCase.php`

### 测试基础设施

- `tests/bootstrap.php`：在 Composer 自动加载器上注册 `Tests\` 命名空间。
- `phpunit.xml`：使用测试专用引导文件。
- `tests/Integration/OrderFeeReservationTest.php`：迁移到新的测试基类并在拆分完成后删除已迁移用例。

---

### Task 1: 建立订单测试基类并拆分现有超大测试文件

**Files:**

- Create: `tests/Support/OrderDatabaseTestCase.php`
- Create: `tests/Integration/OrderCreationServiceTest.php`
- Create: `tests/Integration/OrderClosingServiceTest.php`
- Create: `tests/Integration/PaymentInitializationServiceTest.php`
- Create: `tests/bootstrap.php`
- Modify: `phpunit.xml`
- Modify: `tests/Integration/OrderFeeReservationTest.php`

**Interfaces:**

- Produces: `Tests\Support\OrderDatabaseTestCase`，提供 `merchant()`、`channel()`、`order()` 和每测试重建 SQLite 表结构的能力。
- Consumes: Eloquent `Capsule`、现有测试支付驱动注册逻辑。

- [ ] **Step 1: 记录拆分前测试基线**

Run:

```powershell
php vendor/bin/phpunit tests/Integration/OrderFeeReservationTest.php
```

Expected: 现有订单集成测试全部通过，并记录测试数和断言数。

- [ ] **Step 2: 为测试命名空间增加专用引导文件**

创建 `tests/bootstrap.php`，加载 `vendor/autoload.php` 后调用 Composer `ClassLoader::addPsr4('Tests\\', __DIR__ . '/')`；将 `phpunit.xml` 的 `bootstrap` 改为 `tests/bootstrap.php`。

Run:

```powershell
php vendor/bin/phpunit --list-tests
```

Expected: PHPUnit 能发现原测试和拆分后的测试，且不修改 `vendor` 生成文件。

- [ ] **Step 3: 提取共享测试基类**

创建以下基类接口，表结构和测试驱动注册内容从现有测试原样迁移：

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use app\model\Channel;
use app\model\Merchant;
use app\model\Order;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

abstract class OrderDatabaseTestCase extends TestCase
{
    protected static Capsule $capsule;

    public static function setUpBeforeClass(): void
    {
        self::$capsule = new Capsule();
        self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();
    }

    protected function merchant(string $money, array $overrides = []): Merchant
    {
        return Merchant::create(array_merge([
            'pid' => '10001',
            'name' => '测试商户',
            'key' => 'test-key',
            'money' => $money,
            'rate' => '0.0100',
            'plan_id' => 1,
            'plan_expire_time' => time() + 86400,
            'plan_fee_discount_balance' => '0.00',
            'status' => 1,
        ], $overrides));
    }

    protected function channel(array $overrides = []): Channel
    {
        return Channel::create(array_merge([
            'merchant_id' => 0,
            'pay_category' => 'alipay',
            'title' => '测试通道',
            'c_type' => 'order_fee_test',
            'config' => '{}',
            'online_status' => 1,
            'status' => 1,
        ], $overrides));
    }

    protected function order(Merchant $merchant, Channel $channel, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'merchant_id' => $merchant->id,
            'out_trade_no' => 'OUT-1',
            'trade_no' => 'CX-1',
            'channel_id' => $channel->id,
            'pay_type' => 'alipay',
            'business_type' => 'payment',
            'fee_amount' => '1.00',
            'fee_status' => 1,
            'amount' => '100.00',
            'price' => '100.00',
            'status' => 0,
            'create_time' => time(),
            'expire_time' => time() + 180,
        ], $overrides));
    }
}
```

在这个类中，将现有 `OrderFeeReservationTest::setUp()` 第33至189行完整迁移为具体的 `setUp()`：表删除与建表语句保持原顺序，支付测试驱动的 `PaymentManager::register()` 保持原实现。将现有第569行之后的 `merchant()`、`channel()` 和 `order()` 辅助方法完整迁移到基类，并仅增加上面展示的 `$overrides` 参数合并能力。基类不得声明需要子类补全的抽象方法。

- [ ] **Step 4: 按业务用例移动测试**

- 创建订单、幂等、余额不足测试移入 `OrderCreationServiceTest`。
- 关闭、重复关闭、批量过期测试移入 `OrderClosingServiceTest`。
- 初始化领取、过期领取和所有权测试移入 `PaymentInitializationServiceTest`。
- 结算和账单匹配测试暂时保留在 `OrderFeeReservationTest`，供第二里程碑继续拆分。

所有新测试类继承 `OrderDatabaseTestCase`，测试方法体和断言不改变。

- [ ] **Step 5: 验证拆分没有改变测试行为**

Run:

```powershell
php vendor/bin/phpunit tests/Integration/OrderCreationServiceTest.php tests/Integration/OrderClosingServiceTest.php tests/Integration/PaymentInitializationServiceTest.php tests/Integration/OrderFeeReservationTest.php
```

Expected: 测试数和断言语义与拆分前一致，0 failure，0 error。

- [ ] **Step 6: 提交测试结构调整**

```powershell
git add phpunit.xml tests/bootstrap.php tests/Support/OrderDatabaseTestCase.php tests/Integration/OrderCreationServiceTest.php tests/Integration/OrderClosingServiceTest.php tests/Integration/PaymentInitializationServiceTest.php tests/Integration/OrderFeeReservationTest.php docs/superpowers/plans/2026-08-08-order-creation-reliability.md
git commit -m "test: split order lifecycle integration tests"
```

---

### Task 2: 替换多进程不安全的订单号生成器

**Files:**

- Create: `app/service/order/OrderNumberGenerator.php`
- Create: `tests/Unit/OrderNumberGeneratorTest.php`
- Modify: `app/service/OrderService.php:17,33-39,135`
- Modify: `tests/Integration/OrderCreationServiceTest.php`

**Interfaces:**

- Produces: `OrderNumberGenerator::generate(): string`，返回以 `CX` 开头、总长度 36 的交易号。
- Consumes: PHP `random_bytes()` 和 UTC 时间。

- [ ] **Step 1: 写订单号行为失败测试**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\service\order\OrderNumberGenerator;
use PHPUnit\Framework\TestCase;

final class OrderNumberGeneratorTest extends TestCase
{
    public function testGeneratesOpaqueUniqueOrderNumbersAcrossFreshInstances(): void
    {
        if (!class_exists(OrderNumberGenerator::class)) {
            self::fail('安全订单号生成器尚未实现');
        }

        $ids = [];
        for ($i = 0; $i < 2000; $i++) {
            $id = (new OrderNumberGenerator())->generate();
            self::assertMatchesRegularExpression('/^CX\d{14}[A-F0-9]{20}$/', $id);
            $ids[$id] = true;
        }

        self::assertCount(2000, $ids);
    }
}
```

- [ ] **Step 2: 运行测试并确认预期失败**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/OrderNumberGeneratorTest.php
```

Expected: FAIL，消息包含“安全订单号生成器尚未实现”。

- [ ] **Step 3: 实现最小安全生成器**

```php
<?php

declare(strict_types=1);

namespace app\service\order;

final class OrderNumberGenerator
{
    public function generate(): string
    {
        return 'CX' . gmdate('YmdHis') . strtoupper(bin2hex(random_bytes(10)));
    }
}
```

- [ ] **Step 4: 将生成器注入兼容门面**

`OrderService` 构造器保持无参数可用，并增加可测试注入点：

```php
private OrderNumberGenerator $orderNumberGenerator;

public function __construct(?OrderNumberGenerator $orderNumberGenerator = null)
{
    $this->orderNumberGenerator = $orderNumberGenerator ?? new OrderNumberGenerator();
    $this->notifyService = new MerchantNotifyService();
    $this->riskGuard = new RiskGuardService();
    $this->pollService = new PollService();
}
```

将：

```php
$tradeNo = 'CX' . SnowFlake::makeId();
```

替换为：

```php
$tradeNo = $this->orderNumberGenerator->generate();
```

删除 `use support\SnowFlake;`，但本任务不删除 `support/SnowFlake.php`，以免破坏未知外部调用；在全仓引用确认归零后由仓库治理任务删除。

- [ ] **Step 5: 验证单元和创建订单行为**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/OrderNumberGeneratorTest.php tests/Integration/OrderCreationServiceTest.php
```

Expected: 全部通过；创建订单返回的 `trade_no` 匹配新格式，幂等重试返回同一个订单号。

- [ ] **Step 6: 提交订单号修复**

```powershell
git add app/service/order/OrderNumberGenerator.php app/service/OrderService.php tests/Unit/OrderNumberGeneratorTest.php tests/Integration/OrderCreationServiceTest.php
git commit -m "fix: generate process-safe order numbers"
```

---

### Task 3: 记录手续费现金与优惠额度来源

**Files:**

- Create: `app/service/order/FeeReservation.php`
- Create: `app/service/order/FeeReservationService.php`
- Create: `tests/Unit/FeeReservationServiceTest.php`
- Create: `database/patch_v11.sql`
- Modify: `database/install.sql`
- Modify: `app/model/Order.php`
- Modify: `tests/Support/OrderDatabaseTestCase.php`

**Interfaces:**

- Produces: `FeeReservationService::allocate(string $fee, string $cashBalance, string $discountBalance): FeeReservation`。
- Produces: `FeeReservation`，包含 `fee`、`cash`、`discount` 三个规范化金额字符串。
- Consumes: BCMath。

- [ ] **Step 1: 写手续费分配失败测试**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\service\order\FeeReservationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FeeReservationServiceTest extends TestCase
{
    public function testUsesDiscountBeforeCash(): void
    {
        $reservation = $this->service()->allocate('3.00', '10.00', '1.25');

        self::assertSame('3.00', $reservation->fee);
        self::assertSame('1.75', $reservation->cash);
        self::assertSame('1.25', $reservation->discount);
    }

    public function testUsesOnlyDiscountWhenDiscountCoversFee(): void
    {
        $reservation = $this->service()->allocate('1.00', '10.00', '5.00');

        self::assertSame('0.00', $reservation->cash);
        self::assertSame('1.00', $reservation->discount);
    }

    public function testRejectsInsufficientCombinedBalance(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('商户可用余额不足');

        $this->service()->allocate('3.00', '1.00', '1.50');
    }

    private function service(): FeeReservationService
    {
        if (!class_exists(FeeReservationService::class)) {
            self::fail('手续费预留服务尚未实现');
        }

        return new FeeReservationService();
    }
}
```

- [ ] **Step 2: 运行测试并确认预期失败**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/FeeReservationServiceTest.php
```

Expected: FAIL，消息包含“手续费预留服务尚未实现”。

- [ ] **Step 3: 实现值对象和分配算法**

```php
<?php

declare(strict_types=1);

namespace app\service\order;

final class FeeReservation
{
    public function __construct(
        public readonly string $fee,
        public readonly string $cash,
        public readonly string $discount,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace app\service\order;

use RuntimeException;

final class FeeReservationService
{
    public function allocate(string $fee, string $cashBalance, string $discountBalance): FeeReservation
    {
        $fee = $this->money($fee);
        $cashBalance = $this->money($cashBalance);
        $discountBalance = $this->money($discountBalance);

        if (bccomp(bcadd($cashBalance, $discountBalance, 2), $fee, 2) < 0) {
            throw new RuntimeException(
                "商户可用余额不足（需手续费 ¥{$fee}，充值余额 ¥{$cashBalance}，套餐抵扣金 ¥{$discountBalance}）"
            );
        }

        $discount = bccomp($discountBalance, $fee, 2) >= 0 ? $fee : $discountBalance;
        $cash = bcsub($fee, $discount, 2);

        return new FeeReservation($fee, $cash, $discount);
    }

    private function money(mixed $amount): string
    {
        return is_numeric($amount) ? bcadd((string)$amount, '0.00', 2) : '0.00';
    }
}
```

- [ ] **Step 4: 增加数据库字段**

`cx_order` 增加：

```sql
`fee_reserved_cash` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '从现金余额预留的手续费',
`fee_reserved_discount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '从套餐优惠额度预留的手续费',
`fee_reservation_status` varchar(16) NOT NULL DEFAULT 'legacy' COMMENT 'legacy/reserved/consumed/released',
```

`database/patch_v11.sql` 使用项目最低 MySQL 版本兼容的条件迁移，禁止直接使用无法保证兼容性的 `ADD COLUMN IF NOT EXISTS`：

```sql
DELIMITER //
CREATE PROCEDURE `cxpay_patch_v11`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'cx_order' AND column_name = 'fee_reserved_cash'
    ) THEN
        ALTER TABLE `cx_order`
            ADD COLUMN `fee_reserved_cash` decimal(10,2) NOT NULL DEFAULT '0.00'
            COMMENT '从现金余额预留的手续费' AFTER `fee_amount`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'cx_order' AND column_name = 'fee_reserved_discount'
    ) THEN
        ALTER TABLE `cx_order`
            ADD COLUMN `fee_reserved_discount` decimal(10,2) NOT NULL DEFAULT '0.00'
            COMMENT '从套餐优惠额度预留的手续费' AFTER `fee_reserved_cash`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'cx_order' AND column_name = 'fee_reservation_status'
    ) THEN
        ALTER TABLE `cx_order`
            ADD COLUMN `fee_reservation_status` varchar(16) NOT NULL DEFAULT 'legacy'
            COMMENT 'legacy/reserved/consumed/released' AFTER `fee_reserved_discount`;
    END IF;
END//
DELIMITER ;

CALL `cxpay_patch_v11`();
DROP PROCEDURE `cxpay_patch_v11`;
```

`Order` 增加：

```php
protected $casts = [
    'fee_amount' => 'decimal:2',
    'fee_reserved_cash' => 'decimal:2',
    'fee_reserved_discount' => 'decimal:2',
];
```

- [ ] **Step 5: 更新 SQLite 测试结构并运行测试**

在订单测试表中增加相同三个字段，随后运行：

```powershell
php vendor/bin/phpunit tests/Unit/FeeReservationServiceTest.php tests/Integration/OrderCreationServiceTest.php tests/Integration/OrderClosingServiceTest.php
```

Expected: 新单元测试通过，现有集成测试保持通过。

- [ ] **Step 6: 提交手续费模型和迁移**

```powershell
git add app/service/order/FeeReservation.php app/service/order/FeeReservationService.php app/model/Order.php database/install.sql database/patch_v11.sql tests/Unit/FeeReservationServiceTest.php tests/Support/OrderDatabaseTestCase.php
git commit -m "feat: track fee reservation sources"
```

---

### Task 4: 在创建和关闭订单时原路处理手续费

**Files:**

- Modify: `app/service/OrderService.php:43-297,489-556`
- Modify: `tests/Integration/OrderCreationServiceTest.php`
- Modify: `tests/Integration/OrderClosingServiceTest.php`

**Interfaces:**

- Consumes: `FeeReservationService::allocate()`。
- Produces: 新订单持久化 `fee_reserved_cash`、`fee_reserved_discount` 和 `fee_reservation_status=reserved`。
- Produces: 关闭新订单后现金和优惠额度分别恢复，状态变为 `released`。

- [ ] **Step 1: 写优惠额度原路退回失败测试**

```php
public function testClosingOrderReturnsCashAndDiscountToOriginalSources(): void
{
    $merchant = $this->merchant('8.00', ['plan_fee_discount_balance' => '0.00']);
    $channel = $this->channel();
    $order = $this->order($merchant, $channel, [
        'fee_amount' => '3.00',
        'fee_reserved_cash' => '1.75',
        'fee_reserved_discount' => '1.25',
        'fee_reservation_status' => 'reserved',
        'fee_status' => 1,
    ]);

    self::assertTrue((new OrderService())->closePendingOrder($order->trade_no, '测试关闭'));

    $merchant->refresh();
    self::assertSame('9.75', $merchant->money);
    self::assertSame('1.25', $merchant->plan_fee_discount_balance);
    self::assertSame('released', $order->fresh()->fee_reservation_status);
}
```

增加第二个测试，重复关闭后两个余额都不再次增加。

- [ ] **Step 2: 运行关闭测试并确认错误退款行为**

Run:

```powershell
php vendor/bin/phpunit tests/Integration/OrderClosingServiceTest.php --filter OriginalSources
```

Expected: FAIL；当前代码把 `3.00` 全部加入现金余额，优惠额度没有恢复。

- [ ] **Step 3: 创建订单时保存精确来源**

在订单创建事务内调用分配服务，并使用结果更新商户：

```php
$reservation = $this->feeReservationService->allocate(
    $fee,
    (string)$lockedMerchant->money,
    (string)$lockedMerchant->plan_fee_discount_balance,
);

$lockedMerchant->money = bcsub((string)$lockedMerchant->money, $reservation->cash, 2);
$lockedMerchant->plan_fee_discount_balance = bcsub(
    (string)$lockedMerchant->plan_fee_discount_balance,
    $reservation->discount,
    2,
);
$lockedMerchant->save();
```

订单写入：

```php
'fee_amount' => $reservation->fee,
'fee_reserved_cash' => $reservation->cash,
'fee_reserved_discount' => $reservation->discount,
'fee_reservation_status' => 'reserved',
'fee_status' => bccomp($reservation->fee, '0.00', 2) > 0 ? 1 : 0,
```

充值订单和零手续费订单写入零值及 `consumed` 状态。

- [ ] **Step 4: 关闭订单时按来源冲正**

对 `fee_reservation_status=reserved` 的新订单执行：

```php
$cashRefund = $this->normalizeMoney($order->fee_reserved_cash);
$discountRefund = $this->normalizeMoney($order->fee_reserved_discount);
$merchant->money = bcadd((string)$merchant->money, $cashRefund, 2);
$merchant->plan_fee_discount_balance = bcadd(
    (string)$merchant->plan_fee_discount_balance,
    $discountRefund,
    2,
);
$order->fee_reservation_status = 'released';
$order->fee_status = 3;
```

`fee_reservation_status=legacy` 的存量订单保持原退款行为，并调用 `error_log()` 写入包含订单号的 `[FeeReservation] legacy refund` 告警；其他状态不退款。

- [ ] **Step 5: 验证新单和存量订单兼容**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/FeeReservationServiceTest.php tests/Integration/OrderCreationServiceTest.php tests/Integration/OrderClosingServiceTest.php tests/Integration/OrderFeeReservationTest.php
```

Expected: 新订单原路退款、重复关闭幂等、旧订单兼容测试全部通过。

- [ ] **Step 6: 提交资金修复**

```powershell
git add app/service/OrderService.php tests/Integration/OrderCreationServiceTest.php tests/Integration/OrderClosingServiceTest.php
git commit -m "fix: release reserved fees to original balances"
```

---

### Task 5: 提取通道路由和支付初始化服务

**Files:**

- Create: `app/service/order/ChannelRoutingService.php`
- Create: `app/service/order/PaymentInitializationService.php`
- Modify: `app/service/OrderService.php:597-779,813-856`
- Modify: `tests/Integration/OrderCreationServiceTest.php`
- Modify: `tests/Integration/PaymentInitializationServiceTest.php`

**Interfaces:**

- Produces: `ChannelRoutingService::select(int $merchantId, string $type, string $money): Channel`。
- Produces: `ChannelRoutingService::assertReady(Channel $channel): void`。
- Produces: `PaymentInitializationService::prepare(Order $order, array $originalParams, string $gatewayBaseUrl): array`。
- Consumes: `PollService`、`RiskGuardService`、`PaymentManager`、`Authcode`。

- [ ] **Step 1: 增加直接组件行为测试**

在 `OrderCreationServiceTest` 增加：主通道不可用且合法备用通道存在时返回备用通道；全部通道不可用时抛出“暂无满足条件的可用支付通道”。

在 `PaymentInitializationServiceTest` 保留并迁移以下三个真实行为：

- 活跃初始化领取阻止第二次驱动调用。
- 超过30秒的领取允许恢复。
- 旧领取者不能覆盖新领取者写入的支付结果。

- [ ] **Step 2: 运行测试并记录拆分前通过状态**

Run:

```powershell
php vendor/bin/phpunit tests/Integration/OrderCreationServiceTest.php tests/Integration/PaymentInitializationServiceTest.php
```

Expected: 行为测试通过，作为后续纯重构保护网。

- [ ] **Step 3: 提取通道路由服务**

```php
final class ChannelRoutingService
{
    public function __construct(
        private readonly PollService $pollService = new PollService(),
        private readonly RiskGuardService $riskGuard = new RiskGuardService(),
    ) {
    }
}
```

将当前 `OrderService::selectChannel()` 的完整方法体迁移为 `ChannelRoutingService::select(int $merchantId, string $type, string $money): Channel`，只把传给 `PollService` 和 `RiskGuardService` 的金额显式转换为 `float`。将 `assertChannelReady()` 的完整方法体迁移为 `assertReady(Channel $channel): void`，并把当前 `decryptChannelConfig()` 作为该类私有方法一并迁移。轮询失败、备用通道、加权兜底、驱动不存在、风控失败和 `upchannel()` 错误消息必须逐分支保持一致。

- [ ] **Step 4: 提取支付初始化服务**

创建 `final class PaymentInitializationService`，公开方法签名固定为 `prepare(Order $order, array $originalParams, string $gatewayBaseUrl): array`。将当前 `OrderService::preparePayment()` 的完整领取事务、驱动调用、所有权校验、写回事务和失败恢复逻辑迁移到该方法；把 `formatOrderResult()`、`decryptChannelConfig()` 和初始化专用金额格式化方法作为私有方法一并迁移。驱动接收的 `trade_no`、`out_trade_no`、`merchant_out_trade_no`、`money`、`notify_url` 和 `return_url` 必须保持原值来源。

- [ ] **Step 5: 由兼容门面委托新服务**

`OrderService` 构造器增加可选依赖，默认值保持无参数实例化兼容：

```php
public function __construct(
    ?OrderNumberGenerator $orderNumberGenerator = null,
    ?FeeReservationService $feeReservationService = null,
    ?ChannelRoutingService $channelRoutingService = null,
    ?PaymentInitializationService $paymentInitializationService = null,
) {
    $this->orderNumberGenerator = $orderNumberGenerator ?? new OrderNumberGenerator();
    $this->feeReservationService = $feeReservationService ?? new FeeReservationService();
    $this->channelRoutingService = $channelRoutingService ?? new ChannelRoutingService();
    $this->paymentInitializationService = $paymentInitializationService ?? new PaymentInitializationService();
    $this->notifyService = new MerchantNotifyService();
}
```

删除 `OrderService` 中已经迁移的私有方法。

- [ ] **Step 6: 运行组件和完整订单测试**

Run:

```powershell
php vendor/bin/phpunit tests/Integration/OrderCreationServiceTest.php tests/Integration/PaymentInitializationServiceTest.php tests/Integration/OrderClosingServiceTest.php tests/Integration/OrderFeeReservationTest.php
```

Expected: 全部通过，支付驱动参数和并发领取行为不变。

- [ ] **Step 7: 提交通道路由与初始化拆分**

```powershell
git add app/service/order/ChannelRoutingService.php app/service/order/PaymentInitializationService.php app/service/OrderService.php tests/Integration/OrderCreationServiceTest.php tests/Integration/PaymentInitializationServiceTest.php
git commit -m "refactor: extract order routing and payment initialization"
```

---

### Task 6: 提取订单创建和关闭用例并收敛兼容门面

**Files:**

- Create: `app/service/order/CreateOrderService.php`
- Create: `app/service/order/CloseOrderService.php`
- Modify: `app/service/OrderService.php:43-297,489-595`
- Modify: `tests/Integration/OrderCreationServiceTest.php`
- Modify: `tests/Integration/OrderClosingServiceTest.php`

**Interfaces:**

- Produces: `CreateOrderService::create(array $params, string $gatewayBaseUrl, string $businessType, string $remoteIp): array`。
- Produces: `CloseOrderService::close(string $tradeNo, string $reason): bool`。
- Produces: `CloseOrderService::expire(int $limit): int`。
- Consumes: 前面任务生成的订单号、手续费、通道路由和支付初始化服务。

- [ ] **Step 1: 为兼容门面增加契约测试**

在两个集成测试中分别通过 `OrderService` 和新应用服务执行相同输入，断言：

- 创建结果包含完全相同的键：`trade_no`、`money`、`price`、`pay_type`、`business_type`、`status`、`pay_url`、`pay_mode`。
- 关闭返回值、订单状态、手续费状态和余额结果一致。

测试期待的新应用服务类尚不存在，因此首次运行必须失败。

- [ ] **Step 2: 运行契约测试并确认预期失败**

Run:

```powershell
php vendor/bin/phpunit tests/Integration/OrderCreationServiceTest.php tests/Integration/OrderClosingServiceTest.php --filter Contract
```

Expected: FAIL，原因是新应用服务尚未实现。

- [ ] **Step 3: 提取创建用例**

```php
final class CreateOrderService
{
    public function __construct(
        private readonly OrderNumberGenerator $orderNumberGenerator,
        private readonly FeeReservationService $feeReservationService,
        private readonly ChannelRoutingService $channelRoutingService,
        private readonly PaymentInitializationService $paymentInitializationService,
        private readonly RiskGuardService $riskGuardService = new RiskGuardService(),
    ) {
    }
}
```

新增公开方法 `create(array $params, string $gatewayBaseUrl = '', string $businessType = 'payment', string $remoteIp = ''): array`，将当前 `OrderService::createOrder()` 的完整方法体迁移到其中。原 `enforceOrderRateLimit()`、`normalizeMoney()`、`normalizeInputMoney()`、`quoteDecimal()` 和 `isHttpUrl()` 随创建用例迁移为私有方法；原通道路由、手续费和支付初始化调用分别改为调用前三个任务生成的服务。新服务不得回调旧 `OrderService`。

- [ ] **Step 4: 提取关闭用例**

```php
final class CloseOrderService
{
    public function expire(int $limit = 500): int
    {
        $tradeNumbers = Order::where('status', 0)
            ->where('expire_time', '>', 0)
            ->where('expire_time', '<=', time())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('trade_no');

        $closed = 0;
        foreach ($tradeNumbers as $tradeNo) {
            if ($this->close((string)$tradeNo, '订单超时')) {
                $closed++;
            }
        }
        return $closed;
    }
}
```

在同一类增加 `close(string $tradeNo, string $reason = '订单关闭'): bool`，完整迁移当前 `OrderService::closePendingOrder()` 的固定加锁顺序、状态判断、手续费原路冲正和幂等返回逻辑。`expire()` 调用同一个 `close()`，不得重新实现另一套关闭规则。

- [ ] **Step 5: 将 `OrderService` 收敛为兼容门面**

```php
public function createOrder(
    array $params,
    string $gatewayBaseUrl = '',
    string $businessType = 'payment',
    string $remoteIp = ''
): array {
    return $this->createOrderService->create($params, $gatewayBaseUrl, $businessType, $remoteIp);
}

public function closePendingOrder(string $tradeNo, string $reason = '订单关闭'): bool
{
    return $this->closeOrderService->close($tradeNo, $reason);
}

public function expirePendingOrders(int $limit = 500): int
{
    return $this->closeOrderService->expire($limit);
}
```

本里程碑暂时保留 `markAsPaid()`、`resendNotify()` 和结算查找逻辑，下一里程碑再提取结算与 Outbox。

- [ ] **Step 6: 执行完整验证**

Run:

```powershell
composer validate --strict
php vendor/bin/phpunit
$phpFiles = Get-ChildItem -Path app,config,process,services,plugins-src,support,tests -Recurse -File -Filter '*.php'
foreach ($phpFile in $phpFiles) { php -l $phpFile.FullName }
git diff --check
```

Expected: Composer 配置有效；全部 PHPUnit 测试通过；PHP 语法错误为0；无新增空白错误。

- [ ] **Step 7: 检查文件职责和行数**

Run:

```powershell
(Get-Content app/service/OrderService.php | Measure-Object -Line).Lines
Get-ChildItem app/service/order -File | ForEach-Object { [PSCustomObject]@{ File = $_.Name; Lines = (Get-Content $_.FullName | Measure-Object -Line).Lines } }
```

Expected: `OrderService` 不超过400行；每个新服务具有单一职责，任一文件不超过300行。

- [ ] **Step 8: 提交应用服务拆分**

```powershell
git add app/service/order/CreateOrderService.php app/service/order/CloseOrderService.php app/service/OrderService.php tests/Integration/OrderCreationServiceTest.php tests/Integration/OrderClosingServiceTest.php
git commit -m "refactor: split order creation and closing services"
```

---

## 里程碑验收

完成全部任务后验证：

```powershell
composer validate --strict
php vendor/bin/phpunit
```

并检查：

- 2000 个新生成订单号格式正确且无重复。
- 幂等下单继续返回原订单号。
- 现金和套餐优惠额度按原来源预留和释放。
- 重复关闭不重复退款。
- 存量 `legacy` 订单保持兼容行为。
- 支付初始化领取和恢复行为不变。
- `OrderService` 第一轮拆分后不超过400行。
- 提交中不包含实施前工作区已有的修改文件。
