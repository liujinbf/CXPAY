<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Order;
use app\model\Callbill;
use app\model\Channel;
use app\payment\PaymentManager;
use app\payment\Contracts\PaymentEventReviewInterface;
use app\service\OrderService;
use support\Authcode;
use support\Response;
use Throwable;
use support\Log;

/**
 * 管理员人工补单与流水强插控制 API
 */
class CallbillAdminController
{
    protected OrderService $orderService;
    protected Authcode $authcode;

    public function __construct()
    {
        $this->orderService = new OrderService();
        $this->authcode = new Authcode();
    }

    /**
     * 管理员人工补单冲销订单 /api/admin/order/manual_pay
     */
    public function manualPay(\support\Request $request): Response
    {
        try {
            $tradeNo = (string)($request->post('trade_no') ?? '');
            $remark  = (string)($request->post('remark') ?? '管理员手工补单强插核销');

            if (empty($tradeNo)) {
                return json(['code' => -1, 'msg' => '订单号 (trade_no) 不能为空']);
            }

            $order = Order::where('trade_no', $tradeNo)->first();
            if (!$order) {
                return json(['code' => -1, 'msg' => '未找到对应的订单']);
            }

            if ((int)$order->status !== 0) {
                return json(['code' => -1, 'msg' => '仅待支付订单可以人工核销']);
            }

            $manualTradeNo = 'MANUAL_' . bin2hex(random_bytes(12));
            // 触发人工标记成功
            $success = $this->orderService->markAsPaid(
                (string)$order->trade_no,
                $manualTradeNo,
                (float)$order->price,
                (int)$order->channel_id,
                true
            );

            $settledOrder = $order->fresh();
            if ($success && $settledOrder && hash_equals($manualTradeNo, (string)$settledOrder->channel_trade_no)) {
                // 结算已成功时，流水审计写入失败不能把接口误报为“补单失败”。
                try {
                    Callbill::create([
                        'device_id'   => 'ADMIN',
                        'source_bill_id' => 'manual.' . $order->trade_no . '.' . bin2hex(random_bytes(8)),
                        'app_name'    => 'manual',
                        'channel_id'  => (int)$order->channel_id,
                        'order_id'    => (int)$order->id,
                        'trade_no'    => (string)$order->trade_no,
                        'money'       => (float)$order->price,
                        'remark'      => mb_substr($remark, 0, 255),
                        'occurred_at' => time(),
                        'raw_hash'    => hash('sha256', $order->trade_no . '|' . $remark),
                        'client_version' => 'admin',
                        'review_note' => $this->operator($request) . ' 手工补单',
                        'status'      => 1,
                        'create_time' => time(),
                    ]);
                } catch (Throwable $logError) {
                    Log::warning('管理员补单流水记录失败', [
                        'trade_no' => (string)$order->trade_no,
                        'error' => $logError->getMessage(),
                    ]);
                }

                return json(['code' => 1, 'msg' => '手工补单成功，已完成统一结算并将商户通知加入队列']);
            }

            return json(['code' => -1, 'msg' => '手工补单失败']);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    /** 待复核账单列表，并返回最多5个满足通道、金额和有效期条件的候选订单。 */
    public function reviewList(\support\Request $request): Response
    {
        $pageSize = max(1, min(100, (int)$request->get('page_size', 20)));
        $paginator = Callbill::whereIn('status', [2, 3, 4])
            ->orderByDesc('id')
            ->paginate($pageSize);

        $rows = [];
        foreach ($paginator->items() as $bill) {
            $candidates = Order::where('channel_id', (int)$bill->channel_id)
                ->where('status', 0)
                ->where('price', (string)$bill->money)
                ->where('create_time', '<=', (int)$bill->occurred_at + 10)
                ->where('expire_time', '>', time())
                ->orderByDesc('id')
                ->limit(5)
                ->get(['trade_no', 'out_trade_no', 'merchant_id', 'price', 'create_time', 'expire_time'])
                ->toArray();

            $rows[] = [
                'source' => 'local',
                'id' => (int)$bill->id,
                'channel_id' => (int)$bill->channel_id,
                'app_name' => (string)$bill->app_name,
                'device_id' => (string)$bill->device_id,
                'source_bill_id' => (string)$bill->source_bill_id,
                'money' => number_format((float)$bill->money, 2, '.', ''),
                'remark' => (string)$bill->remark,
                'occurred_at' => (int)$bill->occurred_at,
                'status' => (int)$bill->status,
                'review_note' => (string)($bill->review_note ?? ''),
                'candidates' => $candidates,
            ];
        }

        $cloudWarnings = [];
        $seenCloudEvents = [];
        foreach (Channel::orderBy('id')->get() as $channel) {
            try {
                $driver = PaymentManager::make((string)$channel->c_type);
                if (!$driver instanceof PaymentEventReviewInterface) {
                    continue;
                }
                $config = $this->channelConfig($channel);
                $result = $driver->reviewEvents($config);
                foreach ((array)($result['events'] ?? $result['data'] ?? []) as $event) {
                    if (!hash_equals((string)($config['account_id'] ?? ''), (string)($event['account_id'] ?? ''))) {
                        continue;
                    }
                    $eventKey = (string)($config['monitor_base_url'] ?? '') . '|'
                        . (string)($config['client_id'] ?? '') . '|'
                        . (string)($event['id'] ?? '');
                    if (isset($seenCloudEvents[$eventKey])) {
                        continue;
                    }
                    $seenCloudEvents[$eventKey] = true;
                    $candidates = array_map(static fn (array $order): array => [
                        'trade_no' => (string)($order['out_trade_no'] ?? ''),
                        'price' => (string)($order['amount'] ?? '0.00'),
                        'create_time' => (int)($order['created_at'] ?? 0),
                        'expire_time' => (int)($order['expires_at'] ?? 0),
                    ], (array)($event['candidates'] ?? []));
                    $meta = $driver->getMeta();
                    $rows[] = [
                        'source' => 'cloud',
                        'id' => (string)($event['payment_event_id'] ?? $event['id'] ?? ''),
                        'cloud_channel_id' => (int)$channel->id,
                        'channel_id' => (int)$channel->id,
                        'app_name' => (string)($meta['name'] ?? $channel->c_type),
                        'device_id' => (string)($event['account_id'] ?? ''),
                        'source_bill_id' => (string)($event['source_bill_id'] ?? ''),
                        'money' => (string)($event['amount'] ?? '0.00'),
                        'remark' => (string)($meta['title'] ?? $channel->c_type) . '异常账单',
                        'occurred_at' => (int)($event['occurred_at'] ?? 0),
                        'status' => (string)($event['status'] ?? 'UNMATCHED'),
                        'review_note' => '',
                        'candidates' => $candidates,
                    ];
                }
            } catch (Throwable $e) {
                $cloudWarnings[] = '通道 #' . (int)$channel->id . '：' . $e->getMessage();
                Log::warning('拉取云监控复核账单失败', ['channel_id' => (int)$channel->id, 'error' => $e->getMessage()]);
            }
        }

        return json([
            'code' => 1,
            'data' => [
                'data' => $rows,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'cloud_warnings' => $cloudWarnings,
            ],
        ]);
    }

    public function reviewMatch(\support\Request $request): Response
    {
        try {
            if ((string)$request->post('source', 'local') === 'cloud') {
                return $this->cloudReviewAction($request, 'match');
            }
            $result = $this->callbillService()->reviewMatch(
                (int)$request->post('bill_id', 0),
                trim((string)$request->post('trade_no', '')),
                $this->operator($request)
            );
            return json(['code' => $result['success'] ? 1 : -1, 'msg' => $result['msg']]);
        } catch (Throwable $e) {
            Log::error('人工账单匹配失败', ['error' => $e->getMessage()]);
            return json(['code' => -1, 'msg' => '人工账单匹配失败，请刷新后重试']);
        }
    }

    public function reviewIgnore(\support\Request $request): Response
    {
        try {
            if ((string)$request->post('source', 'local') === 'cloud') {
                return $this->cloudReviewAction($request, 'ignore');
            }
            $result = $this->callbillService()->ignoreReview(
                (int)$request->post('bill_id', 0),
                $this->operator($request),
                trim((string)$request->post('reason', ''))
            );
            return json(['code' => $result['success'] ? 1 : -1, 'msg' => $result['msg']]);
        } catch (Throwable $e) {
            Log::error('忽略复核账单失败', ['error' => $e->getMessage()]);
            return json(['code' => -1, 'msg' => '操作失败，请刷新后重试']);
        }
    }

    private function callbillService(): \app\service\CallbillService
    {
        return new \app\service\CallbillService();
    }

    private function cloudReviewAction(\support\Request $request, string $action): Response
    {
        $channel = Channel::where('id', (int)$request->post('channel_id', 0))
            ->first();
        $eventId = (int)$request->post('event_id', 0);
        if (!$channel || $eventId < 1) {
            return json(['code' => -1, 'msg' => '云监控通道或账单不存在']);
        }
        $driver = PaymentManager::make((string)$channel->c_type);
        if (!$driver instanceof PaymentEventReviewInterface) {
            return json(['code' => -1, 'msg' => '当前通道不支持云端账单复核']);
        }
        $operator = preg_replace('/[^\p{L}\p{N}_.:@-]/u', '_', $this->operator($request)) ?: 'admin';
        $note = trim((string)$request->post('reason', $request->post('note', '')));
        $result = $action === 'match'
            ? $driver->matchReviewEvent(
                $this->channelConfig($channel),
                $eventId,
                trim((string)$request->post('trade_no', '')),
                $operator,
                $note
            )
            : $driver->ignoreReviewEvent($this->channelConfig($channel), $eventId, $operator, $note);
        $accepted = ($result['accepted'] ?? false) === true
            || in_array((string)($result['status'] ?? ''), ['MATCHED', 'IGNORED'], true);
        return json([
            'code' => $accepted ? 1 : -1,
            'msg' => $action === 'match' ? '云端账单已匹配，回调已进入可靠队列' : '云端账单已忽略',
        ]);
    }

    /** @return array<string, mixed> */
    private function channelConfig(Channel $channel): array
    {
        $config = [];
        foreach (json_decode((string)$channel->config, true) ?: [] as $key => $value) {
            $config[$key] = is_string($value) ? $this->authcode->decryptStored($value) : $value;
        }
        return $config;
    }

    private function operator(\support\Request $request): string
    {
        $adminInfo = (array)$request->session()->get('admin_info', []);
        $username = trim((string)($adminInfo['username'] ?? 'admin'));
        return mb_substr($username !== '' ? $username : 'admin', 0, 64);
    }
}
