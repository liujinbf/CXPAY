<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Order;
use app\service\OrderService;

/**
 * 沙箱支付测试控制器
 *
 * 提供两个端点：
 *   GET  /api/sandbox/pay        — 沙箱支付落地页（渲染模拟支付表单）
 *   POST /api/sandbox/complete   — 手动核销沙箱订单（模拟异步回调触发支付成功）
 *
 * 安全约束：
 *   - 调用 /complete 时需携带与通道配置一致的 sandbox_secret，防止非授权核销
 *   - 只有 c_type=sandbox_test 的订单才可被此端点核销
 */
class SandboxController
{
    /**
     * 沙箱支付落地页
     *
     * GET /api/sandbox/pay?trade_no=xxx&amount=xx&subject=xxx
     *
     * 返回一个简单 HTML 页面，用户点击"确认支付"后自动向 /api/sandbox/complete 提交。
     * 前端可替换此模板为更美观的 Vue/React 页面。
     */
    public function payPage(\support\Request $request): \Webman\Http\Response
    {
        $tradeNo = htmlspecialchars(trim((string)$request->get('trade_no', '')), ENT_QUOTES, 'UTF-8');
        $amount  = htmlspecialchars(trim((string)$request->get('amount', '0.00')), ENT_QUOTES, 'UTF-8');
        $subject = htmlspecialchars(trim((string)$request->get('subject', '测试订单')), ENT_QUOTES, 'UTF-8');

        if ($tradeNo === '') {
            return response('<h3>参数错误：缺少 trade_no</h3>', 400, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        // 查询订单是否存在
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return response('<h3>订单不存在</h3>', 404, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if ((int)$order->status !== 0) {
            $statusText = (int)$order->status === 1 ? '已支付' : '已关闭';
            return response(
                "<h3>该订单状态为【{$statusText}】，无需重复支付</h3>",
                400,
                ['Content-Type' => 'text/html; charset=utf-8']
            );
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>CXPAY 沙箱测试支付</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #f0f4f8; display: flex; justify-content: center; align-items: center; min-height: 100vh; font-family: -apple-system, sans-serif; }
  .card { background: #fff; border-radius: 16px; padding: 40px; max-width: 420px; width: 100%; box-shadow: 0 8px 32px rgba(0,0,0,.12); text-align: center; }
  .badge { display: inline-block; background: #f59e0b; color: #fff; font-size: 12px; padding: 3px 10px; border-radius: 99px; margin-bottom: 20px; letter-spacing: 1px; }
  h2 { font-size: 22px; color: #1e293b; margin-bottom: 8px; }
  .amount { font-size: 48px; font-weight: 700; color: #10b981; margin: 20px 0; }
  .amount span { font-size: 20px; }
  .info { color: #64748b; font-size: 14px; margin-bottom: 28px; }
  .trade-no { background: #f1f5f9; border-radius: 8px; padding: 8px 16px; font-size: 12px; color: #94a3b8; margin-bottom: 28px; word-break: break-all; }
  button { width: 100%; padding: 14px; background: #10b981; border: none; border-radius: 10px; color: #fff; font-size: 17px; font-weight: 600; cursor: pointer; transition: background .2s; }
  button:hover { background: #059669; }
  button:disabled { background: #94a3b8; cursor: not-allowed; }
  .tip { margin-top: 16px; font-size: 12px; color: #94a3b8; }
  input[name="sandbox_secret"] { width: 100%; padding: 10px 14px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 14px; margin-bottom: 16px; outline: none; }
  input[name="sandbox_secret"]:focus { border-color: #10b981; }
</style>
</head>
<body>
<div class="card">
  <div class="badge">⚡ 沙箱测试环境</div>
  <h2>{$subject}</h2>
  <div class="amount"><span>¥</span>{$amount}</div>
  <div class="info">订单号</div>
  <div class="trade-no">{$tradeNo}</div>
  <form id="payForm" method="POST" action="/api/sandbox/complete">
    <input type="hidden" name="trade_no" value="{$tradeNo}">
    <input type="password" name="sandbox_secret" placeholder="输入沙箱触发密钥" required autocomplete="off">
    <button type="submit" id="payBtn">✅ 确认支付（模拟）</button>
  </form>
  <p class="tip">沙箱环境 · 不产生任何真实资金流向</p>
</div>
<script>
  document.getElementById('payForm').addEventListener('submit', function() {
    document.getElementById('payBtn').disabled = true;
    document.getElementById('payBtn').textContent = '处理中...';
  });
</script>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * 手动核销沙箱订单（模拟支付成功）
     *
     * POST /api/sandbox/complete
     * Body: trade_no=xxx&sandbox_secret=xxx
     *
     * 成功核销后触发与真实支付相同的 markAsPaid 流程（商户余额扣费、异步通知等）。
     */
    public function complete(\support\Request $request): string
    {
        $tradeNo = trim((string)$request->post('trade_no', ''));
        $secret  = (string)$request->post('sandbox_secret', '');

        if ($tradeNo === '' || $secret === '') {
            return json_encode(['code' => -1, 'msg' => 'trade_no 和 sandbox_secret 不能为空'], JSON_UNESCAPED_UNICODE);
        }

        // 查询订单及关联通道
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return json_encode(['code' => -1, 'msg' => '订单不存在'], JSON_UNESCAPED_UNICODE);
        }
        if ((int)$order->status !== 0) {
            return json_encode(['code' => -1, 'msg' => '订单状态不允许此操作'], JSON_UNESCAPED_UNICODE);
        }

        // 验证通道类型必须是 sandbox_test
        $channel = \app\model\Channel::find((int)$order->channel_id);
        if (!$channel || (string)$channel->c_type !== 'sandbox_test') {
            return json_encode(['code' => -1, 'msg' => '该订单不是沙箱订单，禁止通过此端点核销'], JSON_UNESCAPED_UNICODE);
        }

        // 从通道配置中读取 sandbox_secret 并校验
        $config       = json_decode((string)($channel->config ?? ''), true) ?: [];
        $storedSecret = trim((string)($config['sandbox_secret'] ?? ''));
        if ($storedSecret === '' || !hash_equals($storedSecret, $secret)) {
            return json_encode(['code' => -1, 'msg' => '沙箱触发密钥错误'], JSON_UNESCAPED_UNICODE);
        }

        // 调用与真实支付完全相同的核销流程
        try {
            $orderService = new OrderService();
            $result = $orderService->markAsPaid(
                $tradeNo,
                (float)$order->amount, // 沙箱：实付金额 = 下单金额
                $tradeNo . '_sandbox', // 伪造一个上游流水号
                []                     // 空 rawParams
            );

            if ($result) {
                return json_encode(['code' => 1, 'msg' => '沙箱订单核销成功，异步通知已触发'], JSON_UNESCAPED_UNICODE);
            }
            return json_encode(['code' => -1, 'msg' => '核销失败，订单可能已被其他进程处理'], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['code' => -1, 'msg' => '核销异常：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}
