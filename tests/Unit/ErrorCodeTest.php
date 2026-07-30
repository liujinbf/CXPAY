<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use support\ErrorCode;

/**
 * ErrorCode 统一错误码单元测试
 */
final class ErrorCodeTest extends TestCase
{
    public function testOkCode(): void
    {
        self::assertSame(1, ErrorCode::OK);
        self::assertSame('操作成功', ErrorCode::message(ErrorCode::OK));
    }

    public function testResponseIncludesCode(): void
    {
        $r = ErrorCode::response(ErrorCode::INVALID_PARAMS);
        self::assertSame(ErrorCode::INVALID_PARAMS, $r['code']);
        self::assertNotEmpty($r['msg']);
        self::assertArrayNotHasKey('data', $r);
    }

    public function testResponseWithCustomMsgAndData(): void
    {
        $r = ErrorCode::response(ErrorCode::ORDER_NOT_FOUND, '找不到订单', ['id' => 123]);
        self::assertSame('找不到订单', $r['msg']);
        self::assertSame(['id' => 123], $r['data']);
    }

    public function testAllDefinedCodesHaveMessages(): void
    {
        $constants = (new \ReflectionClass(ErrorCode::class))->getConstants();
        foreach ($constants as $name => $code) {
            if (is_int($code)) {
                $msg = ErrorCode::message($code);
                self::assertNotSame('未知错误', $msg, "ErrorCode::{$name} ({$code}) 没有对应的 message");
            }
        }
    }

    public function testUnknownCodeReturnsDefaultMessage(): void
    {
        self::assertSame('未知错误', ErrorCode::message(99999));
    }
}
