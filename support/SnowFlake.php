<?php

declare(strict_types=1);

namespace support;

/**
 * 64位雪花ID分布式单据号生成器
 */
class SnowFlake
{
    private const EPOCH = 1672502400000;
    protected static int $machineId = 1;
    protected static int $count = 0;
    protected static int $last = 0;

    public static function setMachineId(int $mId): void
    {
        self::$machineId = $mId;
    }

    public static function makeId(): string
    {
        $time = (int)floor(microtime(true) * 1000) - self::EPOCH;
        
        if ($time === self::$last) {
            self::$count = (self::$count + 1) & 4095;
            if (self::$count === 0) {
                while ($time <= self::$last) {
                    $time = (int)floor(microtime(true) * 1000) - self::EPOCH;
                }
            }
        } else {
            self::$count = 0;
        }

        self::$last = $time;

        return sprintf('%d%02d%04d', $time, self::$machineId, self::$count);
    }
}
