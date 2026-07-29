<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use support\IpWhitelist;

final class IpWhitelistTest extends TestCase
{
    public function testNormalizeDeduplicatesAndCanonicalizesAddresses(): void
    {
        self::assertSame(
            '127.0.0.1,2001:db8::1',
            IpWhitelist::normalize("127.0.0.1, 127.0.0.1\n2001:0db8:0:0:0:0:0:1")
        );
    }

    public function testNormalizeRejectsInvalidAndOversizedLists(): void
    {
        self::assertNull(IpWhitelist::normalize('127.0.0.1,not-an-ip'));
        self::assertNull(IpWhitelist::normalize('127.0.0.1,127.0.0.2', 1));
    }

    public function testEmptyListAllowsAllButConfiguredListUsesExactBinaryMatch(): void
    {
        self::assertTrue(IpWhitelist::allows('203.0.113.5', ''));
        self::assertTrue(IpWhitelist::allows('2001:db8::1', '2001:0db8:0:0:0:0:0:1'));
        self::assertFalse(IpWhitelist::allows('203.0.113.6', '203.0.113.5'));
        self::assertFalse(IpWhitelist::allows('invalid-ip', '203.0.113.5'));
    }
}
