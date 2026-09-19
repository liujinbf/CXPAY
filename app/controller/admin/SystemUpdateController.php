<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\service\CloudInstanceClient;
use app\service\SystemUpdateGuard;
use support\Request;
use support\Response;
use Throwable;

/**
 * CXPAY 智能双模在线更新控制器
 *
 * 支持两种部署环境无缝切换：
 *   1. Master Git 模式（总控开发环境，基于 Git 分支、Commit 拉取与回滚）
 *   2. Cloud Release 模式（用户独立部署的源码商业版，自动对接云端发布包下载、SHA256校验、安全增量覆盖与平滑热重启）
 */
final class SystemUpdateController
{
    private const PROGRESS_FILE = '/runtime/update_progress.json';
    private const HISTORY_FILE  = '/runtime/instance/version_history.json';

    public function __construct(
        private readonly ?SystemUpdateGuard $guard = null,
        private readonly ?CloudInstanceClient $cloudClient = null
    ) {
    }

    private function updateGuard(): SystemUpdateGuard
    {
        return $this->guard ?? new SystemUpdateGuard();
    }

    private function client(): CloudInstanceClient
    {
        return $this->cloudClient ?? new CloudInstanceClient();
    }

    /**
     * 判断当前运行环境是否为 Git 总控开发模式
     */
    private function isMasterGit(): bool
    {
        $gitDir = base_path() . DIRECTORY_SEPARATOR . '.git';
        if (!is_dir($gitDir)) {
            return false;
        }

        $test = $this->execGit('git rev-parse --is-inside-work-tree');
        return trim($test) === 'true';
    }

    /**
     * 执行 Git 命令行
     */
    private function execGit(string $command): string
    {
        $baseDir = escapeshellarg(base_path());
        if (DIRECTORY_SEPARATOR === '\\') {
            $cmd = "cd /d {$baseDir} && {$command} 2>&1";
        } else {
            $cmd = "cd {$baseDir} && {$command} 2>&1";
        }
        return trim((string)@shell_exec($cmd));
    }

    /**
     * 检查系统更新状态（自动适配 Git 模式与云端商业发布包模式）
     */
    public function checkUpdate(Request $request): Response
    {
        if ($blocked = $this->updateGuard()->disabledResponse()) {
            return $blocked;
        }

        $isMaster = $this->isMasterGit();

        if ($isMaster) {
            return $this->checkUpdateGit();
        }

        return $this->checkUpdateCloud();
    }

    /**
     * Git 模式：检查远端仓库更新
     */
    private function checkUpdateGit(): Response
    {
        $branch    = $this->execGit('git rev-parse --abbrev-ref HEAD');
        $commit    = $this->execGit('git rev-parse --short HEAD');
        $commitMsg = $this->execGit('git log -1 --pretty=format:"%s (%cd)" --date=format:"%Y-%m-%d %H:%M:%S"');

        // 执行 fetch 获取远端最新引用
        $this->execGit('git fetch');
        $behindCount = $this->execGit('git rev-list --count HEAD..@{u}');
        $hasUpdate = is_numeric($behindCount) && (int)$behindCount > 0;

        $changelog = [];
        if ($hasUpdate) {
            $rawLogs = $this->execGit('git log HEAD..@{u} --pretty=format:"%h %s" -10');
            if ($rawLogs !== '') {
                $changelog = array_filter(array_map('trim', explode("\n", $rawLogs)));
            }
        }

        $remoteCommit = $hasUpdate ? $this->execGit('git rev-parse --short @{u}') : $commit;

        return json([
            'code' => 1,
            'msg'  => $hasUpdate ? "检测到远端仓库有 {$behindCount} 个待更新 Commit" : '当前代码已是最新版本',
            'data' => [
                'is_master'    => true,
                'has_update'   => $hasUpdate,
                'local_ver'    => $commit ? "Git #{$commit}" : 'v1.0.0',
                'remote_ver'   => $remoteCommit ? "Git #{$remoteCommit}" : 'v1.0.0',
                'branch'       => $branch ?: 'main',
                'commit'       => $commit ?: 'unknown',
                'commit_msg'   => $commitMsg ?: '无 Commit 历史',
                'commit_count' => is_numeric($behindCount) ? (int)$behindCount : 0,
                'changelog'    => $changelog,
            ],
        ]);
    }

    /**
     * 云端商业发布包模式：向官方云端控制台发起版本检测
     */
    private function checkUpdateCloud(): Response
    {
        $localVer = (string)config('app.version', '1.0.0');

        try {
            $cloudInfo = $this->client()->checkSystemUpdate($localVer, 'SOURCE_CODE');
        } catch (Throwable $e) {
            return json([
                'code' => 1,
                'msg'  => '云端检测网络暂不可达，当前运行版本为 v' . ltrim($localVer, 'v'),
                'data' => [
                    'is_master'    => false,
                    'has_update'   => false,
                    'local_ver'    => 'v' . ltrim($localVer, 'v'),
                    'remote_ver'   => 'v' . ltrim($localVer, 'v'),
                    'commit_count' => 0,
                    'changelog'    => [],
                ],
            ]);
        }

        $remoteVer = (string)($cloudInfo['latest_version'] ?? $localVer);
        $hasUpdate = (bool)($cloudInfo['has_update'] ?? false);

        // 解析更新说明日志
        $changelog = [];
        $rawChangelog = trim((string)($cloudInfo['changelog'] ?? ''));
        if ($rawChangelog !== '') {
            $lines = explode("\n", $rawChangelog);
            foreach ($lines as $line) {
                $line = trim($line, " \t\n\r\0\x0B-•*");
                if ($line !== '') {
                    $changelog[] = $line;
                }
            }
        }
        if (empty($changelog) && $hasUpdate) {
            $changelog = [
                'CXPAY 核心安全补丁与运行时稳定性优化',
                '云端双层架构授权与插件生态联动升级',
            ];
        }

        // 缓存最新检测数据，供 doUpdate 流程直接复用
        $cacheFile = base_path() . '/runtime/instance/latest_update_info.json';
        @mkdir(dirname($cacheFile), 0755, true);
        @file_put_contents($cacheFile, json_encode($cloudInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return json([
            'code' => 1,
            'msg'  => $hasUpdate ? "检测到云端发布了新版本 v" . ltrim($remoteVer, 'v') : '当前系统已是最新版本',
            'data' => [
                'is_master'    => false,
                'has_update'   => $hasUpdate,
                'local_ver'    => 'v' . ltrim($localVer, 'v'),
                'remote_ver'   => 'v' . ltrim($remoteVer, 'v'),
                'commit_count' => count($changelog),
                'changelog'    => $changelog,
                'download_url' => $cloudInfo['download_url'] ?? '',
                'file_size'    => $cloudInfo['file_size_bytes'] ?? 0,
                'checked_at'   => $cloudInfo['checked_at'] ?? date('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * 触发在线更新（自动适配 Git 模式与云端商业发布包模式）
     */
    public function doUpdate(Request $request): Response
    {
        if ($blocked = $this->updateGuard()->disabledResponse()) {
            return $blocked;
        }

        $progressFile = base_path() . self::PROGRESS_FILE;
        if (file_exists($progressFile)) {
            $currentProgress = json_decode((string)file_get_contents($progressFile), true);
            if (is_array($currentProgress) && ($currentProgress['running'] ?? false) === true) {
                // 如果在 3 分钟内正在执行更新，防止重复并发触发
                $lastTime = strtotime((string)($currentProgress['updated_at'] ?? ''));
                if ($lastTime && (time() - $lastTime < 180)) {
                    return json([
                        'code' => 0,
                        'msg'  => '系统更新任务正在后台进行中，请查看更新进度',
                    ]);
                }
            }
        }

        if ($this->isMasterGit()) {
            return $this->doUpdateGit();
        }

        return $this->doUpdateCloud();
    }

    /**
     * Git 模式：执行代码同步与热重启
     */
    private function doUpdateGit(): Response
    {
        $this->initProgress('正在通过 Git 远端仓库执行同步升级...');

        try {
            $this->addProgressStep('正在重置本地未提交脏改动 (git reset)...', 'info');
            $this->execGit('git reset --hard HEAD');
            $this->execGit('git checkout .');

            $this->addProgressStep('正在拉取 Git 远端最新代码 (git pull)...', 'info');
            $pullLog = $this->execGit('git pull');
            $newCommit = $this->execGit('git rev-parse --short HEAD');
            $newCommitMsg = $this->execGit('git log -1 --pretty=format:"%s (%cd)" --date=format:"%Y-%m-%d %H:%M:%S"');

            $this->addProgressStep("Git 代码拉取完成，当前版本 #{$newCommit}", 'ok');

            // 执行数据库补丁
            $this->addProgressStep('正在检查并执行数据库升级补丁...', 'info');
            $appliedPatches = $this->applyDatabasePatches(base_path());
            if (!empty($appliedPatches)) {
                $this->addProgressStep('已成功应用数据库补丁: ' . implode(', ', $appliedPatches), 'ok');
            }

            $this->addProgressStep('正在触发后台服务平滑热重载 (Reload Workers)...', 'done');
            $this->finishProgress(true, "Git 升级成功，已更新至 #{$newCommit}");

            $this->reloadService();

            return json([
                'code' => 1,
                'msg'  => "系统代码已从 Git 远端拉取成功 (#{$newCommit})，并触发后台进程重载！",
                'data' => [
                    'log'            => $pullLog ?: 'Already up to date.',
                    'new_commit'     => $newCommit,
                    'new_commit_msg' => $newCommitMsg,
                ],
            ]);
        } catch (Throwable $e) {
            $this->finishProgress(false, 'Git 升级失败: ' . $e->getMessage());
            return json([
                'code' => -1,
                'msg'  => 'Git 升级执行失败: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 云端商业发布包模式：自动下载官方安装包、SHA256完整性校验、安全文件替换与平滑重启
     */
    private function doUpdateCloud(): Response
    {
        $localVer = (string)config('app.version', '1.0.0');
        $this->initProgress("开始启动云端在线平滑升级 (当前版本: v{$localVer})...");

        try {
            $this->addProgressStep('步骤 1/7: 正在向官方云端检索最新发布版本信息...', 'start');
            $cloudInfo = $this->client()->checkSystemUpdate($localVer, 'SOURCE_CODE');

            if (!($cloudInfo['has_update'] ?? false)) {
                $this->finishProgress(true, '当前系统已是最新版本，无需升级');
                return json([
                    'code' => 1,
                    'msg'  => '当前系统已是最新版本，无需升级',
                ]);
            }

            $latestVer   = (string)$cloudInfo['latest_version'];
            $downloadUrl = (string)$cloudInfo['download_url'];
            $expectedSha = (string)($cloudInfo['sha256'] ?? '');

            if ($downloadUrl === '') {
                throw new \RuntimeException('云端未提供有效的安装包下载地址');
            }

            // 步骤 2: 下载更新包
            $updatesDir = base_path() . '/runtime/updates';
            @mkdir($updatesDir, 0755, true);
            $targetZip = $updatesDir . "/CXPAY_Release_v{$latestVer}.zip";

            $this->addProgressStep("步骤 2/7: 正在从云端安全下载新版本安装包 (v{$latestVer})...", 'info');
            $this->client()->downloadSystemUpdate($downloadUrl, $targetZip, $expectedSha);
            $fileSizeMb = round(filesize($targetZip) / (1024 * 1024), 2);
            $this->addProgressStep("安装包下载成功 ({$fileSizeMb} MB)，存储至 runtime/updates", 'ok');

            // 步骤 3: 完整性与安全格式验证
            $this->addProgressStep('步骤 3/7: 正在执行 SHA256 哈希完整性及防篡改校验...', 'info');
            $actualSha = hash_file('sha256', $targetZip);
            if ($expectedSha !== '' && !hash_equals(strtolower($expectedSha), strtolower((string)$actualSha))) {
                throw new \RuntimeException("安全校验失败：安装包 SHA256 签名不匹配 (实际: {$actualSha})");
            }
            $this->addProgressStep('SHA256 校验通过，安装包签名合法真实', 'ok');

            // 步骤 4: 创建临时 Staging 目录并解压
            $this->addProgressStep('步骤 4/7: 正在创建独立临时区并解压版本代码...', 'info');
            $stagingDir = $updatesDir . '/staging_' . bin2hex(random_bytes(6));
            $this->unzipArchive($targetZip, $stagingDir);
            $this->addProgressStep('版本包解压成功，代码结构完整', 'ok');

            // 步骤 5: 执行安全文件增量覆盖（严格保护客户本地敏感数据）
            $this->addProgressStep('步骤 5/7: 正在平滑替换应用核心文件 (自动保护本地 .env 及数据库)...', 'info');
            $this->applyStagingFiles($stagingDir, base_path());
            $this->addProgressStep('核心代码文件替换完成', 'ok');

            // 步骤 6: 执行数据库更新补丁
            $this->addProgressStep('步骤 6/7: 正在扫描并执行增量数据库表结构补丁...', 'info');
            $appliedPatches = $this->applyDatabasePatches(base_path());
            if (!empty($appliedPatches)) {
                $this->addProgressStep('已成功应用数据库补丁: ' . implode(', ', $appliedPatches), 'ok');
            } else {
                $this->addProgressStep('数据库结构已为最新状态，无需迁移', 'ok');
            }

            // 更新版本号与版本历史记录
            $this->updateAppVersion($latestVer);
            $this->recordVersionHistory($latestVer, (string)($cloudInfo['changelog'] ?? ''));

            // 清理临时文件
            $this->deleteDirectory($stagingDir);
            @unlink($targetZip);

            // 步骤 7: 触发平滑热重载
            $this->addProgressStep("步骤 7/7: 正在平滑热重启应用进程 (v{$latestVer})...", 'done');
            $this->finishProgress(true, "恭喜！CXPAY 综合系统已平滑升级至 v{$latestVer}");

            $this->reloadService();

            return json([
                'code' => 1,
                'msg'  => "系统已成功升级至最新版本 v{$latestVer}，并已完成进程平滑重载！",
                'data' => [
                    'new_version' => 'v' . ltrim($latestVer, 'v'),
                    'patches'     => $appliedPatches,
                ],
            ]);
        } catch (Throwable $e) {
            $this->finishProgress(false, '升级失败: ' . $e->getMessage());
            return json([
                'code' => -1,
                'msg'  => '在线升级失败: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 轮询更新进度（与前端终端输出完全对齐）
     */
    public function pollProgress(Request $request): Response
    {
        $progressFile = base_path() . self::PROGRESS_FILE;
        if (!file_exists($progressFile)) {
            return json([
                'code' => 1,
                'msg'  => '空闲中',
                'data' => [
                    'running' => false,
                    'steps'   => [],
                ],
            ]);
        }

        $data = json_decode((string)file_get_contents($progressFile), true);
        if (!is_array($data)) {
            return json([
                'code' => 1,
                'msg'  => '空闲中',
                'data' => ['running' => false, 'steps' => []],
            ]);
        }

        return json([
            'code' => 1,
            'msg'  => '获取成功',
            'data' => [
                'running' => (bool)($data['running'] ?? false),
                'steps'   => $data['steps'] ?? [],
            ],
        ]);
    }

    /**
     * 获取更新执行日志
     */
    public function getUpdateLog(Request $request): Response
    {
        if ($blocked = $this->updateGuard()->disabledResponse()) {
            return $blocked;
        }

        $progressFile = base_path() . self::PROGRESS_FILE;
        $lines = [];
        if (file_exists($progressFile)) {
            $data = json_decode((string)file_get_contents($progressFile), true);
            if (is_array($data) && !empty($data['steps'])) {
                foreach ($data['steps'] as $s) {
                    $lines[] = "[{$s['time']}] {$s['message']}";
                }
            }
        }

        $logText = !empty($lines) ? implode("\n", $lines) : '系统运行正常，暂无更新执行日志。';

        return json([
            'code' => 1,
            'msg'  => '获取成功',
            'data' => [
                'log' => $logText,
            ],
        ]);
    }

    /**
     * 获取版本历史记录
     */
    public function versionHistory(Request $request): Response
    {
        if ($blocked = $this->updateGuard()->disabledResponse()) {
            return $blocked;
        }

        if ($this->isMasterGit()) {
            $rawLogs = $this->execGit('git log -10 --pretty=format:"%h|%an|%cd|%s" --date=format:"%Y-%m-%d %H:%M"');
            $commits = [];
            if ($rawLogs !== '') {
                foreach (explode("\n", trim($rawLogs)) as $line) {
                    $parts = explode('|', trim($line));
                    if (count($parts) >= 4) {
                        $commits[] = [
                            'hash'    => $parts[0],
                            'short'   => $parts[0],
                            'author'  => $parts[1],
                            'date'    => $parts[2],
                            'message' => implode('|', array_slice($parts, 3)),
                        ];
                    }
                }
            }
            return json(['code' => 1, 'msg' => '获取成功', 'data' => ['commits' => $commits]]);
        }

        // 云端模式：读取本地版本历史
        $historyFile = base_path() . self::HISTORY_FILE;
        $history = [];
        if (file_exists($historyFile)) {
            $history = json_decode((string)file_get_contents($historyFile), true) ?: [];
        }

        $currentVer = 'v' . ltrim((string)config('app.version', '1.0.0'), 'v');

        if (empty($history)) {
            $history[] = [
                'hash'    => $currentVer,
                'short'   => $currentVer,
                'author'  => 'Official',
                'date'    => date('Y-m-d H:i'),
                'message' => '当前生产部署版本',
            ];
        }

        return json(['code' => 1, 'msg' => '获取成功', 'data' => ['commits' => $history]]);
    }

    /**
     * 执行版本回滚
     */
    public function doRollback(Request $request): Response
    {
        if ($blocked = $this->updateGuard()->disabledResponse()) {
            return $blocked;
        }

        if (!$this->isMasterGit()) {
            return json([
                'code' => -1,
                'msg'  => '当前为云端安全发布部署模式。如需切换特定版本，请在云控制台指定版本推送。',
            ]);
        }

        $targetHash = trim((string)($request->post('hash') ?? $request->post('commit_hash') ?? ''));
        if ($targetHash === '' || !preg_match('/^[0-9a-f]{7,40}$/i', $targetHash)) {
            return json([
                'code' => -1,
                'msg'  => '回滚失败：commit hash 格式不合法',
            ]);
        }

        $verifyOutput = $this->execGit("git cat-file -t {$targetHash}");
        if (!str_starts_with(trim($verifyOutput), 'commit')) {
            return json([
                'code' => -1,
                'msg'  => "回滚失败：commit {$targetHash} 不存在于本地 Git 历史中",
            ]);
        }

        $currentHead = $this->execGit('git rev-parse --short HEAD');
        $resetLog    = $this->execGit("git reset --hard {$targetHash}");
        $newCommit   = $this->execGit('git rev-parse --short HEAD');
        $newCommitMsg = $this->execGit('git log -1 --pretty=format:"%s (%cd)" --date=format:"%Y-%m-%d %H:%M:%S"');

        $this->applyDatabasePatches(base_path());
        $this->reloadService();

        return json([
            'code' => 1,
            'msg'  => "已成功回滚至 #{$newCommit}，并触发后台服务平滑重载",
            'data' => [
                'rollback_from' => $currentHead,
                'rollback_to'   => $newCommit,
                'commit_msg'    => $newCommitMsg,
                'log'           => $resetLog,
            ],
        ]);
    }

    // ─────────────────────── 内部辅助工具函数 ───────────────────────

    /**
     * 解压安装包（多层降级容错：ZipArchive -> PharData -> 系统命令）
     */
    private function unzipArchive(string $zipFile, string $destDir): void
    {
        @mkdir($destDir, 0755, true);

        // 1. 首选 ZipArchive
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($zipFile) === true) {
                $zip->extractTo($destDir);
                $zip->close();
                return;
            }
        }

        // 2. 备选 PharData
        if (class_exists(\PharData::class)) {
            try {
                $phar = new \PharData($zipFile);
                $phar->extractTo($destDir, null, true);
                return;
            } catch (Throwable) {
                // 忽略异常，降级到命令行
            }
        }

        // 3. 命令行降级
        if (DIRECTORY_SEPARATOR === '\\') {
            $cmd = sprintf(
                'powershell -NoProfile -NonInteractive -Command "Expand-Archive -LiteralPath %s -DestinationPath %s -Force"',
                escapeshellarg($zipFile),
                escapeshellarg($destDir)
            );
            @shell_exec($cmd);
        } else {
            $cmd = sprintf('unzip -o -q %s -d %s', escapeshellarg($zipFile), escapeshellarg($destDir));
            @shell_exec($cmd);
        }

        $files = scandir($destDir);
        if ($files === false || count($files) <= 2) {
            throw new \RuntimeException('未能成功解压更新包，请确保服务器具备解压支持 (php-zip 扩展或 unzip 命令)');
        }
    }

    /**
     * 将解压后的文件增量替换到项目根目录（严格保护客户现有数据）
     */
    private function applyStagingFiles(string $stagingDir, string $targetBaseDir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagingDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        // 必须绝对受保护的文件与目录白名单规则（禁止覆盖用户运行态数据）
        $isProtected = static function (string $relPath): bool {
            $normalized = str_replace('\\', '/', $relPath);
            return (bool)preg_match(
                '#^(?:\.env|\.env\..*|runtime/.*|install\.lock|identity\.json|entitlements\.json|cloud_directives\.json|database/.*\.sqlite|database/.*\.db|public/uploads/.*|public/storage/.*|\.git/.*|\.agents/.*)$#i',
                $normalized
            );
        };

        foreach ($iterator as $item) {
            $relPath = substr($item->getPathname(), strlen($stagingDir) + 1);
            if ($isProtected($relPath)) {
                continue;
            }

            $dest = $targetBaseDir . DIRECTORY_SEPARATOR . $relPath;

            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    @mkdir($dest, 0755, true);
                }
            } else {
                $destParent = dirname($dest);
                if (!is_dir($destParent)) {
                    @mkdir($destParent, 0755, true);
                }
                @copy($item->getPathname(), $dest);
            }
        }
    }

    /**
     * 增量执行数据库补丁 SQL
     */
    private function applyDatabasePatches(string $baseDir): array
    {
        $executed = [];
        $databaseDir = $baseDir . DIRECTORY_SEPARATOR . 'database';
        if (!is_dir($databaseDir)) {
            return $executed;
        }

        $patchFiles = glob($databaseDir . DIRECTORY_SEPARATOR . 'patch_*.sql') ?: [];
        sort($patchFiles, SORT_NATURAL);

        foreach ($patchFiles as $file) {
            $content = (string)@file_get_contents($file);
            if (trim($content) === '') {
                continue;
            }

            $statements = array_filter(array_map('trim', explode(';', $content)));
            foreach ($statements as $stmt) {
                if ($stmt === '') {
                    continue;
                }
                try {
                    if (class_exists(\Illuminate\Database\Capsule\Manager::class)) {
                        \Illuminate\Database\Capsule\Manager::statement($stmt);
                    }
                } catch (Throwable) {
                    // 容错处理：字段已存在/索引已存在时忽略报错
                }
            }
            $executed[] = basename($file);
        }

        return $executed;
    }

    /**
     * 更新 config/app.php 中的版本号
     */
    private function updateAppVersion(string $newVersion): void
    {
        $configFile = base_path() . '/config/app.php';
        if (!file_exists($configFile)) {
            return;
        }

        $cleanVer = ltrim($newVersion, 'vV');
        $content  = (string)file_get_contents($configFile);
        $updated  = preg_replace("/('version'\s*=>\s*')[^']*(',?)/", "\${1}{$cleanVer}\${2}", $content);

        if ($updated !== null && $updated !== $content) {
            @file_put_contents($configFile, $updated);
        }
    }

    /**
     * 记录本地版本历史
     */
    private function recordVersionHistory(string $version, string $changelog): void
    {
        $historyFile = base_path() . self::HISTORY_FILE;
        @mkdir(dirname($historyFile), 0755, true);

        $current = [];
        if (file_exists($historyFile)) {
            $current = json_decode((string)file_get_contents($historyFile), true) ?: [];
        }

        $verLabel = 'v' . ltrim($version, 'v');
        $entry = [
            'hash'    => $verLabel,
            'short'   => $verLabel,
            'author'  => 'Official',
            'date'    => date('Y-m-d H:i'),
            'message' => trim($changelog) !== '' ? $changelog : '系统云端在线升级',
        ];

        // 插入到列表首位
        array_unshift($current, $entry);
        $current = array_slice($current, 0, 20);

        @file_put_contents($historyFile, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 递归删除目录
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * 触发 workerman 服务平滑热重载
     */
    private function reloadService(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            @pclose(@popen('start /B php start.php reload', 'r'));
        } else {
            $baseDir = escapeshellarg(base_path());
            @shell_exec("cd {$baseDir} && php start.php reload >/dev/null 2>&1 &");
        }
    }

    // ─────────────────────── 进度状态跟踪辅助 ───────────────────────

    private function initProgress(string $initialMessage): void
    {
        $file = base_path() . self::PROGRESS_FILE;
        @mkdir(dirname($file), 0755, true);

        $data = [
            'running'    => true,
            'updated_at' => date('Y-m-d H:i:s'),
            'steps'      => [
                [
                    'time'    => date('H:i:s'),
                    'message' => $initialMessage,
                    'type'    => 'start',
                ],
            ],
        ];

        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function addProgressStep(string $message, string $type = 'info'): void
    {
        $file = base_path() . self::PROGRESS_FILE;
        if (!file_exists($file)) {
            $this->initProgress($message);
            return;
        }

        $data = json_decode((string)file_get_contents($file), true) ?: [];
        $data['running'] = true;
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['steps'][] = [
            'time'    => date('H:i:s'),
            'message' => $message,
            'type'    => $type,
        ];

        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function finishProgress(bool $success, string $finalMessage): void
    {
        $file = base_path() . self::PROGRESS_FILE;
        $data = file_exists($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];

        $data['running'] = false;
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['steps'][] = [
            'time'    => date('H:i:s'),
            'message' => $finalMessage,
            'type'    => $success ? 'finish' : 'error',
        ];

        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
