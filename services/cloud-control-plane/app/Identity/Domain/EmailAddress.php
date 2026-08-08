<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;

final readonly class EmailAddress
{
    private function __construct(
        private string $display,
        private string $canonical
    ) {
    }

    public static function fromString(string $value): self
    {
        $display = trim($value);
        if (substr_count($display, '@') !== 1) {
            throw self::invalid();
        }

        [$local, $domain] = explode('@', $display, 2);
        if ($local === '' || $domain === '') {
            throw self::invalid();
        }

        $asciiDomain = idn_to_ascii(
            mb_strtolower($domain, 'UTF-8'),
            IDNA_DEFAULT,
            INTL_IDNA_VARIANT_UTS46
        );
        if ($asciiDomain === false) {
            throw self::invalid();
        }

        $canonical = mb_strtolower($local, 'UTF-8') . '@' . strtolower($asciiDomain);
        if (filter_var($canonical, FILTER_VALIDATE_EMAIL) === false) {
            throw self::invalid();
        }

        return new self($display, $canonical);
    }

    public function display(): string
    {
        return $this->display;
    }

    public function canonical(): string
    {
        return $this->canonical;
    }

    private static function invalid(): CloudException
    {
        return new CloudException(
            ErrorCode::CREDENTIALS_INVALID,
            '邮箱格式不正确',
            422
        );
    }
}
