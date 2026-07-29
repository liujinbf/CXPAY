<?php

namespace app\common\library\payment\plugins\alipay_nock_bill;

use app\common\library\payment\AbstractPaymentChannel;
use app\common\library\payment\plugins\alipay_nock_bill\lib\NockBillClass;

/**
 * 支付宝免CK商家账单-[手动配置] 驱动（alipay_nock_bill）
 *
 * 移植自旧 protected/payplug/alipay_nock_bill/config.php（类 alipay_nock_bill）。
 * 原理：商户手动填 支付宝PID/应用APP_ID/应用私钥 → 直连官方账单接口
 *   alipay.data.bill.accountlog.query 轮询到账（RSA2 签名），使用官方公开账单接口，不存在掉线。
 *   - switch_qr_url：上传收款码（解码文本）用于收银台展示
 *   - getqrlogin=false：本通道手动配置，无扫码登录
 *
 * 到账检测（server 监控）：monitor() = 心跳校验(check_user) + 拉账单(query_accountlog)
 *   + 解析明细(getPayCurlCallback) 产出增量到账账单。getPayCurl/getPayCurlCallback 亦保留。
 *
 * 说明：账单接口需真实商户 app_id/私钥授权，网络/签名部分本地不可联网验证，忠实保留。
 */
class Driver extends AbstractPaymentChannel
{
    protected string $cType = 'alipay_nock_bill';

    public function monitorMode(): string
    {
        return 'server'; // 官方账单接口轮询到账，服务端主动心跳
    }

    public function config(): array
    {
        return [
            'version'       => '1.1.6',
            'name'          => '商家账单-免CK',
            'author'        => '小乐',
            'link'          => 'http://xiaole.ink',
            'type'          => 'alipay',
            'c_type'        => 'alipay_nock_bill',
            'switch_qr_url' => true,
            'getqrlogin'    => 'alipay',
            'inputs'        => [
                'userId' => [
                    'name' => '支付宝PID',
                    'type' => 'input',
                    'note' => '支付宝PID',
                ],
                'app_id' => [
                    'name' => '应用APP_ID',
                    'type' => 'input',
                    'note' => '支付宝应用APP',
                ],
                'publicKey' => [
                    'name' => '应用私钥',
                    'type' => 'textarea',
                    'note' => '支付宝应用私钥',
                ],
                'surname' => [
                    'name' => '支付宝"姓"验证:',
                    'type' => 'input',
                    'note' => '有时候需要验证收款姓名的【姓】',
                ],
                'qrurl_type' => [
                    'name'    => '收款码类型',
                    'type'    => 'select',
                    'options' => [
                        '1' => '自定义上传二维码',
                    ],
                ],
            ],
            'note' => '<span style="color:red">支持「自动配置」(扫码登录自动申请应用/免执照)与「手动配置」(自行去 open.alipay.com 申请后填写)。</span><br>使用官方公开的账单接口,不存在掉线情况',
        ];
    }

    /**
     * 更新通道：校验 app_id/私钥 + 审核状态(白名单/应用未上线)；有 Cookie 则取头像昵称；校验上传码。
     * 合并手动/自动配置：自动配置由向导回填 userId/app_id/publicKey 后走同一校验。
     */
    public function upchannel(array $channelRow, array $config): array
    {
        $config['qr_url'] = $config['qr_url'] ?? ''; // 解码出来的二维码 (switch_qr_url=true 才有值)

        $cls        = new NockBillClass();
        $check_user = $cls->check_user($config['app_id'] ?? '', $config['publicKey'] ?? '');
        if (strpos((string) $check_user, '白名单')) {
            $config['status'] = -1;
            $config['msg']    = '当前应用设置了IP白名单,请去开发者平台取消或者创建新的应用使用';
        }
        if (strpos((string) $check_user, 'AppID')) {
            return $this->fail('更新失败：应用App_Id不正确');
        }
        if (strpos((string) $check_user, '签名')) {
            return $this->fail('更新失败：应用私钥不正确' . ($config['publicKey'] ?? ''));
        }
        // 应用未上线（自动配置刚申请）→ 审核中，待监控 worker 审核通过自动上线
        if (strpos((string) $check_user, '应用未上线')) {
            $config['status'] = 2;
            $config['msg']    = '预计需要审核一天左右然后自动上线';
        }

        // 有 Cookie 才获取支付宝头像以及姓名（脱敏）
        if (!empty($config['cookie']) && empty($config['nickname'])) {
            $pic = $cls->get_user_pic(base64_decode((string) $config['cookie']));
            if (($pic['code'] ?? 0) == -1) {
                return $this->fail($pic['msg'] ?? 'cookie失效,请重新获取');
            }
            $config['user_pic'] = $pic['user_pic'] ?? '';
            $nickname           = (string) ($pic['nickname'] ?? '');
            if ($nickname !== '') {
                $config['nickname'] = mb_strlen($nickname, 'UTF-8') > 2
                    ? '**' . mb_substr($nickname, -1)
                    : '*' . mb_substr($nickname, -1);
            }
        }

        if (($config['qrurl_type'] ?? '') == 1 && empty($config['qr_url'])) {
            return $this->fail('请先上传收款二维码');
        }

        return $config;
    }

    /**
     * 出码：默认使用上传解码后的 qr_url（收银台由文本重新生成二维码）。
     * 对应旧 getpayqrurl。
     *
     * 旧 config 仅暴露 qrurl_type='1'(自定义上传码)。旧 2/3(小荷包/免输转账单)分支依赖
     * 旧框架 url()/generateShopName() 助手，xlpay 无此助手且选项未暴露，忠实省略。
     */
    public function getPayQr(array $order, array $config): array
    {
        $config['config'] = false; // 置 false=沿用金额递增去重(存在该值才不递增)

        $qr = $config['qr_url'] ?? '';
        if (!$qr) {
            return $this->fail('通道未配置收款码');
        }
        return $this->ok(['qr' => $qr, 'type' => 'qr']);
    }

    /**
     * 心跳：校验 app_id/私钥，失败置离线。对应旧 gethrcurl_callback。
     */
    public function heartbeatCallback(array $channelRow, array $config): array
    {
        $cls        = new NockBillClass();
        $check_user = $cls->check_user($config['app_id'] ?? '', $config['publicKey'] ?? '');
        if (strpos((string) $check_user, 'AppID') || strpos((string) $check_user, '签名')) {
            $config['status'] = false;
        }
        return $config;
    }

    /**
     * 服务端监控一次：心跳校验 + 拉官方账单 + 解析增量到账。
     * 由常驻 worker 周期调用，返回更新后的明文 config（status/bill）。
     */
    public function monitor(array $channelRow, array $config): array
    {
        $config['bill'] = false;

        // 单次账单接口调用：响应即含应用状态(10000/40003/未上线/白名单)与到账明细，避免重复请求被限流
        $cls  = new NockBillClass();
        $data = $cls->query_accountlog($config['app_id'] ?? '', $config['publicKey'] ?? '');

        if (strpos((string) $data, 'AppID') || strpos((string) $data, 'INVALID_PARAMETER') || strpos((string) $data, '签名')) {
            $config['status'] = false;
            $config['msg']    = '应用App_Id或私钥不正确';
            return $config;
        }
        // 应用未上线（审核中）→ 离线，账单接口不可用
        if (strpos((string) $data, '应用未上线') || strpos((string) $data, 'not-online')) {
            $config['status'] = false;
            $config['msg']    = '应用未上线(审核中)，预计审核一天左右，通过后自动上线';
            return $config;
        }
        // IP 白名单拦截 → 离线
        if (strpos((string) $data, '白名单')) {
            $config['status'] = false;
            $config['msg']    = '当前应用设置了IP白名单,请去开发者平台取消或创建新应用';
            return $config;
        }
        // 账单接口可用（已上线）→ 在线，解析增量到账
        if (strpos((string) $data, '10000') || strpos((string) $data, 'Success')) {
            $config['status'] = '1';
            $config['msg']    = '';
        }
        return $this->getPayCurlCallback($channelRow, $config, $data);
    }

    /**
     * 组装监控取单请求（供多线程监控 worker）。对应旧 getpaycurl。
     */
    public function getPayCurl(array $channelRow, array $config, array $order = []): array
    {
        $cls          = new NockBillClass();
        $url_postdate = $cls->url_postdate($config['app_id'] ?? '', $config['publicKey'] ?? '');
        return [
            'url'    => $url_postdate['url'],
            'post'   => $url_postdate['post'],
            'cookie' => $config['cookie'] ?? '',
            'ua'     => '',
        ];
    }

    /**
     * 解析账单明细 → 增量到账账单。对应旧 getpaycurl_callback（逐字节保留解析/去重/排序）。
     */
    public function getPayCurlCallback(array $channelRow, array $config, $curlData): array
    {
        $config['bill'] = false; // 没有新收款金额 不操作任何数据

        // 检查CURL数据是否有效
        if (empty($curlData)) {
            error_log("alipay_nock_bill: 回调检测收到空数据");
            return $config;
        }

        // 解析JSON数据
        $json = json_decode($curlData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("alipay_nock_bill: JSON解析错误 - " . json_last_error_msg());
            return $config;
        }

        // 检查API响应是否成功
        if (!isset($json['alipay_data_bill_accountlog_query_response']) ||
            $json['alipay_data_bill_accountlog_query_response']['code'] != '10000') {
            $errorMsg = isset($json['alipay_data_bill_accountlog_query_response']['sub_msg'])
                ? $json['alipay_data_bill_accountlog_query_response']['sub_msg']
                : '未知错误';
            error_log("alipay_nock_bill: API响应错误 - " . $errorMsg);
            return $config;
        }

        // 检查是否有账单明细
        if (!isset($json['alipay_data_bill_accountlog_query_response']['detail_list']) ||
            empty($json['alipay_data_bill_accountlog_query_response']['detail_list'])) {
            // 没有账单明细是正常情况，不需要记录错误
            return $config;
        }

        // 处理账单明细
        $processedOrders = array(); // 用于去重，避免重复处理同一订单

        foreach ($json['alipay_data_bill_accountlog_query_response']['detail_list'] as $detail_row) {
            // 只处理收入交易
            if ($detail_row['direction'] == '收入') {
                $orderNo = $detail_row['alipay_order_no'];

                // 去重处理：同一订单号只处理一次
                if (in_array($orderNo, $processedOrders)) {
                    continue;
                }
                $processedOrders[] = $orderNo;

                // 清理金额字符串中的逗号
                $amount = str_replace(",", "", $detail_row['trans_amount']);

                // 验证金额是否为有效数字
                if (!is_numeric($amount) || $amount <= 0) {
                    error_log("alipay_nock_bill: 无效金额 - $amount (订单号: $orderNo)");
                    continue;
                }

                // bill 初值为 false(无到账)；首笔到账时转为数组再追加
                // （旧 PHP 隐式把 false 转数组，xlpay(PHP8.1+/ThinkPHP) 严格会抛异常，此处显式初始化，行为等价）
                if (!is_array($config['bill'])) {
                    $config['bill'] = array();
                }
                $config['bill'][] = array(
                    "price"      => $amount,
                    "config"     => $orderNo,
                    "trans_time" => $detail_row['trans_dt'] ?? '', // 账单接口字段为 trans_dt（非 trans_date）
                    "memo"       => $detail_row['trans_memo'] ?? ($detail_row['memo'] ?? ''),
                );
            }
        }

        // 按交易时间排序，确保先处理的交易是先发生的
        if (isset($config['bill']) && is_array($config['bill'])) {
            usort($config['bill'], function ($a, $b) {
                return strcmp($a['trans_time'], $b['trans_time']);
            });
        }

        return $config;
    }

    /**
     * 关单：旧 trade_close 无实质操作。
     */
    public function tradeClose(array $order, array $config): array
    {
        return ['code' => 1, 'msg' => ''];
    }
}
