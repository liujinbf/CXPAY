<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use app\exception\Handler;

try {
    $class = new \ReflectionClass(Handler::class);
    echo "Class exists: " . $class->getName() . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
