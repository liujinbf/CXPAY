<?php

declare(strict_types=1);

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (str_starts_with($path, '/api/admin/')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => 0,
        'msg' => '浏览器冒烟测试模拟响应',
        'data' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return true;
}

$public = dirname(__DIR__, 2) . '/public';
$publicRoot = realpath($public);
$candidate = realpath($public . $path);
if (
    $publicRoot !== false
    && $candidate !== false
    && str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($candidate)
) {
    return false;
}

http_response_code(404);
echo 'Not Found';

return true;
