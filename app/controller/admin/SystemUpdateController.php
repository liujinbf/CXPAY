<?php

declare(strict_types=1);

namespace app\controller\admin;

use support\Response;
use support\Request;

/**
 * 系统 Git 在线更新与热重启控制器
 */
final class SystemUpdateController
{
    /**
     * 检查 Git 远端代码更新状态与 Commit 信息
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
     * 检查 Git 远端代码更新状态与 Commit 信息
     */
    public function checkUpdate(Request $request): Response
    {
        $branch = $this->execGit('git rev-parse --abbrev-ref HEAD');
        $commit = $this->execGit('git rev-parse --short HEAD');
        $commitMsg = $this->execGit('git log -1 --pretty=format:"%s (%cd)" --date=format:"%Y-%m-%d %H:%M:%S"');

        // 执行 fetch 获取远端最新 commit 引用
        $this->execGit('git fetch');
        $behindCount = $this->execGit('git rev-list --count HEAD..@{u}');

        $hasUpdate = is_numeric($behindCount) && (int)$behindCount > 0;

        return json([
            'code' => 1,
            'msg' => $hasUpdate ? "检测到远端有 {$behindCount} 个待更新 Commit" : "当前代码已是最新版本",
            'data' => [
                'has_update' => $hasUpdate,
                'behind_count' => is_numeric($behindCount) ? (int)$behindCount : 0,
                'branch' => $branch ?: 'main',
                'commit' => $commit ?: 'unknown',
                'commit_msg' => $commitMsg ?: '无 Commit 历史记录',
                'local_ver' => $commit ? "Git #{$commit}" : 'v1.0.0'
            ],
        ]);
    }

    /**
     * 执行 Git 拉取 (git pull) 并平滑重启进程
     */
    public function doUpdate(Request $request): Response
    {
        $pullLog = $this->execGit('git pull');
        $newCommit = $this->execGit('git rev-parse --short HEAD');
        $newCommitMsg = $this->execGit('git log -1 --pretty=format:"%s (%cd)" --date=format:"%Y-%m-%d %H:%M:%S"');

        // 后台触发热重启
        if (DIRECTORY_SEPARATOR === '\\') {
            @pclose(@popen("start /B php start.php reload", "r"));
        } else {
            $baseDir = escapeshellarg(base_path());
            @shell_exec("cd {$baseDir} && php start.php reload >/dev/null 2>&1 &");
        }

        return json([
            'code' => 1,
            'msg' => '代码已成功从 Git 远端拉取并触发后台进程重载！',
            'data' => [
                'log' => $pullLog ?: 'Already up to date.',
                'new_commit' => $newCommit,
                'new_commit_msg' => $newCommitMsg
            ],
        ]);
    }

    /**
     * 获取最新版本 Commit 历史记录 (近10条)
     */
    public function versionHistory(Request $request): Response
    {
        $baseDir = base_path();
        $rawLogs = @shell_exec("cd /d \"{$baseDir}\" && git log -10 --pretty=format:\"%h|%an|%cd|%s\" --date=format:\"%Y-%m-%d %H:%M\" 2>&1");
        $commits = [];
        if ($rawLogs) {
            foreach (explode("\n", trim($rawLogs)) as $line) {
                $parts = explode("|", $line);
                if (count($parts) >= 4) {
                    $commits[] = [
                        'hash' => $parts[0],
                        'author' => $parts[1],
                        'date' => $parts[2],
                        'msg' => $parts[3]
                    ];
                }
            }
        }
        return json(['code' => 1, 'msg' => '获取成功', 'data' => ['commits' => $commits]]);
    }

    public function pollProgress(Request $request): Response
    {
        return json(['code' => 1, 'msg' => '更新完成', 'data' => ['running' => false]]);
    }

    public function getUpdateLog(Request $request): Response
    {
        return json(['code' => 1, 'msg' => '获取成功', 'data' => ['log' => '系统通过 Git 管理与自动重载']]);
    }

    public function doRollback(Request $request): Response
    {
        return json(['code' => -1, 'msg' => '若需回滚版本，请在 Git 中执行 reset/revert 或推送前置 commit']);
    }
}
