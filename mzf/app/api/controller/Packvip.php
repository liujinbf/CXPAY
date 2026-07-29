<?php

namespace app\api\controller;

use Throwable;
use think\facade\Db;
use app\common\controller\Frontend;
use app\common\model\PayPackvip;
use app\admin\model\User;

/**
 * 商户中心 - 会员套餐（浏览 + 购买）
 */
class Packvip extends Frontend
{
    protected array $noNeedLogin = [];

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * 可购买套餐列表
     */
    public function index(): void
    {
        $list = PayPackvip::where('status', 1)->order('weigh desc')
            ->field('id,name,days,rate,mini_rate,channel_quota,price,notes')
            ->select();
        $this->success('', ['list' => $list]);
    }

    /**
     * 购买套餐：余额扣款 + 延长会员期 + 设置配额/费率套餐
     */
    public function buy(): void
    {
        if (!$this->request->isPost()) $this->error('参数错误');
        $uid   = $this->auth->id;
        $id    = (int) $this->request->param('id');
        $force = (int) $this->request->param('force', 0);

        $pack = PayPackvip::where(['id' => $id, 'status' => 1])->find();
        if (!$pack) $this->error('套餐不存在或已下架');

        $user = User::where('id', $uid)->find();
        if (!$user) $this->error('会员不存在');

        // 检测套餐切换：如果当前有套餐且套餐ID不同，需要二次确认
        $isSwitching = false;
        if ($user->packvip_id && $user->packvip_id != $pack->id && $user->packvip_time > time()) {
            $currentPack = PayPackvip::where('id', $user->packvip_id)->find();
            $remainDays  = ceil(($user->packvip_time - time()) / 86400);

            if (!$force) {
                // 返回确认信息，前端需要再次发起请求并带上 force=1
                $this->success('', [
                    'need_confirm' => true,
                    'message'      => sprintf(
                        '您当前是【%s】套餐，剩余 %d 天。切换为【%s】后，原套餐剩余时间将失效，新套餐从今天开始计算 %d 天，是否继续？',
                        $currentPack ? $currentPack->name : '未知套餐',
                        $remainDays,
                        $pack->name,
                        $pack->days
                    ),
                ]);
                return;
            }
            $isSwitching = true;
        }

        $priceCents = (int) round(((float) $pack->price) * 100);

        Db::startTrans();
        try {
            $user = User::where('id', $uid)->lock(true)->find();
            if (!$user) throw new \Exception('会员不存在');

            $beforeCents = (int) Db::name('user')->where('id', $uid)->lock(true)->value('money');
            if ($beforeCents < $priceCents) {
                throw new \Exception('余额不足，请先充值');
            }

            // 扣款
            if ($priceCents > 0) {
                $afterCents = $beforeCents - $priceCents;
                Db::name('user')->where('id', $uid)->update(['money' => $afterCents]);
                Db::name('user_money_log')->insert([
                    'user_id'     => $uid,
                    'money'       => -$priceCents,
                    'before'      => $beforeCents,
                    'after'       => $afterCents,
                    'memo'        => '购买套餐：' . $pack->name,
                    'create_time' => time(),
                ]);
            }

            // 计算会员期：
            // - 切换套餐：从当前时间开始计算新套餐天数（覆盖旧套餐）
            // - 续费同套餐：从 max(now, 现有到期) 起累加天数
            if ($isSwitching) {
                $newTime = time() + ((int) $pack->days) * 86400;
            } else {
                $current = (int) $user->packvip_time;
                $base    = max(time(), $current);
                $newTime = $base + ((int) $pack->days) * 86400;
            }

            Db::name('user')->where('id', $uid)->update([
                'packvip_id'    => $pack->id,
                'packvip_time'  => $newTime,
                'channel_quota' => (int) $pack->channel_quota,
            ]);

            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }

        $msg = $isSwitching ? '套餐已切换' : '购买成功，会员已开通/续期';
        $this->success($msg);
    }
}
