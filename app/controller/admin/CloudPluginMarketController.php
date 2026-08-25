<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\payment\PaymentManager;
use app\payment\Plugin\PluginManager;
use app\service\CloudInstanceClient;
use support\Request;
use support\Response;
use Throwable;

/**
 * CXPAY 官方插件商城在线下单、收银台核销与一键下载安装交互控制器
 */
final class CloudPluginMarketController
{
    private CloudInstanceClient $client;
    private static string $entitlementFile = '';

    public function __construct(?CloudInstanceClient $client = null)
    {
        $this->client = $client ?? new CloudInstanceClient();
        self::$entitlementFile = runtime_path() . '/instance/entitlements.json';
    }

    /**
     * 查询本地实例激活状态与身份元数据
     */
    public function instanceStatus(): Response
    {
        $identity = $this->client->getIdentity();
        return json([
            'code' => 1,
            'msg' => 'ok',
            'data' => [
                'instance_id'  => $identity['instance_id'] ?? 'inst_' . substr($identity['fingerprint'], 0, 12),
                'domain'       => $identity['domain'] ?? 'cs.fcwan.cn',
                'public_key'   => $identity['public_key'],
                'fingerprint'  => $identity['fingerprint'],
                'activated'    => (bool)($identity['activated'] ?? false),
                'activated_at' => $identity['activated_at'] ?? date('Y-m-d H:i:s'),
                'is_agent'     => (bool)($identity['is_agent'] ?? false),
                'license_type' => (string)($identity['license_type'] ?? 'STANDARD'),
                'portal_url'   => rtrim((string)config('cloud.portal_url', 'https://cloud.fcwan.cn'), '/'),
            ],
        ]);
    }

    /**
     * 一键激活绑定当前 CXPAY 实例
     */
    public function activateInstance(Request $request): Response
    {
        $legacyKey = trim((string)$request->post('legacy_key', ''));
        $domain = trim((string)$request->post('domain', ''));
        if ($domain === '') {
            $domain = (string)$request->host();
        }

        if ($legacyKey === '') {
            return json(['code' => -1, 'msg' => '请输入授权凭据 (License Key)']);
        }

        try {
            $result = $this->client->activateWithLegacyKey($legacyKey, $domain);
            return json([
                'code' => 1,
                'msg'  => '实例激活成功，已完成安全绑定',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return json([
                'code' => -1,
                'msg'  => '实例激活失败：' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取云端插件商城列表（动态聚合云端最新目录、本地数据库商品及本地已安装/授权状态）
     */
    public function getCloudMarket(): Response
    {
        $entitlements = $this->getEntitlements();
        $installedPlugins = PluginManager::installed();
        $registeredDrivers = PaymentManager::getRegisteredDrivers();

        // 基础官方基准目录 — 所有插件均需从插件商城下载安装，无内置驱动
        // entitled 字段反映该插件在本实例是否已安装且持有有效授权
        $baseCatalog = [
            [
                'plugin_id'      => 'cxpay.driver.alipay_face_pay',
                'c_type'         => 'alipay_face_pay',
                'name'           => '支付宝当面付 / 官方直连商户插件',
                'category'       => 'alipay',
                'latest_version' => '3.0.0',
                'author'         => 'CXPAY 官方团队',
                'description'    => '直连支付宝开放平台 OpenAPI，支持 RSA2 密钥签名，官方商户直连结算，0 掉单率。',
                'price'          => '0.00',
                'price_month'    => '0.00',
                'price_forever'  => '0.00',
                'price_text'     => '免费 · 从插件商城下载安装',
                // 已通过插件商城安装 = entitled
                'entitled'       => isset($installedPlugins['cxpay.driver.alipay_face_pay'])
                    && ($installedPlugins['cxpay.driver.alipay_face_pay']['enabled'] ?? false),
            ],
            [
                'plugin_id'      => 'cxpay.driver.alipay_cookie_cloud',
                'c_type'         => 'alipay_cookie_cloud',
                'name'           => '支付宝免挂云端 / Cookie 账单轮询插件',
                'category'       => 'alipay',
                'latest_version' => '1.2.0',
                'author'         => 'CXPAY 官方团队',
                'description'    => '提供支付宝固定收款码并绑定商家 Cookie，服务端后台定时自动轮询账单入账，免手机/PC挂机。v1.2.0 新增 Session 防风控保活与连续失败容错重试机制。',
                'price'          => '0.00',
                'price_month'    => '0.00',
                'price_forever'  => '0.00',
                'price_text'     => '免费 · 从插件商城下载安装',
                'entitled'       => isset($installedPlugins['cxpay.driver.alipay_cookie_cloud'])
                    && ($installedPlugins['cxpay.driver.alipay_cookie_cloud']['enabled'] ?? false),
            ],
            [
                'plugin_id'      => 'cxpay.driver.wechat_dy_bill',
                'c_type'         => 'wechat_dy_bill',
                'name'           => '微信店员小账本（官方免挂监控插件）',
                'category'       => 'wxpay',
                'latest_version' => '2.1.0',
                'author'         => 'CXPAY 官方团队',
                'description'    => '微信官方收款小账本免挂机协议直连，支持店员小号隔离监控，资金直入老板微信，零封号风险。',
                'price'          => '129.00',
                'price_month'    => '29.00',
                'price_forever'  => '129.00',
                'price_text'     => '月费 ¥29.00 / 永久 ¥129.00',
                'entitled'       => (isset($entitlements['cxpay.driver.wechat_dy_bill']) || isset($entitlements['wechat_dy_bill']))
                    && isset($installedPlugins['cxpay.driver.wechat_dy_bill'])
                    && ($installedPlugins['cxpay.driver.wechat_dy_bill']['enabled'] ?? false),
            ],
            [
                'plugin_id'      => 'cxpay.app_asst_universal',
                'c_type'         => 'app_asst_universal',
                'name'           => 'CXPay 手机挂机监控助手（微信 / 支付宝 / QQ 三合一全能版）',
                'category'       => 'all_in_one',
                'latest_version' => '2.0.0',
                'author'         => 'CXPAY 官方团队',
                'description'    => '只需安装 1 个 CXPayAssistant.apk 官方安卓助手，在同一台手机上同时秒级监听微信赞赏码、支付宝收钱码与 QQ 钱包到账通知，支持多通道并发核销。',
                'price'          => '99.00',
                'price_month'    => '29.00',
                'price_forever'  => '99.00',
                'price_text'     => '月费 ¥29.00 / 永久 ¥99.00',
                'entitled'       => (isset($entitlements['cxpay.app_asst_universal'])
                    || isset($entitlements['cxpay.driver.wxpay_app_asst'])
                    || isset($entitlements['cxpay.driver.alipay_app_asst'])
                    || isset($entitlements['cxpay.driver.qqpay_app_asst']))
                    && isset($installedPlugins['cxpay.app_asst_universal'])
                    && ($installedPlugins['cxpay.app_asst_universal']['enabled'] ?? false),
            ],
            [
                'plugin_id'      => 'cxpay.driver.usdt_trc20',
                'c_type'         => 'usdt_trc20',
                'name'           => 'USDT TRC-20 链上波场监听与自动归集',
                'category'       => 'other',
                'latest_version' => '1.5.0',
                'author'         => 'CXPAY 官方团队',
                'description'    => '基于 TronGrid 链上区块监听，商户独立地址收款，达到确认数自动回调核销并支持自动归集。',
                'price'          => '129.00',
                'price_month'    => '29.00',
                'price_forever'  => '129.00',
                'price_text'     => '月费 ¥29.00 / 永久 ¥129.00',
                'entitled'       => (isset($entitlements['cxpay.driver.usdt_trc20']) || isset($entitlements['usdt_trc20']))
                    && isset($installedPlugins['cxpay.driver.usdt_trc20'])
                    && ($installedPlugins['cxpay.driver.usdt_trc20']['enabled'] ?? false),
            ],
        ];


        // 2. 尝试从云端拉取最新动态目录与官方实时定价
        $cloudList = [];
        $aliasMap = [
            'cxpay.wxpay.clerk_adapter' => 'cxpay.driver.wechat_dy_bill',
            'cxpay.alipay.scan_monitor' => 'cxpay.driver.alipay_cookie_cloud',
            'cxpay.wxpay.app_monitor'   => 'cxpay.app_asst_universal',
            'cxpay.alipay.app_monitor'  => 'cxpay.app_asst_universal',
            'cxpay.driver.wxpay_app_asst'  => 'cxpay.app_asst_universal',
            'cxpay.driver.alipay_app_asst' => 'cxpay.app_asst_universal',
            'cxpay.driver.qqpay_app_asst'  => 'cxpay.app_asst_universal',
        ];

        try {
            $cloudRes = $this->client->fetchCatalog();
            if (($cloudRes['code'] ?? 0) === 1) {
                $rawList = $cloudRes['data']['plugins'] ?? $cloudRes['data']['list'] ?? [];
                if (is_array($rawList)) {
                    foreach ($rawList as $p) {
                        $rawPid = (string)($p['plugin_id'] ?? '');
                        if ($rawPid === '') continue;
                        $pid = $aliasMap[$rawPid] ?? $rawPid;

                        $manifest = $p['manifest'] ?? [];
                        $pricing = $manifest['pricing'] ?? [];
                        $priceForever = (float)($p['price_forever'] ?? $pricing['price_forever'] ?? $pricing['price_standard'] ?? $p['price'] ?? $manifest['retail_price'] ?? 99.00);
                        $priceMonth = (float)($p['price_month'] ?? $pricing['price_month'] ?? ($priceForever > 0 ? min(29.00, round($priceForever * 0.3, 2)) : 0.00));
                        $cType = (string)($p['c_type'] ?? $manifest['c_type'] ?? str_replace('cxpay.driver.', '', $pid));
                        
                        // 规范化 cType 与废弃过滤
                        if ($cType === 'clerk_adapter') $cType = 'wechat_dy_bill';
                        if ($cType === 'scan_monitor') $cType = 'alipay_cookie_cloud';
                        if (class_exists(\app\payment\RemovedPaymentDrivers::class) && in_array($cType, \app\payment\RemovedPaymentDrivers::all(), true)) {
                            continue;
                        }
                        if (in_array($cType, ['wxpay_app_asst', 'alipay_app_asst', 'qqpay_app_asst'], true)) {
                            $cType = 'app_asst_universal';
                            $pid = 'cxpay.app_asst_universal';
                        }

                        $category = (string)($p['category'] ?? $manifest['category'] ?? (str_starts_with($cType, 'wx') || str_starts_with($cType, 'wechat') ? 'wxpay' : (str_starts_with($cType, 'ali') ? 'alipay' : (str_starts_with($cType, 'qq') ? 'qqpay' : 'other'))));
                        $isFree = ($priceForever <= 0 && $priceMonth <= 0) || in_array($cType, ['alipay_face_pay', 'alipay_cookie_cloud', 'alipay_app_asst'], true);

                        $priceText = $isFree
                            ? '免费内置 · 永久授权'
                            : '月费 ¥' . number_format($priceMonth, 2, '.', '') . ' / 永久 ¥' . number_format($priceForever, 2, '.', '');

                        $cloudList[$pid] = [
                            'plugin_id'      => $pid,
                            'c_type'         => $cType,
                            'name'           => $p['name'] ?? $pid,
                            'category'       => $category,
                            'latest_version' => $p['latest_version'] ?? '1.0.0',
                            'author'         => $p['publisher'] ?? 'CXPAY 官方团队',
                            'description'    => $p['description'] ?? '',
                            'price'          => number_format($priceForever, 2, '.', ''),
                            'price_month'    => number_format($priceMonth, 2, '.', ''),
                            'price_forever'  => number_format($priceForever, 2, '.', ''),
                            'price_text'     => $priceText,
                            'is_free'        => $isFree,
                            'entitled'       => (bool)($p['entitled'] ?? $isFree),
                            'status'         => $p['status'] ?? 'ACTIVE',
                        ];
                    }
                }
            }
        } catch (\Throwable) {
            // 云端离线或未绑定时优雅降级
        }

        // 3. 严格按 cType / 真实驱动进行全量去重与白名单过滤
        $catalogByCType = [];
        // 先放入标准基准目录
        foreach ($baseCatalog as $item) {
            $cType = $item['c_type'];
            $catalogByCType[$cType] = $item;
        }

        // 用云端返回的最新数据（名称、描述、版本、定价、上下架状态）全字段实时同步覆盖
        foreach ($cloudList as $pid => $item) {
            $cType = $item['c_type'];
            if (isset($catalogByCType[$cType])) {
                if (!empty($item['name'])) {
                    $catalogByCType[$cType]['name'] = $item['name'];
                }
                if (!empty($item['description'])) {
                    $catalogByCType[$cType]['description'] = $item['description'];
                }
                if (!empty($item['latest_version'])) {
                    $catalogByCType[$cType]['latest_version'] = $item['latest_version'];
                }
                $catalogByCType[$cType]['status'] = $item['status'] ?? 'ACTIVE';
                if (!$catalogByCType[$cType]['entitled']) {
                    $catalogByCType[$cType]['entitled'] = $item['entitled'];
                }
                if ($item['price_forever'] > 0 && !in_array($cType, ['alipay_face_pay', 'alipay_cookie_cloud', 'alipay_app_asst'], true)) {
                    $catalogByCType[$cType]['price'] = $item['price'];
                    $catalogByCType[$cType]['price_month'] = $item['price_month'];
                    $catalogByCType[$cType]['price_forever'] = $item['price_forever'];
                    $catalogByCType[$cType]['price_text'] = $item['price_text'];
                }
            } else {
                $catalogByCType[$cType] = $item;
            }

            if ($cType === 'app_asst_universal' || $pid === 'cxpay.app_asst_universal') {
                $catalogByCType[$cType]['name'] = 'CXPay 手机挂机监控助手（微信 / 支付宝 / QQ 三合一全能版）';
                $catalogByCType[$cType]['description'] = '只需安装 1 个 CXPayAssistant.apk 官方安卓助手，在同一台手机上同时秒级监听微信赞赏码、支付宝收钱码与 QQ 钱包到账通知，支持多通道并发核销。';
                $catalogByCType[$cType]['category'] = 'all_in_one';
            }
        }

        // 4. 丰富每个真实可用插件在当前节点的实际运行与安装状态
        $finalList = [];
        foreach ($catalogByCType as $cType => $item) {
            $pid = $item['plugin_id'];
            $isEntitled = ($item['entitled'] ?? false)
                || isset($entitlements[$pid])
                || isset($entitlements[$cType])
                || isset($entitlements['cxpay.driver.' . $cType])
                || \app\service\PluginLicenseService::isChannelEntitled($cType);

            $status = strtoupper((string)($item['status'] ?? 'ACTIVE'));
            $isDelisted = in_array($status, ['INACTIVE', 'DELISTED', 'DISABLED', 'OFFLINE', '0'], true);

            // 若云端已下架该插件，且当前站点未曾购买/开通过该插件授权，则在商城中自动完全隐藏
            if ($isDelisted && !$isEntitled) {
                continue;
            }

            $isInstalled = isset($installedPlugins[$pid]);
            $isEnabled = $isInstalled && (($installedPlugins[$pid]['enabled'] ?? false) === true);
            $installedVersion = (string)($installedPlugins[$pid]['version'] ?? $installedPlugins[$pid]['active_version'] ?? '1.0.0');
            $latestVersion = (string)($item['latest_version'] ?? '1.0.0');
            $hasUpdate = $isInstalled && version_compare($latestVersion, $installedVersion, '>');

            $item['entitled'] = $isEntitled;
            $item['installed'] = $isInstalled;
            $item['enabled'] = $isEnabled;
            $item['installed_version'] = $installedVersion;
            $item['has_update'] = $hasUpdate;
            $item['delisted'] = $isDelisted;
            $finalList[] = $item;
        }

        $identity = $this->client->getIdentity();
        $isActivated = ($identity['activated'] ?? false) === true;

        return json([
            'code' => 1,
            'msg'  => 'ok',
            'data' => [
                'list'       => $finalList,
                'plugins'    => $finalList,
                'total'      => count($finalList),
                'activated'  => $isActivated,
                'portal_url' => rtrim((string)config('cloud.portal_url', 'https://cloud.fcwan.cn'), '/'),
            ],
        ]);
    }

    /**
     * 创建官方插件购买订单
     *
     * 调用云端收款核心接口（/api/payment/v1/orders/create），获取官方网关真实支付二维码。
     * 款项100%直接进入官方账户，前端扫码直接调起微信/支付宝支付。
     */
    public function createPurchaseOrder(Request $request): Response
    {
        $pluginId   = trim((string)$request->post('plugin_id', ''));
        $payChannel = trim((string)$request->post('pay_type', 'wxpay')); // wxpay | alipay
        $period     = trim((string)$request->post('period', 'forever')); // month | forever
        $period     = in_array(strtolower($period), ['month', 'monthly'], true) ? 'month' : 'forever';

        if ($pluginId === '') {
            return json(['code' => -1, 'msg' => '请选择需要开通的插件']);
        }

        $identity   = $this->client->getIdentity();
        $instanceId = (string)($identity['instance_id'] ?? '');
        $domain     = (string)$request->host();

        if ($instanceId === '') {
            return json(['code' => -1, 'msg' => '当前实例尚未激活，请先完成云端实例绑定']);
        }

        // 调用云端创建订单 API
        $rawApiUrl    = (string)config('cloud.api_url', 'https://cloud.fcwan.cn');
        $baseCloudUrl = preg_replace('#/api/?$#', '', rtrim($rawApiUrl, '/')) ?: 'https://cloud.fcwan.cn';
        $createApi    = "{$baseCloudUrl}/api/payment/v1/orders/create";

        $ch = curl_init($createApi);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'plugin_id'   => $pluginId,
                'instance_id' => $instanceId,
                'pay_type'    => $payChannel,
                'period'      => $period,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $rawResp = (string)curl_exec($ch);
        curl_close($ch);

        $res = json_decode($rawResp, true);
        if (!is_array($res) || ($res['code'] ?? 0) !== 1 || empty($res['data'])) {
            $errMsg = $res['msg'] ?? '云端下单响应异常，请检查云端收款配置';
            return json(['code' => -1, 'msg' => $errMsg]);
        }

        $data       = $res['data'];
        $orderNo    = (string)$data['order_no'];
        $amount     = (float)$data['amount'];
        $qrCodeUrl  = (string)($data['qr_code_url'] ?? $data['pay_url'] ?? '');
        $orderPeriod = (string)($data['period'] ?? $period);

        // 写入本地 runtime/orders 临时缓存
        $orderInfo = [
            'order_no'        => $orderNo,
            'plugin_id'       => $pluginId,
            'pay_type'        => $payChannel,
            'period'          => $orderPeriod,
            'money'           => number_format($amount, 2, '.', ''),
            'status'          => 'PENDING',
            'create_time'     => time(),
            'expire_time'     => time() + 900,
            'instance_id'     => $instanceId,
            'domain'          => $domain,
            'qr_code_content' => $qrCodeUrl,
        ];

        $ordersDir = runtime_path() . '/orders';
        if (!is_dir($ordersDir)) {
            @mkdir($ordersDir, 0777, true);
        }
        @file_put_contents("{$ordersDir}/{$orderNo}.json", json_encode($orderInfo, JSON_UNESCAPED_UNICODE));

        return json([
            'code' => 1,
            'msg'  => '收银订单创建成功',
            'data' => [
                'order_no'        => $orderNo,
                'trade_no'        => $orderNo,
                'plugin_id'       => $pluginId,
                'money'           => number_format($amount, 2, '.', ''),
                'pay_type'        => $payChannel,
                'period'          => $orderPeriod,
                'qr_code_content' => $qrCodeUrl,
                'expire_seconds'  => 900,
            ]
        ]);
    }

    /**
     * 查询订单支付状态并同步授权（联动云端核销与本地缓存）
     */
    public function checkOrderStatus(Request $request): Response
    {
        $orderNo = trim((string)$request->post('order_no', ''));
        if ($orderNo === '') {
            return json(['code' => -1, 'msg' => '订单号不能为空']);
        }

        $orderFile = runtime_path() . "/orders/{$orderNo}.json";
        $order = [];
        if (file_exists($orderFile)) {
            $order = json_decode((string)file_get_contents($orderFile), true) ?: [];
        }

        $isPaid   = ($order['status'] ?? '') === 'PAID';
        $pluginId = $order['plugin_id'] ?? '';

        // 轮询云端确认订单状态
        if (!$isPaid) {
            $rawApiUrl    = (string)config('cloud.api_url', 'https://cloud.fcwan.cn');
            $baseCloudUrl = preg_replace('#/api/?$#', '', rtrim($rawApiUrl, '/')) ?: 'https://cloud.fcwan.cn';
            $queryApi     = "{$baseCloudUrl}/api/payment/v1/orders/query";

            $ch = curl_init($queryApi);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['order_no' => $orderNo]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $rawQuery = (string)curl_exec($ch);
            curl_close($ch);

            $qRes = json_decode($rawQuery, true);
            if (is_array($qRes) && ($qRes['code'] ?? 0) === 1 && !empty($qRes['data'])) {
                if (($qRes['data']['paid'] ?? false) === true || ($qRes['data']['status'] ?? '') === 'PAID') {
                    $isPaid = true;
                    if ($pluginId === '') {
                        $pluginId = (string)($qRes['data']['plugin_id'] ?? '');
                    }
                    $period = (string)($order['period'] ?? $qRes['data']['period'] ?? 'forever');
                    if ($pluginId !== '') {
                        $this->grantEntitlementLocally($pluginId, $period);
                    }
                    if (file_exists($orderFile)) {
                        $order['status']   = 'PAID';
                        $order['pay_time'] = time();
                        @file_put_contents($orderFile, json_encode($order, JSON_UNESCAPED_UNICODE));
                    }
                }
            }
        }

        $period = (string)($order['period'] ?? 'forever');

        return json([
            'code' => 1,
            'msg'  => 'ok',
            'data' => [
                'order_no'  => $orderNo,
                'paid'      => $isPaid,
                'status'    => $isPaid ? 'PAID' : 'PENDING',
                'plugin_id' => $pluginId,
                'period'    => $period,
            ]
        ]);
    }

    /**
     * 确认付款并触发云端自动开通授权（仅当数据库订单真实已付款时才颁发授权）
     * 安全说明：此接口不信任客户端传入的任何"已付款"声明，必须通过数据库 Order
     * 表 status=1 双重校验后才颁发授权，杜绝绕过支付直接开通的可能。
     */
    public function confirmPayment(Request $request): Response
    {
        $orderNo = trim((string)$request->post('order_no', ''));
        if ($orderNo === '') {
            return json(['code' => -1, 'msg' => '订单号不能为空']);
        }

        $orderFile = runtime_path() . "/orders/{$orderNo}.json";
        $order = [];
        if (file_exists($orderFile)) {
            $order = json_decode((string)file_get_contents($orderFile), true) ?: [];
        }

        $pluginId = (string)($order['plugin_id'] ?? '');

        // ── 安全核心：必须验证数据库中真实付款状态 ───────────────────────────
        $isPaid = ($order['status'] ?? '') === 'PAID';

        if (!$isPaid) {
            try {
                $tradeNo = (string)($order['trade_no'] ?? $orderNo);
                $dbOrder = \app\model\Order::where('out_trade_no', $orderNo)
                    ->orWhere('trade_no', $orderNo)
                    ->orWhere('out_trade_no', $tradeNo)
                    ->orWhere('trade_no', $tradeNo)
                    ->first();

                if ($dbOrder && (int)$dbOrder->status === 1) {
                    $isPaid = true;
                    // 从数据库订单 param 补充 pluginId
                    if ($pluginId === '' && str_starts_with((string)$dbOrder->param, 'plugin:')) {
                        $parts = explode(':', (string)$dbOrder->param);
                        $pluginId = $parts[1] ?? '';
                    }
                    // 同步本地文件状态
                    $order['status'] = 'PAID';
                    $order['pay_time'] = (int)$dbOrder->pay_time ?: time();
                    @file_put_contents($orderFile, json_encode($order, JSON_UNESCAPED_UNICODE));
                }
            } catch (\Throwable) {
                // 数据库不可用时禁止授权，避免离线时被利用
                $isPaid = false;
            }
        }

        if (!$isPaid) {
            return json([
                'code' => -1,
                'msg'  => '订单尚未完成真实支付，请先完成扫码付款后再刷新授权状态',
                'data' => ['order_no' => $orderNo, 'status' => 'PENDING'],
            ]);
        }

        if ($pluginId === '') {
            return json(['code' => -1, 'msg' => '订单关联的插件信息缺失，请联系技术支持']);
        }

        $period = (string)($order['period'] ?? 'forever');

        // 付款验证通过，颁发授权
        $this->grantEntitlementLocally($pluginId, $period);

        return json([
            'code' => 1,
            'msg'  => '支付验证通过，已为您自动颁发官方商业使用授权！',
            'data' => [
                'order_no'  => $orderNo,
                'plugin_id' => $pluginId,
                'period'    => $period,
                'status'    => 'PAID',
            ]
        ]);
    }

    private function getBaseUrl(Request $request): string
    {
        $configured = (string)config('app.url', '');
        if (filter_var($configured, FILTER_VALIDATE_URL)) {
            return rtrim($configured, '/');
        }
        $forwarded = strtolower((string)$request->header('x-forwarded-proto'));
        $scheme = in_array($forwarded, ['http', 'https'], true)
            ? $forwarded
            : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
        return $scheme . '://' . $request->host();
    }

    /**
     * 从云端一键下载并安装插件。
     * 免费插件也必须从云端下载加密包并安裁，源码包中不内置任何驱动实现。
     */
    public function downloadFromCloud(Request $request): Response
    {
        $pluginId = trim((string)$request->post('plugin_id', ''));
        if ($pluginId === '') {
            return json(['code' => -1, 'msg' => '必须指定插件 ID']);
        }

        // 全部插件统一通过云端授权验证，免费插件同样需要下载安装
        // 不再本地硬编码判断免费插件的授权状态
        try {
            $targetVersion = trim((string)$request->post('version', ''));
            if ($targetVersion === '') {
                // 从云端诗取最新版本
                $catalogRes = $this->client->fetchCatalog();
                $pluginList  = $catalogRes['data']['plugins'] ?? [];
                foreach ($pluginList as $p) {
                    if (($p['plugin_id'] ?? '') === $pluginId) {
                        $targetVersion = (string)($p['latest_version'] ?? '1.0.0');
                        break;
                    }
                }
                if ($targetVersion === '') {
                    $targetVersion = '1.0.0';
                }
            }

            $result = $this->client->downloadAndInstallPlugin($pluginId, $targetVersion);

            PaymentManager::flush();

            $name = $result['name'] ?? $pluginId;
            return json([
                'code' => 1,
                'msg'  => "插件【{$name}】已从官方云端成功下载并完成数字验签，驱动已热加载就绪！",
                'data' => [
                    'plugin_id' => $pluginId,
                    'version'   => $result['version'] ?? $targetVersion,
                    'status'    => 'INSTALLED_AND_READY',
                ]
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => -1,
                'msg'  => "插件下载安装失败：" . $e->getMessage(),
            ]);
        }
    }

    /**
     * 兼容旧版云端购买跳转接口
     */
    public function buyFromCloud(Request $request): Response
    {
        $portalUrl = rtrim((string)config('cloud.portal_url', 'https://cloud.fcwan.cn'), '/') . '/plugins';
        return json([
            'code' => -1,
            'error_code' => 'CLOUD_PURCHASE_MOVED_TO_PORTAL',
            'msg' => '云端插件购买已迁移至云端独立控制台',
            'data' => [
                'action' => 'OPEN_PORTAL',
                'portal_url' => $portalUrl,
            ],
        ])->withStatus(409);
    }

    /**
     * 读取本地 Entitlements 授权字典（自动过滤已过期的按月订阅）
     */
    private function getEntitlements(): array
    {
        if (file_exists(self::$entitlementFile)) {
            $data = json_decode((string)file_get_contents(self::$entitlementFile), true);
            if (is_array($data)) {
                $now = time();
                $valid = [];
                foreach ($data as $k => $item) {
                    if (is_array($item)) {
                        $expiresAt = $item['expires_at'] ?? null;
                        if ($expiresAt !== null && strtotime((string)$expiresAt) < $now) {
                            continue; // 已过期
                        }
                        $valid[$k] = $item;
                    } else {
                        $valid[$k] = $item;
                    }
                }
                return $valid;
            }
        }
        return [];
    }

    /**
     * 写入本地 Entitlements 授权记录
     */
    private function grantEntitlementLocally(string $pluginId, string $period = 'forever'): void
    {
        $dir = dirname(self::$entitlementFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $entitlements = $this->getEntitlements();
        $isMonth = in_array(strtolower($period), ['month', 'monthly'], true);
        
        $expiresAt = null;
        if ($isMonth) {
            $curExpire = isset($entitlements[$pluginId]['expires_at']) ? strtotime((string)$entitlements[$pluginId]['expires_at']) : 0;
            $base = max(time(), $curExpire);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days', $base));
        }

        $entitlements[$pluginId] = [
            'plugin_id'  => $pluginId,
            'granted_at' => date('Y-m-d H:i:s'),
            'type'       => $isMonth ? 'MONTH' : 'PERMANENT',
            'expires_at' => $expiresAt,
        ];
        @file_put_contents(self::$entitlementFile, json_encode($entitlements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
