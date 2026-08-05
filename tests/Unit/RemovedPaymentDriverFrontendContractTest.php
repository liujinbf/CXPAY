<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\RemovedPaymentDrivers;
use PHPUnit\Framework\TestCase;

final class RemovedPaymentDriverFrontendContractTest extends TestCase
{
    public function testAdminFrontendContainsNoRemovedDriverCode(): void
    {
        $html = (string)file_get_contents(
            __DIR__ . '/../../public/admin/index.html'
        );

        foreach (RemovedPaymentDrivers::all() as $code) {
            self::assertStringNotContainsString(
                $code,
                $html,
                "后台页面仍引用已移除驱动 {$code}"
            );
        }
    }

    public function testRuntimeSourcesContainNoRemovedDriverCodeOutsideTombstone(): void
    {
        $roots = [
            __DIR__ . '/../../app',
            __DIR__ . '/../../public',
            __DIR__ . '/../../config',
        ];
        $tombstone = realpath(
            __DIR__ . '/../../app/payment/RemovedPaymentDrivers.php'
        );

        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $root,
                    \FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()
                    || !in_array(
                        strtolower($file->getExtension()),
                        ['php', 'html', 'js', 'json'],
                        true
                    )
                ) {
                    continue;
                }

                if (realpath($file->getPathname()) === $tombstone) {
                    continue;
                }

                $source = (string)file_get_contents($file->getPathname());

                foreach (RemovedPaymentDrivers::all() as $code) {
                    self::assertStringNotContainsString(
                        $code,
                        $source,
                        "运行时代码仍引用已移除驱动 {$code}: "
                            . $file->getPathname()
                    );
                }
            }
        }
    }

    public function testRemovedDriverImplementationFilesDoNotExist(): void
    {
        $directories = [
            'AlipayOfficial',
            'WxpayOfficial',
            'AlipayScanBill',
            'WxpayProtocolCloud',
            'QqpayProtocolCloud',
            'QqpayEpay',
        ];

        foreach ($directories as $directory) {
            self::assertFileDoesNotExist(
                __DIR__
                . "/../../app/payment/Drivers/{$directory}/Driver.php"
            );
        }
    }
}
