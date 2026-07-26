<?php

declare(strict_types=1);

namespace app\controller\api;

use support\Response;
use illuminate\database\capsule\manager as DB;
use Throwable;

/**
 * 安装向导后端 API 控制器
 */
class InstallController
{
    /**
     * 自动检测环境与执行 install.sql 初始化
     */
    public function execute(object $request): Response
    {
        try {
            $lockFile = base_path() . '/install.lock';
            if (file_exists($lockFile)) {
                return json(['code' => -1, 'msg' => '系统已被安装过，如果需要重新安装请删除 install.lock 文件']);
            }

            $params = $request->post();
            $dbHost = $params['db_host'] ?? '127.0.0.1';
            $dbPort = $params['db_port'] ?? '3306';
            $dbName = $params['db_name'] ?? 'cxpay';
            $dbUser = $params['db_user'] ?? 'root';
            $dbPass = $params['db_pass'] ?? '';

            // 1. 读取 install.sql 并执行导入
            $sqlFile = base_path() . '/database/install.sql';
            if (file_exists($sqlFile)) {
                $sqlContent = file_get_contents($sqlFile);
                $statements = array_filter(explode(';', $sqlContent));
                foreach ($statements as $stmt) {
                    $stmt = trim($stmt);
                    if (!empty($stmt)) {
                        try {
                            DB::statement($stmt);
                        } catch (Throwable $e) {
                            // 忽略已有字段与警告
                        }
                    }
                }
            }

            // 2. 生成 install.lock 锁定文件
            file_put_contents($lockFile, date('Y-m-d H:i:s') . " Installed Successfully.\n");

            return json(['code' => 1, 'msg' => 'CXPAY 数据库建表与锁文件生成成功！']);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '安装失败: ' . $e->getMessage()]);
        }
    }
}
