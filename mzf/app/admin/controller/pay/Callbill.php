<?php

namespace app\admin\controller\pay;

use app\common\controller\Backend;
use app\common\model\PayCallbill as CallbillModel;
use app\common\model\PayChannel;
use app\common\model\PayCtype;
use app\admin\model\User;

/**
 * 到账账单（只读：由挂机推送/回调产生，后台仅查看/删除）
 */
class Callbill extends Backend
{
    protected object $model;

    protected string|array $quickSearchField = ['id'];

    protected string|array $preExcludeFields = ['id', 'create_time', 'update_time'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new CallbillModel();
    }

    /**
     * 列表：附加 商户PID(用户名+对接PID) 与 所属通道名称
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }
        [$where, $alias, $limit, $order] = $this->queryBuilder();
        $res = $this->model->alias($alias)->where($where)->order($order)->paginate($limit);

        $items = $res->items();
        // 商户：uid → 用户名(对接PID)
        $uids     = array_values(array_filter(array_unique(array_column($items, 'uid'))));
        $userInfo = $uids ? User::whereIn('id', $uids)->column('username,pid', 'id') : [];
        // 所属通道：channel_id → PayChannel.c_type → PayCtype.name
        $chIds      = array_values(array_filter(array_unique(array_column($items, 'channel_id'))));
        $chTypes    = $chIds ? PayChannel::whereIn('id', $chIds)->column('c_type', 'id') : [];
        $ctypeNames = PayCtype::column('name', 'c_type');
        $ctypeTypes = PayCtype::column('type', 'c_type'); // c_type → 支付方式(alipay/wxpay/qqpay)
        foreach ($items as $it) {
            $uid = (int) $it['uid'];
            $it->uid_text = isset($userInfo[$uid])
                ? $userInfo[$uid]['username'] . ' (' . $userInfo[$uid]['pid'] . ')'
                : (string) $uid;
            $cType = $chTypes[$it['channel_id']] ?? '';
            $it->channel_name = $ctypeNames[$cType] ?? ($cType ?: '-');
            $it->pay_type     = $ctypeTypes[$cType] ?? '';
        }

        $this->success('', [
            'list'   => $items,
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    public function add(): void
    {
        $this->error('账单由系统产生，不支持后台新增');
    }

    public function edit(): void
    {
        $this->error('账单不支持编辑');
    }
}
