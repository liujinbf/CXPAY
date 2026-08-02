<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use app\controller\admin\PackvipAdminController;
use Illuminate\Database\Capsule\Manager as DB;

$config = require dirname(__DIR__) . '/config/database.php';
$db = new DB;
$db->addConnection($config['connections']['mysql']);
$db->setAsGlobal();
$db->bootEloquent();

try {
    $controller = new PackvipAdminController();
    $res = $controller->list();
    echo "TYPE: " . gettype($res) . "\n";
    if ($res instanceof \support\Response) {
        echo "BODY: " . $res->rawBody() . "\n";
    } else {
        echo "VAL: " . var_export($res, true) . "\n";
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
