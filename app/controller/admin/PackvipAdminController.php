<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Packvip;
use support\Response;
use Exception;

/**
 * 管理员后台 VIP 套餐配置 API 控制器
 */
class PackvipAdminController
{
    /**
     * VIP 套餐列表
     */
    public function list(): string
    {
        $vips = Packvip::orderBy('weigh', 'desc')->get();
        return json_encode(['code' => 1, 'data' => $vips], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 新增 / 保存 VIP 套餐
     */
    public function save(object $request): string
    {
        $params = $request->post();
        $id     = $params['id'] ?? null;

        $data = [
            'title'     => $params['title'] ?? 'VIP 套餐',
            'rate'      => (float)($params['rate'] ?? 1.80),
            'mini_rate' => (float)($params['mini_rate'] ?? 0.01),
            'weigh'     => (int)($params['weigh'] ?? 0),
        ];

        if ($id) {
            Packvip::where('id', $id)->update($data);
            $msg = 'VIP 套餐更新成功';
        } else {
            Packvip::create($data);
            $msg = 'VIP 套餐创建成功';
        }

        return json_encode(['code' => 1, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    }
}
