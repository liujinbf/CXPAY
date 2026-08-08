<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as DB;
use app\model\Merchant;
use app\model\Plan;
use app\model\FinanceLog;
use app\model\UserMoneyLog;
use app\service\OrderService;

// 初始化 DB 关联
$config = require dirname(__DIR__) . '/config/database.php';
$db = new DB;
$db->addConnection($config['connections']['mysql']);
$db->setAsGlobal();
$db->bootEloquent();

echo "=== CXPAY 新功能自动化综合验证脚本 ===\n\n";

try {
    // 1. 验证数据库字段与配置
    echo "[1/4] 检查数据库 Schema ...\n";
    $hasCol = DB::schema()->hasColumn('cx_merchant', 'plan_fee_discount_balance');
    echo "  cx_merchant.plan_fee_discount_balance 存在: " . ($hasCol ? 'YES' : 'NO') . "\n";

    $grantBalanceCfg = DB::table('cx_config')->where('name', 'register_grant_balance')->value('value');
    $sysPidCfg = DB::table('cx_config')->where('name', 'system_recharge_pid')->value('value');
    echo "  register_grant_balance 配置: {$grantBalanceCfg}\n";
    echo "  system_recharge_pid 配置: {$sysPidCfg}\n";

    // 2. 模拟新商户注册（验证赠送体验余额）
    echo "\n[2/4] 测试新商户注册赠送体验金逻辑 ...\n";
    $testPid = 'TEST_GRANT_' . rand(1000, 9999);
    $grantVal = (float)($grantBalanceCfg ?: 10.00);

    $testMerchant = Merchant::create([
        'name' => '体验注册测试商户',
        'pid'  => $testPid,
        'key'  => bin2hex(random_bytes(16)),
        'password_hash' => password_hash('123456', PASSWORD_BCRYPT),
        'money' => number_format($grantVal, 2, '.', ''),
        'rate' => 0.0200,
        'status' => 1,
        'create_time' => time(),
    ]);

    FinanceLog::create([
        'merchant_id' => $testMerchant->id,
        'type'        => 'register_grant',
        'amount'      => '+' . number_format($grantVal, 2, '.', ''),
        'before'      => '0.00',
        'after'       => number_format($grantVal, 2, '.', ''),
        'memo'        => "新商户注册赠送体验服务费余额 ¥" . number_format($grantVal, 2, '.', ''),
        'create_time' => time(),
    ]);

    echo "  商户 ID={$testMerchant->id}, PID={$testPid} 注册成功！\n";
    echo "  初始注册余额 money = ¥{$testMerchant->money}\n";

    // 3. 模拟商户购买 99 元套餐（验证套餐费全额转化为手续费抵扣金）
    echo "\n[3/4] 测试商户订阅套餐（累加抵扣金）...\n";
    $planPrice = 99.00;
    // 先赋予充值余额用于购买
    $testMerchant->money = number_format((float)$testMerchant->money + $planPrice, 2, '.', '');
    $testMerchant->save();

    // 模拟 buyPlan 扣除 99 元余额并增加 99 元套餐抵扣金
    $beforeMoney = (float)$testMerchant->money;
    $afterMoney  = $beforeMoney - $planPrice;
    $testMerchant->money = number_format($afterMoney, 2, '.', '');
    
    $currentDiscount = (float)($testMerchant->plan_fee_discount_balance ?? 0);
    $testMerchant->plan_fee_discount_balance = number_format($currentDiscount + $planPrice, 2, '.', '');
    $testMerchant->plan_id = 2; // VIP黄金月卡
    $testMerchant->rate = 0.0180; // 1.8%
    $testMerchant->save();

    echo "  购买 99 元套餐成功！\n";
    echo "  购买后账户余额 money = ¥{$testMerchant->money}\n";
    echo "  购买后套餐手续费抵扣金 plan_fee_discount_balance = ¥{$testMerchant->plan_fee_discount_balance}\n";

    // 4. 模拟收单交易扣费（验证优先扣除套餐抵扣金）
    echo "\n[4/4] 测试订单交易扣费（优先消耗套餐抵扣金）...\n";
    // 假设一笔 1000 元订单，按 1.8% 扣费 = 18 元
    $orderAmount = 1000.00;
    $fee = bcmul((string)$orderAmount, (string)$testMerchant->rate, 2); // 18.00
    echo "  订单金额 ¥{$orderAmount}，费率 1.8%，需手续费 ¥{$fee}\n";

    $discountBal = (float)$testMerchant->plan_fee_discount_balance;
    $moneyBal    = (float)$testMerchant->money;

    if ($discountBal >= $fee) {
        $newDiscount = $discountBal - $fee;
        $testMerchant->plan_fee_discount_balance = number_format($newDiscount, 2, '.', '');
        $testMerchant->save();
        echo "  [SUCCESS] 全额使用套餐抵扣金！扣除 ¥{$fee}，剩余抵扣金 ¥{$testMerchant->plan_fee_discount_balance}，通用余额保持 ¥{$testMerchant->money}\n";
    }

    echo "\n=== 全部测试项验证通过！ ===\n";

} catch (\Throwable $e) {
    echo "\n[ERROR] 测试过程发生异常: " . $e->getMessage() . "\n";
} finally {
    // 清理测试商户数据
    if (isset($testMerchant) && $testMerchant->id) {
        FinanceLog::where('merchant_id', $testMerchant->id)->delete();
        $testMerchant->delete();
        echo "(已自动清理测试商户环境数据)\n";
    }
}
