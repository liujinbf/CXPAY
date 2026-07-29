<?php

namespace app\openapi\controller;

use app\BaseController;
use app\admin\model\User;
use app\common\model\PayChannel;
use app\common\model\PayOrder;
use app\common\model\PayCallbill;
use app\core\SettlementService;
use think\response\Json;

/**
 * 挂机小助手回调入口（PEAK 小助手 / APP）
 *
 * 移植旧 openapi/AsstPushController，但改进为「单一结算路径」：
 *   推送只写到账账单 + 即时触发结算(settleOne)，不再内联复制扣费逻辑，
 *   避免与定时结算 double-entry。签名沿用 md5 方案（asst_key）。
 *
 * 路由（自动多应用）：
 *   /openapi/Asst/heart  心跳（md5(t.asst_key)==sign）
 *   /openapi/Asst/push   到账推送（md5(type.price.t.asst_key)==sign）
 */
class Asst extends BaseController
{
    /**
     * 心跳：刷新通道在线时间。
     * 响应须与旧 AsstPushController::actionasstHeart 一致（挂机 APP 按文本「心跳成功」判活）。
     */
    public function heart(): \think\Response
    {
        $id   = $this->request->param('id');
        $sign = $this->request->param('sign', '');
        $t    = $this->request->param('t', '');

        $channel = PayChannel::where('id', $id)->find();
        if (!$channel) {
            return response('通道不存在');
        }
        $asstKey = $this->resolveAsstKey((int) $channel->uid);
        if (md5($t . $asstKey) !== $sign) {
            return response('签名验证失败');
        }

        PayChannel::where('id', $channel->id)->update([
            'check_time' => time(),
            'status'     => 1,
        ]);
        return response('心跳成功');
    }

    /**
     * 到账推送：写账单 + 即时结算。
     * 响应与旧 AsstPushController::actionasstPush 一致（JSON code 200/201/-1）。
     */
    public function push(): Json
    {
        $id    = $this->request->param('id');
        $sign  = $this->request->param('sign', '');
        $t     = $this->request->param('t', '');
        $type  = $this->request->param('type', '');
        $price = $this->request->param('price', '');

        $channel = PayChannel::where('id', $id)->find();
        if (!$channel) {
            return json(['code' => -1, 'msg' => '通道不存在']);
        }
        $asstKey = $this->resolveAsstKey((int) $channel->uid);

        if (md5($type . $price . $t . $asstKey) !== $sign) {
            return json(['code' => -1, 'msg' => '回调签名验证失败']);
        }
        if (!is_numeric($price) || $price <= 0) {
            return json(['code' => -1, 'msg' => 'price error']);
        }

        // 写到账账单（去重：5s 内同 uid+channel+price 视为重复推送，跳过入库）
        // 修复：APP/小助手重试/并发推送导致一笔到账产多条账单 → 残留账单错配新订单。
        // push 无 config（流水号），用时间窗去重；窗口取 5s 平衡防重与误判。
        $dedup = PayCallbill::where([
            'uid'        => $channel->uid,
            'channel_id' => $channel->id,
            'price'      => $price,
        ])->where('create_time', '>', time() - 5)->find();

        if (!$dedup) {
            $bill = new PayCallbill();
            $bill->save([
                'uid'        => $channel->uid,
                'channel_id' => $channel->id,
                'price'      => $price,
                'status'     => 0,
            ]);
        }

        // 即时结算：匹配同 通道+price 的未支付订单（且下单早于到账）
        $order = PayOrder::where([
            'channel_id' => $channel->id,
            'price'      => $price,
            'status'     => 0,
        ])->where('create_time', '<=', time())->order('create_time asc')->find();

        if ($order) {
            $res = (new SettlementService())->settleOne($order->toArray());
            if ($res !== null) {
                // 兜底：作废同价所有残留账单（防 push 并发/重试产生的重复账单）。
                // 移植旧 AsstPushController::actionasstPush line 69 的防御逻辑：
                // UPDATE ... WHERE uid+channel_id+price（无 id 条件）全作废，避免残留账单错配后续订单。
                PayCallbill::where([
                    'uid'        => $channel->uid,
                    'channel_id' => $channel->id,
                    'price'      => $price,
                    'status'     => 0,
                ])->update(['status' => 2]);

                return json(['code' => 200, 'msg' => '回调成功', 'data' => [
                    'out_trade_no' => $order->trade_no,
                    'name'         => $order->name ?? '',
                ]]);
            }
        }

        // 未匹配到订单：将当前 push 的账单标记为已处理（status=2），避免后续误匹配。
        // 注意：此时可能有并发 push 写入的重复账单，也一并作废。
        PayCallbill::where([
            'uid'        => $channel->uid,
            'channel_id' => $channel->id,
            'price'      => $price,
            'status'     => 0,
        ])->where('create_time', '>', time() - 10)->update(['status' => 2]);

        return json(['code' => 201, 'msg' => '未匹配到订单']);
    }

    /**
     * 取商户 asst_key（清理零宽/空白字符），无则回退 pay_key
     */
    protected function resolveAsstKey(int $uid): string
    {
        $user = \app\admin\model\User::find($uid);
        $key = $user ? ($user->asst_key ?: $user->pay_key) : '';
        $key = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string) $key);
        $key = str_replace(["\r", "\n", ' ', "\t", "'", '"', '`'], '', trim($key));
        return $key;
    }
}
