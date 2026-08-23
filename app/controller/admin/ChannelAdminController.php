<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\payment\PaymentManager;
use Illuminate\Database\Capsule\Manager as DB;
use support\Authcode;
use support\Request;
use support\Response;
use ZipArchive;

/**
 * 通道动态参数与 inputs 表单配置控制 API
 */
class ChannelAdminController
{
    protected Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }

    /**
     * 获取指定 c_type 驱动的 inputs 动态表单项定义
     */
    public function getConfigInputs(Request $request): string
    {
        $cType = $request->get('c_type') ?? $request->post('c_type') ?? '';

        if (empty($cType) || !PaymentManager::has($cType)) {
            return json_encode([
                'code' => -1,
                'msg' => '支付驱动不存在或尚未安装',
            ], JSON_UNESCAPED_UNICODE);
        }

        $driver = PaymentManager::make($cType);
        $meta   = $driver->getMeta();

        return json_encode([
            'code' => 1,
            'data' => [
                'c_type' => $cType,
                'title'  => $meta['title'] ?? $cType,
                'inputs' => $meta['inputs'] ?? [],
                'supports_account_authorization' => ($meta['supports_account_authorization'] ?? false) === true,
                'authorization_label' => (string)($meta['authorization_label'] ?? '扫码授权'),
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 在未保存通道前直接根据驱动类型发起扫码授权（用于添加通道弹窗中一键扫码提取Cookie）
     */
    public function startDriverAuth(Request $request): string
    {
        $cType = trim((string)($request->post('c_type') ?? $request->get('c_type') ?? ''));
        if ($cType === '' || !PaymentManager::has($cType)) {
            return json_encode(['code' => -1, 'msg' => '指定的驱动类型不存在'], JSON_UNESCAPED_UNICODE);
        }
        try {
            $config = (array)($request->post('config') ?? []);
            $result = PaymentManager::startAccountAuthorization($cType, $config);
            return json_encode(['code' => 1, 'data' => $result], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['code' => -1, 'msg' => '发起扫码登录提取失败：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * 轮询驱动扫码授权状态（返回提取到的 Cookie 供前端直接回填到表单）
     */
    public function pollDriverAuth(Request $request): string
    {
        $cType = trim((string)($request->post('c_type') ?? $request->get('c_type') ?? ''));
        $sessionId = trim((string)($request->post('session_id') ?? $request->get('session_id') ?? ''));
        if ($cType === '' || !PaymentManager::has($cType)) {
            return json_encode(['code' => -1, 'msg' => '指定的驱动类型不存在'], JSON_UNESCAPED_UNICODE);
        }
        try {
            $config = (array)($request->post('config') ?? []);
            $result = PaymentManager::pollAccountAuthorization($cType, $sessionId, $config);
            return json_encode(['code' => 1, 'data' => $result], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['code' => -1, 'msg' => '轮询扫码状态失败：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * 管理后台一键生成并下载通道专属预装监控端 Zip
     */
    public function downloadPresetClient(Request $request)
    {
        try {
            $id = (int)$request->get('id');
            $channel = DB::table('cx_pay_channel')->where('id', $id)->first();
            if (!$channel) {
                return json(['code' => 404, 'msg' => '通道不存在'])->withStatus(404);
            }

            $config = json_decode((string)$channel->config, true) ?: [];
            $secret = '';
            foreach ($config as $k => $v) {
                if ($k === 'notify_secret' || $k === 'secret' || $k === 'app_secret') {
                    $secret = is_string($v) ? $this->authcode->decryptStored($v) : (string)$v;
                    break;
                }
            }
            if ($secret === '' || strlen($secret) < 32) {
                $secret = bin2hex(random_bytes(16));
                $config['notify_secret'] = $this->authcode->encrypt($secret);
                DB::table('cx_pay_channel')->where('id', $id)->update([
                    'config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ]);
            }

            $payType = 'wxpay';
            if (str_contains((string)$channel->c_type, 'alipay')) $payType = 'alipay';
            elseif (str_contains((string)$channel->c_type, 'qqpay')) $payType = 'qqpay';

            $host = $request->header('host') ?: 'cs.fcwan.cn';
            $scheme = $request->header('x-forwarded-proto') ?: 'https';
            $serverUrl = "{$scheme}://{$host}";

            $presetData = [
                'server_url' => $serverUrl,
                'channel_id' => (int)$channel->id,
                'device_id' => "PC_ADMIN_CH{$channel->id}",
                'pay_type' => $payType,
                'notify_secret' => $secret,
                'capture_mode' => 'wechat_ui',
                'poll_seconds' => 5
            ];

            $tmpDir = sys_get_temp_dir() . '/cxpay_presets';
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

            $zipPath = $tmpDir . "/CXPayMonitor-Channel-{$channel->id}.zip";
            $baseZip = public_path() . '/downloads/CXPayMonitor-v1.3.5-Release.zip';

            if (class_exists(ZipArchive::class) && file_exists($baseZip)) {
                copy($baseZip, $zipPath);
                $zip = new ZipArchive();
                if ($zip->open($zipPath) === true) {
                    $zip->addFromString('config.json', json_encode($presetData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $zip->close();
                    return response('')->download($zipPath, "CXPayMonitor-Channel-{$channel->id}-Preset.zip");
                }
            }

            return json(['code' => 1, 'msg' => '专属配置生成成功', 'data' => $presetData]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '生成专属包异常：' . $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine()]);
        }
    }
}
