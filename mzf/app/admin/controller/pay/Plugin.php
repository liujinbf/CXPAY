<?php

namespace app\admin\controller\pay;

use app\common\controller\Backend;
use app\common\model\PayCtype;
use app\common\library\payment\PaymentManager;
use app\core\CloudStore;

/**
 * 支付插件（插件商城基础）
 *
 * 支付通道全部插件化：每个通道 = plugins/{c_type}/ 下实现 PaymentChannelInterface 的驱动，
 * 由 PaymentManager 自动发现。本控制器列出已发现插件，并可「一键安装」——
 * 把插件登记到 ba_pay_ctype，使其可被商户在通道管理中选用。
 */
class Plugin extends Backend
{
    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * 已发现支付插件列表（含是否已安装到系统 = 是否登记 ctype）
     */
    public function index(): void
    {
        $ctypes = PayCtype::column('id', 'c_type'); // c_type => id
        $list   = [];
        foreach (PaymentManager::registered() as $cType) {
            $meta   = PaymentManager::metadata($cType);
            $list[] = [
                'c_type'    => $cType,
                'name'      => $meta['name'] ?? $cType,
                'version'   => $meta['version'] ?? '-',
                'author'    => $meta['author'] ?? '-',
                'type'      => $meta['type'] ?? '-',
                'link'      => $meta['link'] ?? '',
                'note'      => $meta['note'] ?? '',
                'installed' => isset($ctypes[$cType]), // 已登记 ctype = 已安装(可被商户选用)
            ];
        }
        $this->success('', ['list' => $list, 'total' => count($list), 'installed' => count($ctypes)]);
    }

    /**
     * 一键安装：把所有已发现插件登记到 ba_pay_ctype（缺失即新增，已存在保留后台自定义）。
     */
    public function install(): void
    {
        // 已合并/下线的通道类型：不再作为可选 ctype 暴露给商户
        $deprecated = ['alipay_nock_nolic']; // 自动配置已并入 alipay_nock_bill
        $added = 0;
        $registered = PaymentManager::registered();
        foreach ($registered as $cType) {
            if (in_array($cType, $deprecated, true)) {
                continue;
            }
            if (PayCtype::where('c_type', $cType)->find()) {
                continue; // 已登记则跳过，保留名称/状态/排序等后台自定义
            }
            $meta = PaymentManager::make($cType)->config();
            (new PayCtype())->save([
                'type'   => $meta['type'] ?? '',
                'c_type' => $cType,
                'name'   => $meta['name'] ?? $cType,
                'notes'  => mb_substr(strip_tags((string) ($meta['note'] ?? '')), 0, 200),
                'status' => 1,
                'weigh'  => 50,
            ]);
            $added++;
        }
        $this->success("安装完成：新增 {$added} 个，系统共 " . count($registered) . " 个通道类型");
    }

    /**
     * 云端商城：列出云端可下载/更新的插件（含本地版本对比）。
     */
    public function cloud(): void
    {
        $res = CloudStore::list();
        if (!$res['ok']) {
            $this->error($res['msg'] ?: '云端不可达');
        }
        $this->success('', ['list' => $res['list']]);
    }

    /**
     * 从云端下载安装 / 更新一个插件。
     */
    public function download(): void
    {
        $cType = (string) $this->request->post('c_type', '');
        if ($cType === '') {
            $this->error('缺少插件标识');
        }
        $res = CloudStore::install($cType);
        if (!$res['ok']) {
            $this->error($res['msg']);
        }
        $this->success($res['msg']);
    }

    /**
     * 卸载一个插件（删除插件目录 + 注销 ctype；有通道占用时拒绝）。
     */
    public function uninstall(): void
    {
        $cType = (string) $this->request->post('c_type', '');
        if ($cType === '') {
            $this->error('缺少插件标识');
        }
        $res = CloudStore::uninstall($cType);
        if (!$res['ok']) {
            $this->error($res['msg']);
        }
        $this->success($res['msg']);
    }

    /**
     * 插件云端授权状态（走 cloud 新协议）。
     * 返回：cloud_ipad_endtime / wechat_pc_endtime / app_endtime+app_status / ipad_urls / ipad_proxys /
     *       Wechat_Pc_ver / Wechat_Pc_uplog / user_center，供 官方iPad免挂 / PC挂机 / APP监控 插件配置页展示。
     */
    public function cloudStatus(): void
    {
        $grant    = \app\core\CloudAuth::grant();
        $features = (array) ($grant['features'] ?? []);

        $out = [
            'code'                     => 1,
            'cloud_ipad_endtime'       => $this->featureText($features, 'cloud_ipad'),
            'wechat_pc_endtime'        => $this->featureText($features, 'wechat_pc'),
            'app_endtime'              => $this->featureText($features, 'app'),
            'app_status'               => !empty($features['app']['authorized']) ? 1 : 0,
            'protocol_cloud_endtime'   => $this->featureText($features, 'protocol_cloud'),
            'agent_qq'                 => (string) ($grant['qq'] ?? ''),
            'user_center'              => rtrim(\app\core\CloudClient::cloudUrl(), '/') . '/',
            'ipad_urls'                => [],
            'ipad_proxys'              => [],
            'Wechat_Pc_ver'            => '',
            'Wechat_Pc_uplog'          => '',
        ];

        // iPad 服务器/代理列表
        try {
            $srv = \app\core\CloudClient::request('ipad.get_ipad_server');
            if ($srv['ok']) {
                $out['ipad_urls']   = is_array($srv['data']['urls'] ?? null) ? $srv['data']['urls'] : [];
                $out['ipad_proxys'] = is_array($srv['data']['proxys'] ?? null) ? $srv['data']['proxys'] : [];
            }
        } catch (\Throwable $e) {
        }

        // PC微信最新版本
        try {
            $chk = \app\core\CloudClient::request('software.check', ['product' => 'wechat_pc', 'vers' => '']);
            if ($chk['ok']) {
                $out['Wechat_Pc_ver']   = (string) ($chk['data']['version'] ?? '');
                $out['Wechat_Pc_uplog'] = (string) ($chk['data']['changelog'] ?? '');
            }
        } catch (\Throwable $e) {
        }

        $this->success('', $out);
    }

    /** 功能授权状态文案（供插件页 needBuy 判定：含"未开通/到期"即需购买） */
    protected function featureText(array $features, string $key): string
    {
        $f = $features[$key] ?? null;
        if (!$f) {
            return '未开通';
        }
        if (empty($f['authorized'])) {
            return '未开通或已到期';
        }
        $e = (int) ($f['expire'] ?? 0);
        return $e > 0 ? date('Y-m-d H:i:s', $e) : '永久授权';
    }

    /** APP监控-读取 APP 品牌配置 */
    public function appConfig(): void
    {
        $this->success('', ['config' => self::readAppConfig()]);
    }

    /** APP监控-保存 APP 品牌配置（名称/LOGO/介绍/客服/公告/群） */
    public function saveAppConfig(): void
    {
        $cfg   = (array) $this->request->post('config/a', []);
        $allow = ['name', 'logo', 'title', 'kfqq', 'notice', 'qunname', 'qunlink'];
        $out   = [];
        foreach ($allow as $k) {
            $out[$k] = (string) ($cfg[$k] ?? '');
        }
        $file = self::appConfigFile();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }
        if (@file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            $this->error('保存失败，请检查目录权限');
        }
        $this->success('保存成功');
    }

    /** APP 配置文件路径（固定 root/runtime，避免多应用 runtime 目录不一致） */
    public static function appConfigFile(): string
    {
        return root_path() . 'runtime/plugin_config/wxpay_app_asst.json';
    }

    /** 读取 APP 配置（合并默认值） */
    public static function readAppConfig(): array
    {
        $def = ['name' => '', 'logo' => '', 'title' => '', 'kfqq' => '', 'notice' => '', 'qunname' => '', 'qunlink' => ''];
        $f   = self::appConfigFile();
        if (is_file($f)) {
            $j = json_decode((string) file_get_contents($f), true);
            if (is_array($j)) {
                return array_merge($def, $j);
            }
        }
        return $def;
    }
}
