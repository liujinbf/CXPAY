<?php

namespace app\admin\controller\pay;

use Throwable;
use app\common\controller\Backend;
use app\common\model\PayChannel;
use app\common\model\PayCtype;
use app\common\library\payment\PaymentManager;
use app\common\service\QrDecodeService;

/**
 * 通道管理
 *
 * 通道 = 商户(ba_user) 配置的某个 c_type 收款实例，config 为随 c_type 变化的
 * 动态字段，以 authcode 加密 JSON 存库。生成器无法处理动态表单，故手写。
 *
 * 扩展接口：
 *   GET  getConfig?c_type=xxx  → 返回该通道驱动的字段定义(inputs)，供前端渲染动态表单
 */
class Channel extends Backend
{
    /**
     * @var object
     * @phpstan-var PayChannel
     */
    protected object $model;

    protected string|array $quickSearchField = ['id', 'c_type', 'notes'];

    // config 不入通用流程，由本控制器显式加解密
    protected string|array $preExcludeFields = ['id', 'create_time', 'update_time', 'config'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new PayChannel();
    }

    /**
     * 列表（不返回加密 config，避免泄露）
     * @throws Throwable
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        list($where, $alias, $limit, $order) = $this->queryBuilder();
        $res = $this->model
            ->withoutField('config')
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        $items      = $res->items();
        $now        = time();
        $pushTtl    = (int) \think\facade\Config::get('payment.push_heartbeat_timeout', 120);
        $typeNames  = PayCtype::column('name', 'c_type');
        // 所属商户：uid → 用户名(对接PID)
        $uids = array_values(array_filter(array_unique(array_column($items, 'uid'))));
        $userInfo = $uids ? \app\admin\model\User::whereIn('id', $uids)->column('username,pid', 'id') : [];
        foreach ($items as $it) {
            $uid = (int) $it['uid'];
            $it->uid_text = isset($userInfo[$uid])
                ? $userInfo[$uid]['username'] . '(' . $userInfo[$uid]['pid'] . ')'
                : (string) $uid;
            $cType = (string) $it['c_type'];
            $it->c_type_name = $typeNames[$cType] ?? $cType;
            // 在线判定（按监控方式）
            $mode = 'none';
            try {
                if (PaymentManager::has($cType)) $mode = PaymentManager::make($cType)->monitorMode();
            } catch (Throwable $e) {
            }
            if ($mode === 'push') {
                $ct = (int) $it['check_time'];
                $online = $ct > 0 && $ct >= $now - $pushTtl;
            } else {
                $online = ((int) $it['status'] === 1);
            }
            $ot = (int) $it['online_time'];
            $it->online       = $online;
            $it->online_secs  = ($online && $ot > 0) ? ($now - $ot) : 0; // 本次在线时长(秒)
            $it->offline_at   = (int) $it['endtime'];                    // 掉线时间戳
            // 累计在线：历史累计 + 本次在线时长
            $it->online_total = (int) $it['online_total'] + (($online && $ot > 0) ? ($now - $ot) : 0);
        }

        $this->success('', [
            'list'   => $items,
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 返回指定 c_type 的动态表单字段定义
     * 已注册驱动→取驱动 config().inputs；未注册→空(前端用通用键值编辑)
     */
    public function getConfig(): void
    {
        $cType = $this->request->param('c_type', '');
        if (!$cType) {
            $this->error(__('Parameter error'));
        }

        $inputs = [];
        $meta   = [];
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
            'inputs'        => $inputs,
            'cloud_url'     => \think\facade\Config::get('payment.cloud_url', 'https://cloud.iosle.com/'),
        ]);
    }

    /**
     * 添加：驱动校验 + config 加密
     */
    public function add(): void
    {
        if (!$this->request->isPost()) {
            $this->error(__('Parameter error'));
        }
        $data   = $this->request->post();
        $config = $this->extractConfig($data);

        $data = $this->excludeFields($data);
        $this->validateChannelBase($data);

        // 驱动校验/规范化配置
        $config = $this->runDriverUpchannel($data['c_type'] ?? '', [], $config);
        $data['config'] = PayChannel::encryptConfig($config);

        $this->model->startTrans();
        try {
            $result = $this->model->save($data);
            $this->model->commit();
        } catch (Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }
        $result !== false ? $this->success(__('Added successfully')) : $this->error(__('No rows were added'));
    }

    /**
     * 编辑：GET 回填解密 config；POST 驱动校验 + 加密
     */
    public function edit(): void
    {
        $pk  = $this->model->getPk();
        $id  = $this->request->param($pk);
        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if (!$this->request->isPost()) {
            // 回填：解密 config 供表单显示
            $arr           = $row->toArray();
            $arr['config'] = PayChannel::decryptConfig($row->config);
            $this->success('', ['row' => $arr]);
        }

        $data   = $this->request->post();
        $config = $this->extractConfig($data);
        $data   = $this->excludeFields($data);

        // 合并旧配置后再校验（旧密文先解密）
        $oldConfig = PayChannel::decryptConfig($row->config);
        $config    = $this->runDriverUpchannel($row->c_type, array_merge($oldConfig, $config), array_merge($oldConfig, $config));
        $data['config'] = PayChannel::encryptConfig($config);

        $this->model->startTrans();
        try {
            $result = $row->save($data);
            $this->model->commit();
        } catch (Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }
        $result !== false ? $this->success(__('Update successful')) : $this->error(__('No rows updated'));
    }

    /**
     * 上传收款码并解码：返回二维码内容文本
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

    /**
     * 从提交数据中取出 config（对象/JSON 字符串两种形式）并移除，避免进主表字段
     */
    protected function extractConfig(array &$data): array
    {
        $config = $data['config'] ?? [];
        unset($data['config']);
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            if (!is_array($decoded)) {
                $decoded = json_decode(htmlspecialchars_decode($config, ENT_QUOTES), true);
            }
            $config = is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }

    /**
     * 通道主字段基本校验
     */
    protected function validateChannelBase(array $data): void
    {
        if (empty($data['uid'])) {
            $this->error('请选择所属商户');
        }
        if (empty($data['c_type'])) {
            $this->error('请选择通道类型');
        }
    }

    /**
     * 调用驱动 upchannel 校验/规范化配置；未注册驱动则原样返回
     * @return array 规范化后的明文配置
     */
    protected function runDriverUpchannel(string $cType, array $channelRow, array $config): array
    {
        if (!$cType || !PaymentManager::has($cType)) {
            return $config;
        }
        $result = PaymentManager::make($cType)->upchannel($channelRow, $config);
        if (isset($result['code']) && $result['code'] == -1) {
            $this->error($result['msg'] ?? '通道配置校验失败');
        }
        return $result;
    }
}
