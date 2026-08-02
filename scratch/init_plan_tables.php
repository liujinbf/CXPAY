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
        echo "cx_plan created\n";
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
