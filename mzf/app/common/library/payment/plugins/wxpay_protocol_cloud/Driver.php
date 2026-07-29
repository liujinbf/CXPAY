<?php

namespace app\common\library\payment\plugins\wxpay_protocol_cloud;

use app\common\library\payment\AbstractPaymentChannel;
use app\common\library\payment\lib\ProtocolCloud;
use app\common\library\payment\plugins\wxpay_protocol_cloud\lib\WeChatProtocolCloud;

/**
 * 微信协议云端 驱动（wxpay_protocol_cloud）
 *
 * 支持小账本和收款单两种模式。
 * 原理：扫码登录保存账号后，获取 uin → 调用云端API获取小程序code → 换取SID → 拉取账单匹配结算。
 * 监控方式 server：monitor() 主动拉取账单列表（小账本）或单笔查询（收款单）。
 * 收款单模式：price=money 不递增（按 receipt_id 唯一匹配），需登记 no_increment_ctypes。
 */
class Driver extends AbstractPaymentChannel
{
    protected string $cType = 'wxpay_protocol_cloud';

    public function monitorMode(): string
    {
        return 'server';
    }

    public function config(): array
    {
        return [
            'version'       => '1.0.0',
            'name'          => '微信协议云端-[小账本/收款单]',
            'author'        => '小乐',
            'link'          => 'https://xiaole.ink',
            'type'          => 'wxpay',
            'c_type'        => 'wxpay_protocol_cloud',
            'switch_qr_url' => false,
            'getqrlogin'    => 'yydopen',
            'inputs'        => [
                'openid' => [
                    'name' => 'OpenID',
                    'type' => 'input',
                    'note' => '微信账号OpenID，手动填入已有账号或扫码自动获取',
                ],
                'app_type' => [
                    'name' => '小程序类型',
                    'type' => 'select',
                    'options' => [
                        'book' => '小账本',
                        'recpt' => '收款单',
                    ],
                    'note' => '选择使用的微信小程序类型',
                    'default' => 'book',
                ],
                'sid' => [
                    'name' => '当前SID',
                    'type' => 'readonly',
                    'note' => '系统自动生成的会话令牌，无需手动填写',
                ],
            ],
            'note' => '官方纯云端协议，支持小账本和收款单两种模式',
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {

        if (empty($config['openid'])) {
            return $this->fail('请填写OpenID或扫码获取');
        }
        if (empty($config['app_type'])) {
            return $this->fail('请选择小程序类型');
        }


        if (!\app\core\CloudGuard::isFeatureAllowed('protocol_cloud')) {
            $userCenter = rtrim(\app\core\CloudClient::cloudUrl(), '/') . '/';
            return $this->fail('协议云端功能未授权或已到期，请前往云端授权站开通: ' . $userCenter);
        }


        $cloudClient = $this->getCloudClient();
        $health = $cloudClient->health();
        if (($health['code'] ?? 0) != 1) {
            return $this->fail('云端服务器异常: ' . ($health['msg'] ?? ''));
        }

        //  已有SID时直接验证可用性，避免每次保存都重新获取
        $protocol = new WeChatProtocolCloud($cloudClient, $config['app_type']);
        $sidValid = false;

        if (!empty($config['sid'])) {
            if ($config['app_type'] === 'book') {
                $detail = $protocol->detailOrder($config['sid']);
                $sidValid = ($detail['code'] ?? 0) == 1;
            } else {
                // 收款单：检测SID有效性
                $sidValid = $this->checkRecptSidValid($config['sid']);
            }
        }

        if (!$sidValid) {
            // 无SID或SID不可用时重新获取
            $app_id = $this->getAppId($config['app_type']);

            // 优先使用 openid 作为 ref
            $ref = !empty($config['openid']) ? $config['openid'] : $config['uin'];
            $login = $protocol->login($ref, $app_id);

            if (($login['code'] ?? 0) != 1) {
                return $this->fail('SID换取失败: ' . ($login['msg'] ?? ''));
            }

            $config['sid'] = $login['data']['sid'] ?? '';

            if (empty($config['sid'])) {
                return $this->fail('SID为空，请重新登录');
            }

            // 小账本额外验证账单接口可用性
            if ($config['app_type'] === 'book') {
                $detail = $protocol->detailOrder($config['sid']);
                if (($detail['code'] ?? 0) != 1) {
                    return $this->fail('SID验证失败，请重新登录: ' . ($detail['msg'] ?? ''));
                }
            }
        }

        //  设置刷新计时器
        $config['refresh_time'] = time() + (60 * 60 * 6);
        $config['sid_refresh_time'] = time() + (60 * 60 * 6);

        return $config;
    }

    /**
     * 出码：小账本返回动态收款码，收款单创建指定金额的收款单
     */
    public function getPayQr(array $order, array $config): array
    {
        $cloudClient = $this->getCloudClient();
        $protocol = new WeChatProtocolCloud($cloudClient, $config['app_type'] ?? 'book');

        if ($config['app_type'] === 'book') {
            // 小账本：使用已有SID或按需获取
            $effectiveSid = $config['sid'] ?? '';
            if (empty($effectiveSid)) {
                $app_id = $this->getAppId('book');
                $ref = !empty($config['openid']) ? $config['openid'] : ($config['uin'] ?? '');
                $login = $protocol->login($ref, $app_id);
                if (($login['code'] ?? 0) != 1) {
                    return $this->fail('登录失败: ' . ($login['msg'] ?? ''));
                }
                $effectiveSid = $login['data']['sid'] ?? '';
                if (empty($effectiveSid)) {
                    return $this->fail('SID获取失败');
                }
            }
            $result = $protocol->getpayqrcode($effectiveSid);
            if (($result['code'] ?? 0) == 1) {
                return $this->ok([
                    'qr' => $result['qr_url'],
                    'type' => 'qr',
                    'config' => false,
                ]);
            }
            return $this->fail($result['msg'] ?? '获取收款码失败');
        } elseif ($config['app_type'] === 'recpt') {
            // 收款单：按需获取SID，创建指定金额的收款单
            $effectiveSid = $config['sid'] ?? '';
            if (empty($effectiveSid)) {
                $app_id = $this->getAppId('recpt');
                $ref = !empty($config['openid']) ? $config['openid'] : ($config['uin'] ?? '');
                $login = $protocol->login($ref, $app_id);
                if (($login['code'] ?? 0) != 1) {
                    return $this->fail('收款单登录失败: ' . ($login['msg'] ?? ''));
                }
                $effectiveSid = $login['data']['sid'] ?? '';
                if (empty($effectiveSid)) {
                    return $this->fail('收款单SID获取失败');
                }
                // 回写 SID 到通道配置，供 monitor 轮询使用
                $this->updateChannelSid($channelRow['id'] ?? 0, $effectiveSid);
            }

            [$account_id, $sid] = $this->splitRecptSid($effectiveSid);
            $remark = $this->generateShopName() . substr((string)($order['trade_no'] ?? ''), -5);

            $result = $protocol->createOrder($account_id, $sid, $order['money'] ?? '1.00', $remark);
            if (($result['code'] ?? 0) == 1) {
                return $this->ok([
                    'qr' => $result['qr_url'],
                    'type' => 'qr',
                    'config' => $result['receipt_id'],
                ]);
            }
            return $this->fail($result['msg'] ?? '创建收款单失败');
        }

        return $this->fail('未知的小程序类型: ' . ($config['app_type'] ?? ''));
    }

    /**
     * 心跳保活：定期刷新账号状态和SID
     */
    public function heartbeatCallback(array $channelRow, array $config): array
    {
        $cloudClient = $this->getCloudClient();

        // 每6小时刷新一次账号状态
        if (($config['refresh_time'] ?? 0) < time() || empty($config['refresh_time'])) {
            $config['refresh_time'] = time() + (60 * 60 * 6);

            // 优先使用 openid 作为 ref
            $ref = !empty($config['openid']) ? $config['openid'] : ($config['uin'] ?? '');
            $refresh = $cloudClient->refreshAccount($ref);
            if (($refresh['code'] ?? 0) == -1) {
                // 刷新失败不置离线，仅日志记录
                \think\facade\Log::warning('wxpay_protocol_cloud refresh失败', [
                    'channel' => $channelRow['id'] ?? '',
                    'msg' => $refresh['msg'] ?? '',
                ]);
            } else {
                $config['status'] = 1;
            }
        }

        // 每6小时重新获取SID
        if (($config['sid_refresh_time'] ?? 0) < time() || empty($config['sid_refresh_time'])) {
            $config['sid_refresh_time'] = time() + (60 * 60 * 6);

            $app_id = $this->getAppId($config['app_type'] ?? 'book');
            $protocol = new WeChatProtocolCloud($cloudClient, $config['app_type'] ?? 'book');
            // 优先使用 openid 作为 ref
            $ref = !empty($config['openid']) ? $config['openid'] : ($config['uin'] ?? '');
            $login = $protocol->login($ref, $app_id);

            if (($login['code'] ?? 0) == 1) {
                $config['sid'] = $login['data']['sid'] ?? '';
            } else {
                // SID刷新失败不置离线，旧SID还能继续用；只在日志记录
                \think\facade\Log::warning('wxpay_protocol_cloud SID刷新失败', [
                    'channel' => $channelRow['id'] ?? '',
                    'msg' => $login['msg'] ?? '',
                ]);
            }
        }

        return $config;
    }

    /**
     * 获取监控请求（小账本：拉列表；收款单：需订单 receipt_id）
     */
    public function getPayCurl(array $channelRow, array $config, array $order = []): array
    {
        if ($config['app_type'] === 'book') {
            // 小账本：拉账单列表
            $url = 'https://payapp.weixin.qq.com/qrappzd/user/incomelistflexible?sid=' . ($config['sid'] ?? '') . '&v=7.2.3';
            $post = json_encode([
                'v' => '7.2.3',
                'sid' => $config['sid'] ?? '',
                'start_time' => strtotime(date("Y-m-d H:00:00")),
                'end_time' => time(),
                'page_size' => 20,
                'sort' => 'desc',
                'is_first' => true,
                'last_create_time' => 0,
                'last_id' => '',
            ]);

            return [
                'url' => $url,
                'post' => $post,
                'cookie' => '',
                'ua' => '',
            ];
        } elseif ($config['app_type'] === 'recpt' && !empty($order['config']) && !empty($config['sid'])) {
            // 收款单：单笔查询（需 receipt_id + SID）
            $parts = explode('|', $config['sid'] ?? '');
            $real_account_id = $parts[0] ?? '';
            $account_type = $parts[1] ?? '1';
            $realSid = count($parts) >= 4 ? $parts[3] : (count($parts) >= 3 ? $parts[2] : '');

            $receipt_mgr = $this->getReceiptMgr($account_type);
            $url = "https://payapp.wechatpay.cn/{$receipt_mgr}/receipt/detail"
                . "?miniprogram_version=3.15.9&page_index=1&page_size=10"
                . "&account_type={$account_type}&account_id={$real_account_id}"
                . "&sid={$realSid}&receipt_id=" . ($order['config'] ?? '');

            return [
                'url' => $url,
                'post' => '',
                'cookie' => '',
                'ua' => '',
            ];
        }

        return [];
    }

    /**
     * 解析账单结果
     */
    public function getPayCurlCallback(array $channelRow, array $config, $curlData): array
    {
        $json = json_decode((string)$curlData, true);
        if (!isset($config['bill']) || !is_array($config['bill'])) $config['bill'] = [];

        if ($config['app_type'] === 'book') {
            // 小账本：解析账单列表（最近300s=5分钟，覆盖 worker 空闲冷却期60s）
            if (isset($json['data']['data_list']) && is_array($json['data']['data_list'])) {
                foreach ($json['data']['data_list'] as $order) {
                    if ($order['timestamp'] > (time() - 300)) {
                        $money = sprintf("%.2f", substr(sprintf("%.4f", ($order['fee'] / 100)), 0, -2));
                        if (!is_array($config['bill'])) $config['bill'] = [];
                        $config['bill'][] = [
                            'price' => $money,
                            'config' => $order['trans_id'],
                        ];
                    }
                }
            }
        } elseif ($config['app_type'] === 'recpt') {
            // 收款单：解析单笔支付结果
            if (isset($json['data']['receipt']['state']) && $json['data']['receipt']['state'] === 'success') {
                foreach (($json['data']['receipt']['order'] ?? []) as $order) {
                    $money = sprintf("%.2f", substr(sprintf("%.4f", ($order['fee'] / 100)), 0, -2));
                    $receiptId = $json['data']['receipt']['receipt_id'] ?? '';
                    // 记录日志诊断空config问题
                    if (empty($receiptId)) {
                        \think\facade\Log::warning('recpt_callback_empty_receipt', [
                            'response' => mb_substr((string)$curlData, 0, 1000),
                        ]);
                    }
                    $config['bill'][] = [
                        'price' => $money,
                        'config' => $receiptId,
                    ];
                }
            }
        }

        return $config;
    }

    /**
     * 服务端监控一次：拉账单 + 验证在线状态
     */
    public function monitor(array $channelRow, array $config): array
    {
        // 1. 心跳保活
        $config = $this->heartbeatCallback($channelRow, $config);

        // 2. 拉取账单
        if ($config['app_type'] === 'book') {
            $req = $this->getPayCurl($channelRow, $config, []);
            $data = '';
            if (is_array($req) && !empty($req['url'])) {
                $data = $this->httpRequest($req);
            }
            $config = $this->getPayCurlCallback($channelRow, $config, $data);
        } elseif ($config['app_type'] === 'recpt') {
            // 收款单：逐笔轮询待支付订单的收款状态
            // 如无SID则按需获取
            $effectiveSid = $config['sid'] ?? '';
            if (empty($effectiveSid)) {
                $cloudClient = $this->getCloudClient();
                $protocol = new WeChatProtocolCloud($cloudClient, 'recpt');
                $ref = !empty($config['openid']) ? $config['openid'] : ($config['uin'] ?? '');
                $login = $protocol->login($ref, 'wx264e9b6d4d484f51');
                if (($login['code'] ?? 0) == 1) {
                    $effectiveSid = $login['data']['sid'] ?? '';
                    if ($effectiveSid) {
                        $config['sid'] = $effectiveSid;
                        $this->updateChannelSid($channelRow['id'] ?? 0, $effectiveSid);
                    }
                }
            }
            $config['bill'] = false;
            $pendingOrders = \app\common\model\PayOrder::where('channel_id', $channelRow['id'])
                ->where('status', 0)
                ->where('config', '<>', '0')
                ->where('config', '<>', '')
                ->whereNotNull('config')
                ->select();
            foreach ($pendingOrders as $order) {
                $orderArr = $order->toArray();
                if (empty($orderArr['config'])) continue;
                $req = $this->getPayCurl($channelRow, $config, $orderArr);
                if (is_array($req) && !empty($req['url'])) {
                    $data = $this->httpRequest($req);
                    $json = json_decode((string)$data, true);
                    // 直接用订单的config(receipt_id)和价格，不依赖API解析
                    if (isset($json['data']['receipt']['state']) && $json['data']['receipt']['state'] === 'success') {
                        if (!is_array($config['bill'])) $config['bill'] = [];
                        $config['bill'][] = [
                            'price' => $orderArr['price'],
                            'config' => $orderArr['config'],
                        ];
                        // 支付成功后自动关闭并删除收款单
                        try {
                            $cloudClient = $this->getCloudClient();
                            $closeProtocol = new WeChatProtocolCloud($cloudClient, 'recpt');
                            $parts = explode('|', $effectiveSid);
                            if (count($parts) >= 4) {
                                $closeAccountId = $parts[0] . '|' . $parts[1] . '|' . $parts[2];
                                $closeSid = $parts[3];
                            } elseif (count($parts) >= 3) {
                                $closeAccountId = $parts[0] . '|' . $parts[1];
                                $closeSid = $parts[2];
                            } else {
                                $closeAccountId = '';
                                $closeSid = '';
                            }
                            if ($closeAccountId && $closeSid) {
                                $closeProtocol->closeDel($closeAccountId, $closeSid, $orderArr['config']);
                            }
                        } catch (\Throwable $e) {
                            // 关闭失败不影响主流程
                        }
                    }
                }
            }
        }

        // 3. 验证在线状态
        $cloudClient = $this->getCloudClient();
        $protocol = new WeChatProtocolCloud($cloudClient, $config['app_type'] ?? 'book');

        if ($config['app_type'] === 'book') {
            if (!empty($config['sid'])) {
                $detail = $protocol->detailOrder($config['sid']);
                $config['status'] = (($detail['code'] ?? 0) == 1) ? '1' : false;
            }
        } elseif ($config['app_type'] === 'recpt') {
            $config['status'] = (!empty($config['sid']) && $this->checkRecptSidValid($config['sid'])) ? '1' : false;
        }

        return $config;
    }

    /**
     * 订单失效关闭：收款单模式关闭并删除收款单
     */
    public function tradeClose(array $order, array $config): array
    {
        if ($config['app_type'] === 'recpt' && !empty($order['config'])) {
            [$account_id, $sid] = $this->splitRecptSid($config['sid'] ?? '');
            $cloudClient = $this->getCloudClient();
            $protocol = new WeChatProtocolCloud($cloudClient, $config['app_type']);
            $protocol->closeDel($account_id, $sid, $order['config']);
        }

        return ['code' => 1, 'msg' => ''];
    }

    /**
     * 回写 SID 到通道配置（供 monitor 轮询使用）
     */
    protected function updateChannelSid(int $channelId, string $sid): void
    {
        if ($channelId <= 0 || empty($sid)) return;
        try {
            $channel = \app\common\model\PayChannel::find($channelId);
            if (!$channel) return;
            $config = \app\common\model\PayChannel::decryptConfig($channel->config);
            if (($config['sid'] ?? '') === $sid) return; // 没变化
            $config['sid'] = $sid;
            $config['sid_refresh_time'] = time() + (60 * 60 * 6);
            $config['refresh_time'] = time() + (60 * 60 * 6);
            $channel->save(['config' => \app\common\model\PayChannel::encryptConfig($config)]);
        } catch (\Throwable $e) {
            // 静默失败，不影响主流程
        }
    }

    /**
     * 检测收款单 SID 是否有效
     */
    protected function checkRecptSidValid(string $sid): bool
    {
        if (empty($sid)) return false;
        $parts = explode('|', $sid);
        if (count($parts) < 3) return false;
        $aid = $parts[0];
        $at = $parts[1];
        $sidClean = count($parts) >= 4 ? $parts[3] : $parts[2];
        $mgr = $at == '1' ? 'receiptmdmgr' : ($at == '2' ? 'receiptwxmgr' : 'receiptsjtmgr');
        $url = "https://payapp.wechatpay.cn/{$mgr}/receipt/list?miniprogram_version=3.15.9&account_type={$at}&account_id={$aid}&sid={$sidClean}";
        $post = json_encode(['miniprogram_version' => '3.15.9', 'start_time' => 0, 'end_time' => 0, 'page_size' => 1, 'state' => [], 'shop_id_list' => [], 'sid' => $sidClean]);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Linux; U; Android 4.4.1; zh-cn; R815T Build/JOP40D) AppleWebKit/533.1 (KHTML, like Gecko)Version/4.0 MQQBrowser/4.5 Mobile Safari/533.1');
        $resp = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($resp, true);
        if (isset($json['errcode']) && $json['errcode'] == 0) return true;
        if (isset($json['msg']) && strpos($json['msg'], '效验登录态失败') !== false) return false;
        return true;
    }

    /**
     * 获取app_id
     */
    protected function getAppId(string $app_type): string
    {
        return $app_type === 'book'
            ? WeChatProtocolCloud::APP_ID_BOOK
            : WeChatProtocolCloud::APP_ID_RECPT;
    }

    /**
     * 拆分收款单SID：account_id|account_type|sid
     * @return array{0:string,1:string} [account_id(含type), sid]
     */
    protected function splitRecptSid(string $raw): array
    {
        $parts = explode('|', $raw);
        $count = count($parts);
        if ($count >= 4) {
            // 4段格式：account_id|account_type|shop_id|sid
            return [$parts[0] . '|' . $parts[1] . '|' . $parts[2], $parts[3]];
        } elseif ($count >= 3) {
            // 3段格式：account_id|account_type|sid
            return [$parts[0] . '|' . $parts[1], $parts[2]];
        }
        return ['', ''];
    }

    /**
     * 根据account_type获取收款单管理路径
     */
    protected function getReceiptMgr(string $account_type): string
    {
        $mgr_map = [
            '1' => 'receiptmdmgr',  // 经营码
            '2' => 'receiptwxmgr',   // 商业码
            '3' => 'receiptsjtmgr',  // 社交账本
        ];
        return $mgr_map[$account_type] ?? 'receiptsjtmgr';
    }

    /**
     * 生成随机店铺名称
     */
    protected function generateShopName(): string
    {
        $shops = [
            '鲜果时光', '云端便利店', '星光小铺', '悦享生活馆', '缤纷购物',
            '优选好物', '品质生活', '时尚潮流', '健康之选', '美好时光',
        ];
        return $shops[array_rand($shops)];
    }

    /**
     * 获取云端客户端实例
     */
    protected function getCloudClient(): ProtocolCloud
    {
        return new ProtocolCloud();
    }

    /**
     * HTTP请求封装
     */
    protected function httpRequest(array $req): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $req['url']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: */*',
            'Accept-Encoding: gzip,deflate,sdch',
            'Accept-Language: zh-CN,zh;q=0.8',
            'Connection: close',
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if (!empty($req['post'])) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $req['post']);
        }
        if (!empty($req['cookie'])) {
            curl_setopt($ch, CURLOPT_COOKIE, $req['cookie']);
        }
        if (!empty($req['ua'])) {
            curl_setopt($ch, CURLOPT_USERAGENT, $req['ua']);
        } else {
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Linux; U; Android 4.4.1; zh-cn; R815T Build/JOP40D) AppleWebKit/533.1 (KHTML, like Gecko)Version/4.0 MQQBrowser/4.5 Mobile Safari/533.1');
        }

        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($ch);
        curl_close($ch);

        return (string)$ret;
    }
}
