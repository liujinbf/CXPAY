<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\controller\admin\AdminAuthController;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use support\Request;

final class AdminControllerApiContractTest extends TestCase
{
    public function testAuthenticationRejectsEmptyCredentials(): void
    {
        self::assertTrue(class_exists(AdminAuthController::class), '认证控制器尚未迁移');
        $payload = $this->decode((new AdminAuthController())->login($this->postRequest([])));

        self::assertSame(-1, $payload['code']);
        self::assertSame('管理员账号与密码不能为空', $payload['msg']);
    }

    private function postRequest(array $data): Request
    {
        $body = http_build_query($data);
        return new Request(
            "POST / HTTP/1.1\r\n"
            . "Host: pay.example.com\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body
        );
    }

    private function decode(string $json): array
    {
        return json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    }
}
