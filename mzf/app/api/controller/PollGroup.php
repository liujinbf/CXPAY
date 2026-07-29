<?php

namespace app\api\controller;

use app\common\controller\Frontend;
use app\common\model\PayPollGroup;
use app\common\model\PayPollGroupChannel;
use app\common\model\PayChannel;
use app\common\model\PayCtype;

/**
 * 商户中心 - 轮询规则组（轮询池）
 *
 * 商户按支付方式建规则（模式 random/weight/priority），给规则分配通道+权重；
 * 下单时（OrderService::pickChannel）取该支付方式当前启用规则，在规则内通道按模式选通道。
 * 所有操作以 $this->auth->id 限定本商户。
 */
class PollGroup extends Frontend
{
    protected array $noNeedLogin = [];

    protected const TYPES = ['alipay', 'wxpay', 'qqpay'];
    protected const MODES = ['random', 'weight', 'priority'];

    /** 规则列表 */
    public function index(): void
    {
        $uid  = $this->auth->id;
        $rows = PayPollGroup::where('uid', $uid)->order('weigh desc, id desc')->select()->toArray();
        // 每规则通道数
        $counts = [];
        foreach (PayPollGroupChannel::whereIn('group_id', array_column($rows, 'id') ?: [0])->select() as $c) {
            $counts[$c['group_id']] = ($counts[$c['group_id']] ?? 0) + 1;
        }
        $catNames = ['alipay' => '支付宝', 'wxpay' => '微信', 'qqpay' => 'QQ'];
        foreach ($rows as &$r) {
            $r['type_name']    = $catNames[$r['type']] ?? $r['type'];
            $r['channel_count'] = $counts[$r['id']] ?? 0;
        }
        $this->success('', ['list' => $rows]);
    }

    /** 新增/编辑规则 */
    public function save(): void
    {
        if (!$this->request->isPost()) $this->error('参数错误');
        $uid   = $this->auth->id;
        $id    = (int) $this->request->param('id', 0);
        $name  = trim((string) $this->request->param('name', ''));
        $type  = (string) $this->request->param('type', '');
        $mode  = (string) $this->request->param('mode', 'random');
        $status = (int) $this->request->param('status', 1) ? 1 : 0;
        $notes = trim((string) $this->request->param('notes', ''));
        $weigh = (int) $this->request->param('weigh', 0);

        if ($name === '') $this->error('规则名称不能为空');
        if (mb_strlen($name) > 60) $this->error('规则名称过长');
        if (!in_array($type, self::TYPES, true)) $this->error('支付方式不合法');
        if (!in_array($mode, self::MODES, true)) $this->error('轮询模式不合法');
        if (mb_strlen($notes) > 255) $this->error('备注过长');

        if ($id > 0) {
            $g = PayPollGroup::where(['id' => $id, 'uid' => $uid])->find();
            if (!$g) $this->error('规则不存在');
            // 改了支付方式则清空原通道关联（类型不匹配）
            if ($g->type !== $type) {
                PayPollGroupChannel::where('group_id', $id)->delete();
            }
        } else {
            $g = new PayPollGroup();
            $g->uid = $uid;
        }
        $g->name   = $name;
        $g->type   = $type;
        $g->mode   = $mode;
        $g->status = $status;
        $g->notes  = $notes;
        $g->weigh  = $weigh;
        $g->save();
        $this->success('保存成功', ['id' => $g->id]);
    }

    /** 删除规则 */
    public function del(): void
    {
        if (!$this->request->isPost()) $this->error('参数错误');
        $id = (int) $this->request->param('id', 0);
        $g  = PayPollGroup::where(['id' => $id, 'uid' => $this->auth->id])->find();
        if (!$g) $this->error('规则不存在');
        PayPollGroupChannel::where('group_id', $id)->delete();
        $g->delete();
        $this->success('已删除');
    }

    /** 切换启用状态 */
    public function toggle(): void
    {
        if (!$this->request->isPost()) $this->error('参数错误');
        $id = (int) $this->request->param('id', 0);
        $g  = PayPollGroup::where(['id' => $id, 'uid' => $this->auth->id])->find();
        if (!$g) $this->error('规则不存在');
        $g->status = filter_var($this->request->param('status'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $g->save();
        $this->success($g->status ? '已启用' : '已停用');
    }

    /** 某规则的已分配通道 + 可分配通道（同 type，本商户） */
    public function channels(): void
    {
        $uid = $this->auth->id;
        $gid = (int) $this->request->param('group_id', 0);
        $g   = PayPollGroup::where(['id' => $gid, 'uid' => $uid])->find();
        if (!$g) $this->error('规则不存在');

        $typeNames = PayCtype::column('name', 'c_type');
        $weights   = PayPollGroupChannel::where('group_id', $gid)->column('weight', 'channel_id'); // channel_id => weight

        $chs = PayChannel::where(['uid' => $uid, 'type' => $g->type])->order('id desc')
            ->field('id,c_type,notes,status')->select()->toArray();
        $assigned = [];
        $available = [];
        foreach ($chs as $c) {
            $item = [
                'id'          => (int) $c['id'],
                'c_type_name' => $typeNames[$c['c_type']] ?? $c['c_type'],
                'notes'       => (string) $c['notes'],
                'weight'      => (int) ($weights[$c['id']] ?? 1),
                'checked'     => isset($weights[$c['id']]),
            ];
            $item['checked'] ? $assigned[] = $item : $available[] = $item;
        }
        $this->success('', [
            'group'    => ['id' => $g->id, 'name' => $g->name, 'type' => $g->type, 'mode' => $g->mode],
            'channels' => array_merge($assigned, $available),
        ]);
    }

    /** 保存规则的通道集合（整表替换） */
    public function saveChannels(): void
    {
        if (!$this->request->isPost()) $this->error('参数错误');
        $uid   = $this->auth->id;
        $gid   = (int) $this->request->param('group_id', 0);
        $items = $this->request->param('items', []);
        $g     = PayPollGroup::where(['id' => $gid, 'uid' => $uid])->find();
        if (!$g) $this->error('规则不存在');
        if (!is_array($items)) $items = [];

        // 本商户同 type 通道白名单
        $validIds = PayChannel::where(['uid' => $uid, 'type' => $g->type])->column('id');
        $validIds = array_map('intval', $validIds);

        PayPollGroupChannel::where('group_id', $gid)->delete();
        $rows = [];
        foreach ($items as $it) {
            $cid = (int) ($it['channel_id'] ?? 0);
            if (!in_array($cid, $validIds, true)) continue;
            $w = (int) ($it['weight'] ?? 1);
            if ($w < 1) $w = 1;
            if ($w > 999) $w = 999;
            $rows[] = ['group_id' => $gid, 'channel_id' => $cid, 'weight' => $w];
        }
        if ($rows) {
            (new PayPollGroupChannel())->saveAll($rows);
        }
        $this->success('通道已保存', ['count' => count($rows)]);
    }
}
