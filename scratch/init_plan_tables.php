<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as DB;

$config = require dirname(__DIR__) . '/config/database.php';
$db = new DB;
$db->addConnection($config['connections']['mysql']);
$db->setAsGlobal();
$db->bootEloquent();

try {
    if (!DB::schema()->hasTable('cx_plan')) {
        DB::schema()->create('cx_plan', function ($t) {
            $t->increments('id');
            $t->string('name', 100);
            $t->integer('days')->default(30);
            $t->decimal('rate', 5, 2)->default(2.50);
            $t->decimal('min_rate', 5, 2)->default(0.00);
            $t->integer('channel_quota')->default(0);
            $t->string('allowed_channels', 255)->default('');
            $t->decimal('price', 10, 2)->default(0.00);
            $t->integer('limit_count')->default(0);
            $t->string('memo', 255)->nullable();
            $t->integer('sort_order')->default(0);
            $t->tinyInteger('status')->default(1);
            $t->integer('create_time')->default(0);
        });
        // 填充默认初始化预设套餐
        DB::table('cx_plan')->insert([
            [
                'id'               => 1,
                'name'             => '0元免费体验套餐',
                'days'             => 7,
                'rate'             => 2.50,
                'min_rate'         => 0.00,
                'channel_quota'    => 3,
                'allowed_channels' => 'alipay,wxpay',
                'price'            => 0.00,
                'limit_count'      => 1,
                'memo'             => '新商户零元免费试用，体验全部聚合出码功能',
                'sort_order'       => 0,
                'status'           => 1,
                'create_time'      => time(),
            ],
            [
                'id'               => 2,
                'name'             => 'VIP黄金月卡套餐',
                'days'             => 30,
                'rate'             => 1.80,
                'min_rate'         => 0.00,
                'channel_quota'    => 10,
                'allowed_channels' => 'alipay,wxpay,qqpay',
                'price'            => 99.00,
                'limit_count'      => 0,
                'memo'             => '交易扣率低至 1.8%，多通道轮询与专属告警通知',
                'sort_order'       => 1,
                'status'           => 1,
                'create_time'      => time(),
            ],
            [
                'id'               => 3,
                'name'             => 'VIP钻石年卡套餐',
                'days'             => 365,
                'rate'             => 1.20,
                'min_rate'         => 0.00,
                'channel_quota'    => 0,
                'allowed_channels' => 'alipay,wxpay,qqpay,usdt',
                'price'            => 888.00,
                'limit_count'      => 0,
                'memo'             => '尊享最高权重优先级通道调度，无限通道配额与专属人工服务',
                'sort_order'       => 2,
                'status'           => 1,
                'create_time'      => time(),
            ],
        ]);
        echo "cx_plan created & default plans inserted\n";
    } else {
        echo "cx_plan already exists\n";
    }

    if (!DB::schema()->hasTable('cx_merchant_plan_log')) {
        DB::schema()->create('cx_merchant_plan_log', function ($t) {
            $t->increments('id');
            $t->integer('merchant_id');
            $t->integer('plan_id');
            $t->string('plan_name', 100);
            $t->decimal('price', 10, 2)->default(0.00);
            $t->integer('days')->default(0);
            $t->decimal('rate', 5, 2)->default(0.00);
            $t->integer('create_time')->default(0);
        });
        echo "cx_merchant_plan_log created\n";
    } else {
        echo "cx_merchant_plan_log already exists\n";
    }

    // 检查 cx_merchant 表是否补充字段
    if (!DB::schema()->hasColumn('cx_merchant', 'plan_id')) {
        DB::schema()->table('cx_merchant', function ($t) {
            $t->integer('plan_id')->default(0);
            $t->integer('plan_expire_time')->default(0);
            $t->integer('channel_quota')->default(0);
        });
        echo "cx_merchant columns added\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
