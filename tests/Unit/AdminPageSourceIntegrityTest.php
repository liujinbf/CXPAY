<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminPageSourceIntegrityTest extends TestCase
{
    private string $html;

    protected function setUp(): void
    {
        $html = file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.html');
        self::assertIsString($html);
        $this->html = $html;
    }

    public function testApplicationEntryIsValidJavaScript(): void
    {
        $script = dirname(__DIR__, 2) . '/public/admin/assets/app.js';
        self::assertFileExists($script);
        [$exitCode, $output] = $this->runNodeSyntaxCheck($script);
        self::assertSame(0, $exitCode, $output);
    }

    public function testStaticMarkupIdsAreUnique(): void
    {
        $markup = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $this->html);
        self::assertIsString($markup);
        preg_match_all('/\bid="([^"]+)"/', $markup, $matches);
        $counts = array_count_values($matches[1]);
        $duplicates = array_keys(array_filter(
            $counts,
            static fn (int $count): bool => $count > 1
        ));

        self::assertSame([], $duplicates, '浏览器静态 DOM id 不得重复');
    }

    public function testNamedBusinessFunctionsAreUnique(): void
    {
        preg_match_all(
            '/\b(?:async\s+)?function\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/',
            $this->html,
            $matches
        );
        $counts = array_count_values($matches[1]);
        $duplicates = array_keys(array_filter(
            $counts,
            static fn (int $count): bool => $count > 1
        ));

        self::assertSame([], $duplicates, '管理页动作不得依赖后声明覆盖前声明');
    }

    /** @return array{0: int, 1: string} */
    private function runNodeSyntaxCheck(string $script): array
    {
        $pipes = [];
        $process = proc_open(
            ['node', '--check', $script],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), trim((string) $stdout . PHP_EOL . (string) $stderr)];
    }
}
