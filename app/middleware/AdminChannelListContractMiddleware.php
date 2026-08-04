<?php

declare(strict_types=1);

namespace app\middleware;

use app\model\Channel;
use app\service\AdminChannelPresenter;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * Replaces the legacy synthetic channel fallback with persisted channel rows.
 *
 * The downstream handler executes first, so the existing administrator
 * authentication and authorization middleware remains authoritative.
 */
final class AdminChannelListContractMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $response = $handler($request);

        if (ltrim($request->path(), '/') !== 'api/admin/channel/list') {
            return $response;
        }

        $payload = json_decode($response->rawBody(), true);
        if (!is_array($payload) || (int)($payload['code'] ?? 0) !== 1) {
            return $response;
        }

        $data = $payload['data'] ?? null;
        if (is_array($data) && $data !== [] && $this->containsPersistedChannelShape($data)) {
            return $response;
        }

        try {
            $channels = Channel::where('merchant_id', 0)
                ->select([
                    'id', 'pay_category', 'title', 'c_type', 'remark', 'weight',
                    'single_min', 'single_max', 'day_max', 'online_status',
                    'last_heartbeat_time', 'status',
                ])
                ->orderByDesc('id')
                ->get()
                ->toArray();
        } catch (\Throwable) {
            return $response
                ->withStatus(503)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withBody((string)json_encode([
                    'code' => 503,
                    'msg' => '通道数据暂时不可用，请稍后重试',
                    'data' => [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody((string)json_encode([
                'code' => 1,
                'data' => AdminChannelPresenter::format($channels),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<int, mixed> $channels
     */
    private function containsPersistedChannelShape(array $channels): bool
    {
        foreach ($channels as $channel) {
            if (!is_array($channel)
                || !array_key_exists('c_type', $channel)
                || !array_key_exists('online_status', $channel)) {
                return false;
            }
        }

        return true;
    }
}
