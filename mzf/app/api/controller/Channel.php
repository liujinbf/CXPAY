<?php

namespace app\api\controller;

use Throwable;
use app\common\controller\Frontend;
use app\common\model\PayChannel;
use app\common\model\PayCtype;
use app\common\model\PayOrder;
use app\common\model\PayPackvip;
use app\admin\model\User;
use app\common\library\payment\PaymentManager;
use app\common\library\notify\NotifyService;
use app\common\service\QrDecodeService;

/**
 * 商户中心 - 通道管理（商户自助绑定/配置收款通道）
 *
 * 所有操作限定 uid = $this->auth->id；受商户 channel_quota 配额限制。
 * config 随 c_type 动态、authcode 加密存储（复用 PayChannel）。
 */
class Channel extends Frontend
{
    protected array $noNeedLogin = [];

    // 心跳超时（秒）：check_time 超过此时长未更新视为离线
    protected const HEARTBEAT_TIMEOUT = 120;

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * 本商户通道列表（含在线状态）
     */
    public function index(): void
    {
        $uid  = $this->auth->id;
        $list = PayChannel::where('uid', $uid)->order('id desc')->select()->toArray();

        $now      = time();
        $todayStart = strtotime(date('Y-m-d'));
        $pushTtl  = (int) \think\facade\Config::get('payment.push_heartbeat_timeout', self::HEARTBEAT_TIMEOUT);
        $typeNames = PayCtype::column('name', 'c_type'); // c_type => 通道昵称
        foreach ($list as &$r) {
            $cType = (string) ($r['c_type'] ?? '');
            // 通道昵称：ctype 友好名称，无则回退 c_type
            $r['c_type_name'] = $typeNames[$cType] ?? $cType;
            // 配置中的分步状态提示——仅审核/白名单/上线相关的才作为「审核/受限」原因展示，
            // 避免把普通保存提示(如「已保存APP助手二维码配置」)误判为受限。
            $cfg = PayChannel::decryptConfig($r['config'] ?? '');
            $msg = trim((string) ($cfg['msg'] ?? ''));
            $isReview = $msg !== '' && (
                mb_strpos($msg, '审核') !== false ||
                mb_strpos($msg, '白名单') !== false ||
                mb_strpos($msg, '上线') !== false
            );
            $r['status_msg'] = $isReview ? $msg : '';
            unset($r['config']); // 不外泄加密配置
            $mode  = 'none';
            try {
                if (PaymentManager::has($cType)) {
                    $mode = PaymentManager::make($cType)->monitorMode();
                }
            } catch (Throwable $e) {
            }
            // 在线判定按监控方式区分：
            //   push   —— 端上 APP 心跳，check_time 新鲜才在线（没挂 APP / 超时即离线）
            //   server —— 服务端主动监控维护 status
            //   none   —— 官方/易支付等 API 通道，启用即在线
            if ($mode === 'push') {
                $ct          = (int) ($r['check_time'] ?? 0);
                $r['online'] = $ct > 0 && $ct >= $now - $pushTtl;
            } else {
                $r['online'] = ((int) ($r['status'] ?? 0) === 1);
            }
            $r['online_text'] = $r['online'] ? '在线' : ($r['status_msg'] !== '' ? $r['status_msg'] : '离线');
            // 在线时长 / 掉线时间
            $ot = (int) ($r['online_time'] ?? 0);
            $r['online_secs'] = ($r['online'] && $ot > 0) ? ($now - $ot) : 0;
            $r['offline_at']  = (int) ($r['endtime'] ?? 0);
            // 是否为需扫码绑定 APP 的通道
            $r['need_app_bind'] = str_contains((string) $r['c_type'], 'app_asst');
            // 收款开关 + 限额 + 今日/累计收款统计（整合进通道管理）
            $cid = (int) $r['id'];
            $paidT = PayOrder::where(['channel_id' => $cid, 'status' => 1]);
            $r['tt_switch']    = ((string) ($r['tt_switch'] ?? 'true') === 'true');
            $r['all_money']    = number_format((float) (clone $paidT)->sum('money'), 2, '.', '');
            $r['all_order']    = (clone $paidT)->count();
            $r['today_money']  = number_format((float) (clone $paidT)->where('pay_time', '>=', $todayStart)->sum('money'), 2, '.', '');
            $r['today_order']  = (clone $paidT)->where('pay_time', '>=', $todayStart)->count();
            $r['all_money_max']   = (string) $r['all_money_max'];
            $r['today_money_max'] = (string) $r['today_money_max'];
        }
        unset($r);

        $this->success('', ['list' => $list]);
    }

    /**
     * 切换通道收款开关（tt_switch）——控制该通道是否参与轮询收款。
     */
    public function toggleSwitch(): void
    {
        if (!$this->request->isPost()) $this->error('参数错误');
        $id = (int) $this->request->param('id', 0);
        $ch = PayChannel::where(['id' => $id, 'uid' => $this->auth->id])->find();
        if (!$ch) $this->error('通道不存在');
        $on = filter_var($this->request->param('switch'), FILTER_VALIDATE_BOOLEAN);
        $ch->tt_switch = $on ? 'true' : 'false';
        $ch->save();
        $this->success($on ? '已开启收款' : '已关闭收款');
    }

    /**
     * 设置通道限额（0=不限）：累计/今日 金额与笔数上限，达到后自动退出轮询。
     */
    public function setLimit(): void
    {
        if (!$this->request->isPost()) $this->error('参数错误');
        $id = (int) $this->request->param('id', 0);
        $ch = PayChannel::where(['id' => $id, 'uid' => $this->auth->id])->find();
        if (!$ch) $this->error('通道不存在');

        $amMax = (float) $this->request->param('all_money_max', 0);
        $aoMax = (int) $this->request->param('all_order_max', 0);
        $tmMax = (float) $this->request->param('today_money_max', 0);
        $toMax = (int) $this->request->param('today_order_max', 0);
        if ($amMax < 0 || $tmMax < 0 || $aoMax < 0 || $toMax < 0) {
            $this->error('限额不能为负数');
        }
        $ch->all_money_max   = number_format($amMax, 2, '.', '');
        $ch->all_order_max   = $aoMax;
        $ch->today_money_max = number_format($tmMax, 2, '.', '');
        $ch->today_order_max = $toMax;
        $ch->save();
        $this->success('限额已保存');
    }

    /**
     * PEAK 小助手 APP 扫码绑定配置
     * 返回 qrdata = {type}/{host}/{asst_key}/{channel_id}，商户用监控 APP 扫码即绑定。
     */
    public function asstConfig(): void
    {
        $uid = $this->auth->id;
        $id  = $this->request->param('id');
        $channel = PayChannel::where(['id' => $id, 'uid' => $uid])->find();
        if (!$channel) {
            $this->error('通道不存在');
        }
        $user    = User::find($uid);
        $asstKey = $user ? ($user->asst_key ?: $user->pay_key) : '';
        if (!$asstKey) {
            $this->error('未配置通讯密钥，请联系管理员');
        }

        $type   = $channel->type ?: 'wxpay';
        $host   = $this->request->host(true);
        $qrdata = $type . '/' . $host . '/' . $asstKey . '/' . $channel->id;

        $this->success('', [
            'qrdata'     => $qrdata,
            'qr'         => \app\common\library\QrImage::dataUri($qrdata),
            'channel_id' => $channel->id,
            'cloud_url'  => \think\facade\Config::get('payment.cloud_url', 'https://cloud.iosle.com/'),
        ]);
    }

    /**
     * 可选通道类型（下拉）
     *
     * 仅返回「当前有效会员套餐已绑定」的通道：
     *   - 非会员 / 会员过期 → 空列表（前台无可添加通道）
     *   - 会员但套餐未绑定任何通道 → 空列表
     * 与 add() 的服务端校验保持一致，避免前台展示不可添加的通道。
     */
    public function ctypes(): void
    {
        $user = User::find($this->auth->id);
        // 非会员 / 会员已过期：无可选通道
        if (!$user || !$user->packvip_time || (int) $user->packvip_time < time()) {
            $this->success('', ['list' => []]);
        }
        $packvip   = $user->packvip_id ? PayPackvip::where('id', $user->packvip_id)->find() : null;
        $bindCtype = $packvip ? ($packvip->bind_ctype ?: []) : [];
        if (!$bindCtype) {
            $this->success('', ['list' => []]);
        }
        $list = PayCtype::where('status', 1)->whereIn('c_type', $bindCtype)
            ->order('weigh desc')->field('type,c_type,name,notes')->select();
        $this->success('', ['list' => $list]);
    }

    /**
     * 指定 c_type 的动态字段定义
     */
    public function getConfig(): void
    {
        $cType = $this->request->param('c_type', '');
        if (!$cType) $this->error('参数错误');
        $inputs = $meta = [];
        if (PaymentManager::has($cType)) {
            $meta   = PaymentManager::metadata($cType);
            $inputs = $meta['inputs'] ?? [];
        }
        $this->success('', [
            'c_type'        => $cType,
            'registered'    => PaymentManager::has($cType),
            'name'          => $meta['name'] ?? $cType,
            'note'          => $meta['note'] ?? '',
            'switch_qr_url' => (bool) ($meta['switch_qr_url'] ?? false),
            'getqrlogin'    => $meta['getqrlogin'] ?? false,
            'software'      => $meta['software'] ?? null,
            'inputs'        => $inputs,
            'cloud_url'     => \think\facade\Config::get('payment.cloud_url', 'https://cloud.iosle.com/'),
        ]);
    }

    /**
     * PC 挂机软件下载地址（按登录用户实时生成，1:1 移植旧 actionWechat_Pc_Down）
     *
     * - wxpay_msg_afk_pc：静态安装包 {cloud_url}Zip/PeakWin.zip
     * - 其它(book/recpt)：{cloud_url}Apis/Wechat_Pc.php?act=Download&my=installer
     *   &Url={base64(scheme://host)}&Id={uid}&Password={pay_key}
     *   （云端下载时把 Id/Password/Url 打包进安装包，运行即自动对接本站）
     */
    public function wechatPcDown(): void
    {
        $cType = (string) $this->request->param('c_type', '');
        $user  = User::find($this->auth->id);
        if (!$user) {
            $this->error('用户不存在');
        }

        $dir = public_path() . 'download' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            $this->error('下载目录不可写：' . $dir);
        }

        $product = $cType === 'wxpay_msg_afk_pc' ? 'wechat_mb' : 'wechat_pc';
        $data    = [
            'product'     => $product,
            'site_url'    => $this->request->domain(),
            'merchant_id' => (string) $user->id,
            'pay_key'     => (string) $user->pay_key,
        ];
        $fileName = $product . '_' . $user->id . '_' . md5($user->id . microtime(true)) . '.zip';
        $savePath = $dir . $fileName;

        $res = \app\core\CloudClient::download('software.download', $data, $savePath);
        if (!$res['ok']) {
            $this->error($res['msg'] ?: '获取安装包失败，请稍后再试');
        }

        $this->success('获取下载地址成功,点击确定开始下载', ['download_url' => '/download/' . $fileName]);
    }

    /**
     * 扫码登录：获取登录二维码（支持 alipay / yydopen）
     */
    public function getqrlogin(): void
    {
        $type = $this->request->param('type', 'alipay');

        if ($type === 'alipay') {
            $res = (new \app\common\library\payment\lib\AlipayLogin())->getqrlogin();
            if (($res['code'] ?? 0) != 1) {
                $this->error($res['msg'] ?? '获取登录二维码失败');
            }
            $this->success('', [
                'id'     => $res['id'],
                'qrdata' => $res['qrdata'],
                'qr'     => \app\common\library\QrImage::dataUri($res['qrdata']),
            ]);
        } elseif ($type === 'yydopen') {
            $res = (new \app\common\library\payment\lib\ProtocolCloud())->getqrlogin(true);
            if (($res['code'] ?? 0) != 1) {
                $this->error($res['msg'] ?? '获取登录二维码失败');
            }
            // yydopen 返回 session_id + image_base64（已包含 data URI 前缀）
            $this->success('', [
                'id'     => $res['data']['session_id'] ?? '',
                'qrdata' => $res['data']['session_id'] ?? '',
                'qr'     => $res['data']['image_base64'] ?? '',
            ]);
        } else {
            $this->error('暂不支持该登录方式');
        }
    }

    /**
     * 扫码登录：轮询扫码状态（支持 alipay / yydopen）
     * alipay 返回 data.code：0=等待扫码 1=已扫待确认 2=登录成功(带cookie) -1=失败
     * yydopen 返回 data：{status: 'pending|scanned|confirmed|expired|cancelled', ...}
     */
    public function verifyqrlogin(): void
    {
        $type = $this->request->param('type', 'alipay');
        $id   = $this->request->param('id', '');

        if (!$id) {
            $this->error('参数错误');
        }

        if ($type === 'alipay') {
            $res = (new \app\common\library\payment\lib\AlipayLogin())->verifyqrlogin($id);
            $this->success('', $res);
        } elseif ($type === 'yydopen') {
            $cloudClient = new \app\common\library\payment\lib\ProtocolCloud();
            $pollRes = $cloudClient->pollQr($id);

            // 如果轮询失败（超时等），返回等待状态而不是报错，让前端继续轮询
            if (($pollRes['code'] ?? 0) != 1) {
                // 检查是否是超时错误
                $errMsg = $pollRes['msg'] ?? '';
                if (strpos($errMsg, 'timeout') !== false || strpos($errMsg, 'deadline exceeded') !== false) {
                    // 超时：返回等待扫码状态，让前端继续轮询
                    $this->success('', ['code' => 0]);
                    return; // 直接返回，不继续执行
                } else {
                    // 其他错误：报错
                    $this->error($pollRes['msg'] ?? '轮询失败');
                }
            }

            $status = $pollRes['data']['status'] ?? 'pending';

            // 转换为前端期望的格式（兼容支付宝格式）
            // yydopen 可能返回 'authorized' 或 'confirmed' 状态
            if ($status === 'confirmed' || $status === 'authorized') {
                // 扫码成功，获取账号信息
                $confirmRes = $cloudClient->confirmQr($id);
                if (($confirmRes['code'] ?? 0) == 1) {
                    $this->success('', [
                        'code' => 2, // 登录成功
                        'uin' => $confirmRes['data']['uin'] ?? '',
                        'openid' => $confirmRes['data']['openid'] ?? '',
                        'nickname' => $confirmRes['data']['nickname'] ?? '',
                        'avatar' => $confirmRes['data']['avatar'] ?? '',
                    ]);
                } else {
                    $this->error($confirmRes['msg'] ?? '获取账号信息失败');
                }
            } elseif ($status === 'scanned') {
                $this->success('', ['code' => 1]); // 已扫待确认
            } elseif ($status === 'expired' || $status === 'cancelled') {
                $this->success('', ['code' => -1]); // 失败/过期
            } else {
                $this->success('', ['code' => 0]); // 等待扫码
            }
        } else {
            $this->error('暂不支持该登录方式');
        }
    }

    /**
     * 免CK 商家账单-自动配置向导（走 cloud 新协议 nock.*，由 CloudNockService+AlipayOpenClass 处理）。
     * type: BusinessLicense(检测已过审应用/入驻开发者) / Create(申请应用) / VerifyAckCode(设密钥+获取应用信息)
     * 云返回原样放入 data，前端按 data.code 驱动分步：alipayId(PID)/appId/appPrivateKey(私钥)。
     */
    public function nockNolic(): void
    {
        if (!$this->request->isPost()) {
            $this->error('参数错误');
        }
        $type      = (string) $this->request->param('type', '');
        $cookie    = base64_decode((string) $this->request->param('cookie', ''));
        $checkCode = (string) $this->request->param('checkCode', '');
        $email     = (string) $this->request->param('email', '');
        $devtype   = (string) $this->request->param('devtype', '');

        $cloud = new \app\common\library\payment\plugins\alipay_nock_bill\lib\NockNolicCloud();
        switch ($type) {
            case 'BusinessLicense':
                $res = $cloud->BusinessLicense($cookie, $checkCode, $email);
                break;
            case 'Create':
                $res = $cloud->Create($cookie, $devtype);
                break;
            case 'VerifyAckCode':
                $res = $cloud->VerifyAckCode($cookie, $devtype, $checkCode);
                break;
            default:
                $this->error('参数异常');
                return;
        }
        $this->success('', $res);
    }

    /**
     * 新增通道
     */
    public function add(): void
    {
        if (!$this->request->isPost()) $this->error('参数错误');
        $uid  = $this->auth->id;
        $data = $this->request->post();

        $cType = $data['c_type'] ?? '';
        $type  = $data['type'] ?? '';
        if (!$cType || !$type) $this->error('请选择通道类型');

        $user = User::find($uid);

        // 会员有效期校验：非会员 / 会员已过期不允许添加通道
        if (!$user || !$user->packvip_time || (int) $user->packvip_time < time()) {
            $this->error('当前非会员或会员已过期，请先购买会员套餐后再添加通道');
        }

        // 套餐绑定通道校验：只能添加所购套餐已绑定的通道类型
        $packvip = $user->packvip_id ? PayPackvip::where('id', $user->packvip_id)->find() : null;
        if (!$packvip) {
            $this->error('套餐不存在，请联系管理员');
        }
        $bindCtype = $packvip->bind_ctype ?: []; // 模型解码为数组
        if (!in_array($cType, $bindCtype)) {
            $this->error('该通道不在当前套餐允许范围内，请选择套餐已绑定的通道');
        }

        // 配额校验：0或负数配额不允许添加，正数配额检查是否超额
        $quota = (int) $user->channel_quota;
        $used  = PayChannel::where('uid', $uid)->count();
        if ($quota <= 0) {
            $this->error('通道配额为0，无法添加通道，请先购买套餐或联系管理员增加配额');
        }
        if ($used >= $quota) {
            $this->error('通道配额已用完（已用' . $used . '/' . $quota . '），请升级套餐');
        }

        $config = $this->extractConfig($data);
        $config = $this->runDriverUpchannel($cType, [], $config);

        (new PayChannel())->save([
            'uid'       => $uid,
            'type'      => $type,
            'c_type'    => $cType,
            'notes'     => $data['notes'] ?? '',
            'status'    => $this->initialStatus($cType, $config),
            'tt_switch' => 'true',
            'polling'   => 0,
            'config'    => PayChannel::encryptConfig($config),
        ]);
        NotifyService::send('channel_create', $uid, [
            '[channel]' => NotifyService::channelLabel((string) $type, (string) $cType, (string) ($data['notes'] ?? '')),
        ]);
        $this->success($this->configMsg($config, '添加成功'));
    }

    /**
     * 编辑通道（GET 回填解密 / POST 保存）
     */
    public function edit(): void
    {
        $uid = $this->auth->id;
        $id  = $this->request->param('id');
        $row = PayChannel::where(['id' => $id, 'uid' => $uid])->find();
        if (!$row) $this->error('通道不存在');

        if (!$this->request->isPost()) {
            $arr = $row->toArray();
            $arr['config'] = PayChannel::decryptConfig($row->config);
            $this->success('', ['row' => $arr]);
        }

        $data   = $this->request->post();
        $config = $this->extractConfig($data);
        $old    = PayChannel::decryptConfig($row->config);
        $merged = array_merge($old, $config);
        $merged = $this->runDriverUpchannel($row->c_type, $merged, $merged);

        $row->notes  = $data['notes'] ?? $row->notes;
        // push 通道配好也须等 APP 心跳才在线；none/server 配好即在线
        $row->status = $this->initialStatus($row->c_type, $merged);
        $row->config = PayChannel::encryptConfig($merged);
        $row->save();
        NotifyService::send('channel_update', $uid, [
            '[channel]' => NotifyService::channelLabel((string) $row->type, (string) $row->c_type, (string) $row->notes),
        ]);
        $this->success($this->configMsg($merged, '保存成功'));
    }

    /**
     * 组装配置反馈文案：驱动返回的 msg（如「预计需要审核一天」「IP白名单提示」）附加到成功提示，
     * 实现旧系统「一步一步配置」的分步状态反馈。
     */
    protected function configMsg(array $config, string $default): string
    {
        $msg = trim((string) ($config['msg'] ?? ''));
        return $msg !== '' ? ($default . '，' . $msg) : $default;
    }

    /**
     * 初始在线状态：驱动显式给 status 时，仅 status===1 视为在线；
     * 审核中(2)/白名单(-1)/false 均置离线(0)，待监控 worker 审核通过后自动置 1。
     * 未显式给 status 时：push 通道置 0（等端上心跳），none/server 置 1（配好即在线）。
     */
    protected function initialStatus(string $cType, array $config): int
    {
        if (array_key_exists('status', $config) && $config['status'] !== null) {
            return ((int) $config['status'] === 1) ? 1 : 0;
        }
        try {
            if (PaymentManager::has($cType) && PaymentManager::make($cType)->monitorMode() === 'push') {
                return 0;
            }
        } catch (Throwable $e) {
        }
        return 1;
    }

    /**
     * 删除通道
     */
    public function del(): void
    {
        $uid = $this->auth->id;
        $id  = $this->request->param('id');
        $row = PayChannel::where(['id' => $id, 'uid' => $uid])->find();
        if (!$row) $this->error('通道不存在');
        $row->delete();
        $this->success('已删除');
    }

    /**
     * 上传收款码并解码：返回二维码内容文本（存储/展示用文本，非图片）
     */
    public function uploadQr(): void
    {
        $file = $_FILES['file'] ?? $_FILES['image_field'] ?? null;
        if (!$file || empty($file['tmp_name'])) {
            $this->error('请上传收款码图片');
        }
        $dir = runtime_path() . 'qrtmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . uniqid('qr_') . '.png';
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            $this->error('上传失败，请重试');
        }
        $text = (new QrDecodeService())->decodeFile($path);
        @unlink($path);

        if ($text) {
            $this->success('解码成功', ['qrcode' => $text]);
        }
        $this->error('解码失败，请手动填写收款码内容');
    }

    /* ---------------- helpers ---------------- */

    protected function extractConfig(array &$data): array
    {
        $config = $data['config'] ?? [];
        unset($data['config']);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            if (!is_array($decoded)) {
                // 兼容 BA 对参数做 htmlspecialchars 过滤后 " → &quot; 的情况
                $decoded = json_decode(htmlspecialchars_decode($config, ENT_QUOTES), true);
            }
            $config = is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    protected function runDriverUpchannel(string $cType, array $channelRow, array $config): array
    {
        if (!$cType || !PaymentManager::has($cType)) return $config;
        $result = PaymentManager::make($cType)->upchannel($channelRow, $config);
        if (isset($result['code']) && $result['code'] == -1) {
            $this->error($result['msg'] ?? '通道配置校验失败');
        }
        return $result;
    }
}
