<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as DB;

$config = require dirname(__DIR__) . '/config/database.php';
$db = new DB;
$db->addConnection($config['connections']['mysql']);
$db->setAsGlobal();
$db->bootEloquent();

try {
    if (!DB::schema()->hasColumn('cx_merchant', 'plan_fee_discount_balance')) {
        DB::schema()->table('cx_merchant', function ($t) {
            $t->decimal('plan_fee_discount_balance', 10, 2)->default(0.00)->comment('套餐费用抵扣手续费剩余额度');
        });
        echo "Column plan_fee_discount_balance added to cx_merchant.\n";
    } else {
        echo "Column plan_fee_discount_balance already exists.\n";
    }

    DB::table('cx_config')->insertOrIgnore([
        ['name' => 'register_grant_balance', 'value' => '10.00', 'title' => '新商户注册赠送体验服务费余额(元)'],
        ['name' => 'system_recharge_pid', 'value' => '1000', 'title' => '平台统一收单与充值系统商户PID'],
    ]);
    echo "Default system configs ensured.\n";
} catch (\Throwable $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
}
