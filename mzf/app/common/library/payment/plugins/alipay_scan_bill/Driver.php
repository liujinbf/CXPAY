<?php

namespace app\common\library\payment\plugins\alipay_scan_bill;

use app\common\library\payment\AbstractPaymentChannel;
use app\common\library\payment\plugins\alipay_scan_bill\lib\AlipayScanClass;

/**
 * 支付宝扫码免挂 驱动（alipay_scan_bill）
 *
 * 移植自旧 protected/payplug/alipay_scan_bill/config.php。
 * 原理：扫码登录支付宝网页版获取 Cookie → 轮询余额检测到账 → 匹配回调。
 *   - switch_qr_url：上传收款码（解码文本）用于收银台展示
 *   - getqrlogin=alipay：扫码登录获取 Cookie（该流程需 alipaylogin 云端，属后续）
 *   - Cookie 保活：心跳(heartbeatCallback) 每次刷新延长 Cookie 有效期，保持在线
 *
 * 说明：getPayCurl/getPayCurlCallback 为 Cookie 轮询监控接口，供常驻监控 worker 调用（待接）。
 */
class Driver extends AbstractPaymentChannel
{
    protected string $cType = 'alipay_scan_bill';

    public function monitorMode(): string
    {
        return 'server'; // Cookie 轮询余额/校验，服务端主动心跳
    }

    /**
     * 服务端监控一次：验 Cookie + 取余额增量记账单 + 保活。
     * 由常驻 worker 周期调用。返回更新后的明文 config（status/money/bill）。
     */
    public function monitor(array $channelRow, array $config): array
    {
        $config['bill'] = false;
        if (empty($config['cookie'])) {
            // 未配置 Cookie：不强制离线（可能仅用上传码测试），保持当前状态
            return $config;
        }
        $cookieRaw = base64_decode((string) $config['cookie']);
        $cls = new AlipayScanClass();

        $res = $cls->get_user_money($cookieRaw);
        if (($res['code'] ?? 0) == -1) {
            $config['status'] = false;   // Cookie 失效 → 离线
            return $config;
        }
        $config['status'] = '1';

        $newMoney  = (float) $res['money'];
        $lastMoney = (float) ($config['money'] ?? 0);
        $price     = round($newMoney - $lastMoney, 2);

        if ($newMoney > 0) {
            $config['money'] = $res['money'];  // 更新余额基线
        }
        // 仅在已有基线(lastMoney>0)时才记增量账单，避免首次轮询把全部余额当到账
        if ($price > 0 && $lastMoney > 0) {
            if (!is_array($config['bill'])) $config['bill'] = []; // PHP8.1+: 禁止向 false 追加
            $config['bill'][] = ['price' => (string) $price, 'config' => false];
        }

        $cls->keepAlive($cookieRaw);  // 保活延长 Cookie
        return $config;
    }

    public function config(): array
    {
        return [
            'version'       => '1.1.3',
            'name'          => '扫码免挂机',
            'author'        => '小乐',
            'link'          => 'http://xiaole.ink',
            'c_type'        => 'alipay_scan_bill',
            'type'          => 'alipay',
            'switch_qr_url' => true,
            'getqrlogin'    => 'alipay',
            'inputs'        => [
                'cookie' => [
                    'name' => '支付宝Cookie(base64)',
                    'type' => 'textarea',
                    'note' => '扫码登录自动获取；或手动粘贴 base64 编码的支付宝网页版 Cookie',
                ],
                'surname' => [
                    'name' => '支付宝"姓"验证',
                    'type' => 'input',
                    'note' => '有时候需要验证收款姓名的【姓】',
                ],
                'qrurl_type' => [
                    'name'    => '收款码类型',
                    'type'    => 'select',
                    'options' => ['1' => '自定义上传二维码'],
                    'default' => '1',
                ],
            ],
            'note' => '通过扫码登录获取支付宝网页版Cookie(登录缓存)检测金额/账单收款,并根据到账情况进行回调',
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        $config['qr_url'] = $config['qr_url'] ?? '';

        // 有 Cookie 则校验并提取账号信息（延长有效期）
        if (!empty($config['cookie'])) {
            $cookieRaw = base64_decode((string) $config['cookie']);
            $config['userId'] = self::getSubstr($cookieRaw, 'CLUB_ALIPAY_COM=', ';');

            $cls  = new AlipayScanClass();
            $info = $cls->get_user_pic($cookieRaw);
            if (($info['code'] ?? 0) == -1) {
                return $this->fail($info['msg'] ?? 'Cookie失效,请重新获取');
            }
            $config['user_pic'] = $info['user_pic'] ?? '/assets/alipay.jpeg';
            $nick = $info['nickname'] ?? '';
            if (mb_strlen($nick, 'UTF-8') > 2) {
                $config['nickname'] = '**' . mb_substr($nick, -1);
            } else {
                $config['nickname'] = '*' . mb_substr($nick, -1);
            }
        }

        // 自定义上传码类型必须有收款码
        if (($config['qrurl_type'] ?? '1') == 1 && empty($config['qr_url'])) {
            return $this->fail('请先上传收款二维码');
        }

        return $config;
    }

    /**
     * 出码：默认使用上传解码后的 qr_url（收银台由文本重新生成二维码）
     */
    public function getPayQr(array $order, array $config): array
    {
        $config['config'] = false; // 金额递增去重

        $qr = $config['qr_url'] ?? '';
        if (!$qr) {
            return $this->fail('通道未配置收款码');
        }
        return $this->ok(['qr' => $qr, 'type' => 'qr']);
    }

    /**
     * 心跳：刷新 Cookie 保活 + 取余额，Cookie 失效则离线
     */
    public function heartbeatCallback(array $channelRow, array $config): array
    {
        if (empty($config['cookie'])) {
            $config['status'] = false;
            return $config;
        }
        $cookieRaw = base64_decode((string) $config['cookie']);
        $cls = new AlipayScanClass();

        $money = $cls->get_user_money($cookieRaw);
        if (($money['code'] ?? 0) == -1) {
            $config['status'] = false;
        } else {
            $config['status'] = '1';
            $config['money']  = $money['money'];
        }

        // 保活：强化刷新，延长 Cookie 有效期
        $cls->keepAlive($cookieRaw);

        return $config;
    }

    /**
     * 监控取单：组装 Cookie 轮询请求（供监控 worker 多线程执行）
     */
    public function getPayCurl(array $channelRow, array $config, array $order = []): array
    {
        return [
            'url'    => 'https://uemprod.alipay.com/service.json?_output_charset=utf-8&ctoken=' . time() . '-' . time() . '&operation=mrchcenter.artisan.v2.ext.query',
            'post'   => 'data=%7B%22pageSource%22%3A%22fund_home_pc_merchant%22%2C%22parameters%22%3A%7B%22switchToNew%22%3A%22true%22%7D%7D',
            'cookie' => base64_decode((string) ($config['cookie'] ?? '')),
            'ua'     => '',
        ];
    }

    /**
     * 监控回调：解析余额，增量记为到账账单
     */
    public function getPayCurlCallback(array $channelRow, array $config, $curlData): array
    {
        $config['bill'] = false;

        $json  = json_decode((string) $curlData, true);
        $money = preg_replace('/[^\d.]/', '', $json['data']['data']['fund_home_detail_info']['data']['alipayBalanceList'][0]['availableBalance'] ?? '');
        $price = (float) $money - (float) ($config['money'] ?? 0);

        if ($money > 0) {
            $config['money'] = $money;
        }
        if ($price > 0) {
            if (!is_array($config['bill'])) $config['bill'] = []; // PHP8.1+: 禁止向 false 追加
            $config['bill'][] = ['price' => $price, 'config' => false];
        }
        return $config;
    }

    /**
     * 取中间文本（cookie 提取 userId 用）
     */
    private static function getSubstr(string $str, string $leftStr, string $rightStr): string
    {
        $left = strpos($str, $leftStr);
        if ($left === false) return '';
        $right = strpos($str, $rightStr, $left);
        if ($right === false || $right < $left) return '';
        return substr($str, $left + strlen($leftStr), $right - $left - strlen($leftStr));
    }
}
