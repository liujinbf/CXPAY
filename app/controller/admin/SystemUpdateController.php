<?php

declare(strict_types=1);

namespace app\controller\admin;

use support\Response;

/**
 * 系统更新安全占位控制器。
 *
 * 在可信发布源、包签名、原子部署与可验证回滚链路完成前，禁止从 Web 进程
 * 拉取代码、覆盖工作区、运行数据库补丁或执行系统命令。
 */
final class SystemUpdateController
{
    public function checkUpdate(): Response
    {
        return json([
            'code' => -1,
            'msg' => '在线更新已禁用，请通过受控 CI/CD 流程发布版本',
            'data' => [
                'has_update' => false,
                'local_ver' => $this->localVersion(),
                'mode' => 'disabled',
            ],
        ]);
    }

    public function doUpdate(): Response
    {
        return $this->disabled();
    }

    public function pollProgress(): Response
    {
        return json(['code' => 1, 'msg' => '在线更新未运行', 'data' => ['running' => false, 'steps' => []]]);
    }

    public function getUpdateLog(): Response
    {
        return json(['code' => 1, 'msg' => '在线更新未启用', 'data' => ['log' => '无在线更新日志']]);
    }

    public function versionHistory(): Response
    {
        return json(['code' => -1, 'msg' => '版本历史由外部版本控制与 CI/CD 系统管理', 'data' => ['commits' => []]]);
    }

    public function doRollback(): Response
    {
        return $this->disabled('在线回滚');
    }

    private function disabled(string $operation = '在线更新'): Response
    {
        return json([
            'code' => -1,
            'msg' => "{$operation}已禁用，请通过受控 CI/CD 与备份恢复流程操作",
        ])->withStatus(501);
    }

    private function localVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            return \Composer\InstalledVersions::getRootPackage()['pretty_version'] ?? '开发版本';
        }
        return '开发版本';
    }
}
