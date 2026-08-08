<?php

declare(strict_types=1);

namespace CloudControl\Shared\Config;

final class Environment
{
    public static function get(string $name, mixed $default = null): mixed
    {
        $value = getenv($name);
        if ($value !== false) {
            return $value;
        }

        if (array_key_exists($name, $_ENV)) {
            return $_ENV[$name];
        }

        if (array_key_exists($name, $_SERVER)) {
            return $_SERVER[$name];
        }

        return $default;
    }
}
