<?php

declare(strict_types=1);

namespace AlipayAutoConfig;

/**
 * 自动配置核心控制器。
 *
 * 实现 CXPAY 插件调用的两个 API 端点：
 *   POST /v1/autoconfig-sessions   — 创建配置会话
 *   GET  /v1/autoconfig-sessions/{id} — 查询会话状态
 *
 * 以及商户引导页面：
 *   GET  /guide?session={id}        — 引导页面（收集 AppID + 支付宝公钥）
 *   POST /guide/verify              — 验证商户提交的凭证
 */
final class AutoConfigController
{
    public function __construct(
        private readonly SessionStore     $sessions,
        private readonly SignatureHelper  $signer,
        private readonly AlipayApiClient  $alipay,
        private readonly string           $baseUrl
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // CXPAY API 端点
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /v1/autoconfig-sessions
     *
     * 请求体 JSON：
     * {
     *   "reference"  : "cxpay-channel-123",   // CXPAY 通道标识
     *   "public_key" : "BASE64...",            // 插件本地生成的 RSA2 公钥（去头尾行）
     *   "pay_type"   : "alipay"
     * }
     *
     * 成功响应：
     * {
     *   "session_id"  : "abc...",
     *   "guide_url"   : "https://proxy.example.com/guide?session=abc...",
     *   "expires_at"  : 1234567890,
     *   "status"      : "PENDING"
     * }
     */
    public function createSession(string $rawBody): void
    {
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $this->signer->sendJson(['error' => '请求体必须是合法 JSON'], 400);
        }

        $reference = trim((string)($data['reference'] ?? ''));
        $publicKey = trim((string)($data['public_key'] ?? ''));
        $payType   = trim((string)($data['pay_type'] ?? ''));

        if ($reference === '' || $publicKey === '' || $payType !== 'alipay') {
            $this->signer->sendJson(['error' => '缺少必要参数或 pay_type 不受支持'], 400);
        }

        // 验证公钥格式
        if (base64_decode(preg_replace('/\s+/', '', $publicKey) ?? '', true) === false) {
            $this->signer->sendJson(['error' => 'public_key 格式不合法'], 400);
        }

        $sessionId = $this->sessions->create([
            'reference'  => $reference,
            'public_key' => $publicKey,
            'pay_type'   => 'alipay',
        ]);

        $expiresAt = time() + 1200;
        $guideUrl  = $this->baseUrl . '/guide?session=' . $sessionId;

        $this->signer->sendJson([
            'session_id' => $sessionId,
            'guide_url'  => $guideUrl,
            'expires_at' => $expiresAt,
            'status'     => 'PENDING',
            'message'    => '请使用手机或电脑打开引导链接，按步骤完成支付宝开放平台应用配置',
        ]);
    }

    /**
     * GET /v1/autoconfig-sessions/{id}
     *
     * 查询配置会话状态。
     * 可能的 status 值：PENDING | CONFIRMED | FAILED | EXPIRED
     *
     * CONFIRMED 时的额外字段：
     * {
     *   "status"     : "CONFIRMED",
     *   "app_id"     : "2021...",
     *   "alipay_public_key": "MIIBIjANBgkqh...",
     *   "message"    : "配置成功"
     * }
     */
    public function pollSession(string $sessionId): void
    {
        $session = $this->sessions->get($sessionId);
        if ($session === null) {
            $this->signer->sendJson(['status' => 'EXPIRED', 'message' => '会话不存在或已过期'], 404);
        }

        $status = (string)($session['_status'] ?? 'PENDING');

        $response = ['status' => $status];

        match ($status) {
            'PENDING'   => $response['message'] = '等待商户在引导页面完成配置',
            'CONFIRMED' => $response = array_merge($response, [
                'message'           => (string)($session['_confirmed']['message'] ?? '配置成功'),
                'app_id'            => (string)($session['_confirmed']['app_id'] ?? ''),
                'alipay_public_key' => (string)($session['_confirmed']['alipay_public_key'] ?? ''),
            ]),
            'FAILED' => $response['message'] = (string)($session['_fail_message'] ?? '配置失败'),
            default  => $response['message'] = '未知状态',
        };

        $this->signer->sendJson($response);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 商户引导页面
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /guide?session={id}
     * 渲染商户操作引导页面（无需 CXPAY 签名，面向人工操作）。
     */
    public function showGuidePage(string $sessionId): void
    {
        $session = $this->sessions->get($sessionId);
        $expired = ($session === null);
        $status  = $expired ? 'EXPIRED' : (string)($session['_status'] ?? 'PENDING');

        $publicKeyDisplay = '';
        if (!$expired && isset($session['public_key'])) {
            // 格式化为 PEM 公钥格式，方便商户粘贴到支付宝开放平台
            $raw = preg_replace('/\s+/', '', (string)$session['public_key']) ?? '';
            $publicKeyDisplay = chunk_split($raw, 64, "\n");
        }

        $verifyUrl = $this->baseUrl . '/guide/verify';
        $this->renderGuidePage($sessionId, $status, $publicKeyDisplay, $verifyUrl);
    }

    /**
     * POST /guide/verify
     * 接收商户提交的 AppID + 支付宝公钥，验证后标记会话为 CONFIRMED 或 FAILED。
     */
    public function verifyCredentials(): void
    {
        $sessionId      = trim((string)($_POST['session_id']      ?? ''));
        $appId          = trim((string)($_POST['app_id']          ?? ''));
        $alipayPubKey   = trim((string)($_POST['alipay_public_key'] ?? ''));

        if ($sessionId === '' || $appId === '' || $alipayPubKey === '') {
            http_response_code(400);
            $this->renderVerifyResult(false, '缺少必要参数', $sessionId);
            return;
        }

        $session = $this->sessions->get($sessionId);
        if ($session === null) {
            http_response_code(404);
            $this->renderVerifyResult(false, '会话不存在或已过期', $sessionId);
            return;
        }

        if ((string)($session['_status'] ?? '') === 'CONFIRMED') {
            $this->renderVerifyResult(true, '该会话已配置成功，CXPAY 将自动写入配置', $sessionId);
            return;
        }

        // 从会话中取出 CXPAY 端生成的 RSA2 私钥对应的公钥（用于后续在开放平台配置）
        // 注意：私钥由 CXPAY 插件本地生成并保存，代理服务只保存公钥
        $appPrivateKey = trim((string)($session['_app_private_key_temp'] ?? ''));

        // 调用支付宝接口验证凭证组合
        $result = $this->alipay->verifyAppCredentials($appId, $appPrivateKey, $alipayPubKey);

        if ($result['ok']) {
            $this->sessions->confirm($sessionId, [
                'app_id'            => $appId,
                'alipay_public_key' => $alipayPubKey,
                'message'           => $result['message'],
            ]);
            $this->renderVerifyResult(true, $result['message'], $sessionId);
        } else {
            $this->sessions->fail($sessionId, $result['message']);
            $this->renderVerifyResult(false, $result['message'], $sessionId);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 页面渲染
    // ─────────────────────────────────────────────────────────────────────────

    private function renderGuidePage(
        string $sessionId,
        string $status,
        string $publicKeyDisplay,
        string $verifyUrl
    ): never {
        $publicKeyHtml = htmlspecialchars($publicKeyDisplay);
        $sessionIdHtml = htmlspecialchars($sessionId);
        $verifyUrlHtml = htmlspecialchars($verifyUrl);

        $statusBanner = match ($status) {
            'CONFIRMED' => '<div class="banner success">✅ 配置已成功！CXPAY 将在下次轮询时自动写入通道配置。</div>',
            'FAILED'    => '<div class="banner error">❌ 配置失败，请重新填写信息并提交。</div>',
            'EXPIRED'   => '<div class="banner error">⚠️ 会话已过期，请在 CXPAY 后台重新发起配置。</div>',
            default     => '',
        };

        $formDisabled = in_array($status, ['CONFIRMED', 'EXPIRED'], true) ? 'disabled' : '';

        echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>支付宝账单通道自动配置引导</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Microsoft YaHei', sans-serif;
         background: #f0f2f5; color: #333; min-height: 100vh; padding: 20px; }
  .container { max-width: 760px; margin: 0 auto; }
  h1 { font-size: 22px; color: #1677ff; margin-bottom: 20px; }
  .card { background: #fff; border-radius: 10px; padding: 24px; margin-bottom: 16px;
          box-shadow: 0 2px 8px rgba(0,0,0,.08); }
  .step-num { display: inline-flex; align-items: center; justify-content: center;
              width: 28px; height: 28px; border-radius: 50%; background: #1677ff;
              color: #fff; font-weight: bold; font-size: 13px; margin-right: 8px; }
  .step-title { font-size: 16px; font-weight: 600; color: #1a1a1a; margin-bottom: 12px;
                display: flex; align-items: center; }
  .step-desc { font-size: 14px; color: #555; line-height: 1.7; }
  .code-block { background: #f6f8fa; border: 1px solid #e0e0e0; border-radius: 6px;
                padding: 14px; font-family: 'Courier New', monospace; font-size: 13px;
                word-break: break-all; white-space: pre-wrap; margin: 10px 0;
                user-select: all; cursor: text; }
  .copy-btn { display: inline-block; margin-top: 8px; padding: 6px 14px; background: #f0f0f0;
              border: none; border-radius: 5px; cursor: pointer; font-size: 13px; }
  .copy-btn:hover { background: #e0e0e0; }
  label { display: block; font-size: 14px; color: #444; margin-bottom: 6px; font-weight: 500; }
  input[type=text], textarea { width: 100%; padding: 10px 12px; border: 1px solid #d9d9d9;
    border-radius: 6px; font-size: 14px; outline: none; transition: border .2s; }
  input[type=text]:focus, textarea:focus { border-color: #1677ff; }
  textarea { height: 120px; font-family: 'Courier New', monospace; resize: vertical; }
  .submit-btn { width: 100%; padding: 12px; background: #1677ff; color: #fff;
                border: none; border-radius: 8px; font-size: 16px; font-weight: 600;
                cursor: pointer; transition: background .2s; margin-top: 10px; }
  .submit-btn:hover:not([disabled]) { background: #0958d9; }
  .submit-btn[disabled] { background: #ccc; cursor: not-allowed; }
  .banner { padding: 14px 18px; border-radius: 8px; font-size: 15px; margin-bottom: 16px; }
  .banner.success { background: #f6ffed; border: 1px solid #b7eb8f; color: #389e0d; }
  .banner.error   { background: #fff2f0; border: 1px solid #ffa39e; color: #cf1322; }
  .link { color: #1677ff; text-decoration: none; }
  .link:hover { text-decoration: underline; }
  .note { font-size: 13px; color: #888; margin-top: 8px; }
</style>
</head>
<body>
<div class="container">
  <h1>🔧 支付宝账单通道自动配置</h1>
  {$statusBanner}

  <!-- 步骤一：登录支付宝开放平台 -->
  <div class="card">
    <div class="step-title"><span class="step-num">1</span>登录支付宝开放平台</div>
    <div class="step-desc">
      访问 <a class="link" href="https://open.alipay.com" target="_blank">open.alipay.com</a>，
      使用支付宝 App 扫码登录。如果尚未创建应用，请点击「创建应用」→「网页&移动应用」→「自定义接入」。
      <br><br>
      <strong>必须开通的接口权限：</strong>「支付宝账户流水查询」（alipay.data.bill.accountlog.query）
      <br>
      <span class="note">提示：个人开发者可免费申请，审核通常在 1 个工作日内完成。</span>
    </div>
  </div>

  <!-- 步骤二：配置 RSA2 公钥 -->
  <div class="card">
    <div class="step-title"><span class="step-num">2</span>在应用中配置 RSA2 公钥</div>
    <div class="step-desc">
      在开放平台应用的「开发设置」→「接口加签方式」中，选择「公钥」，
      将以下公钥内容粘贴进去并保存。
      <div class="code-block" id="pubkey">-----BEGIN PUBLIC KEY-----
{$publicKeyHtml}-----END PUBLIC KEY-----</div>
      <button class="copy-btn" onclick="copyText('pubkey')">📋 复制公钥</button>
      <br>
      <span class="note">保存后，支付宝将显示一个「支付宝公钥」，请在下方步骤三中填写。</span>
    </div>
  </div>

  <!-- 步骤三：填写信息并验证 -->
  <div class="card">
    <div class="step-title"><span class="step-num">3</span>填写 App ID 与支付宝公钥</div>
    <form method="POST" action="{$verifyUrlHtml}">
      <input type="hidden" name="session_id" value="{$sessionIdHtml}">

      <div style="margin-bottom:16px;">
        <label for="app_id">应用 App ID（开放平台应用概览页面可以找到）</label>
        <input type="text" id="app_id" name="app_id" placeholder="例如：2021003197123456" {$formDisabled} required>
      </div>

      <div style="margin-bottom:16px;">
        <label for="alipay_public_key">支付宝公钥（配置 RSA2 公钥后，开放平台显示的那个公钥）</label>
        <textarea id="alipay_public_key" name="alipay_public_key"
          placeholder="粘贴支付宝公钥（不含 -----BEGIN PUBLIC KEY----- 头尾行）" {$formDisabled} required></textarea>
        <span class="note">注意：这里填写的是「支付宝公钥」，不是您自己的公钥。</span>
      </div>

      <button type="submit" class="submit-btn" {$formDisabled}>✅ 验证并完成配置</button>
    </form>
  </div>
</div>

<script>
function copyText(id) {
  var el = document.getElementById(id);
  navigator.clipboard ? navigator.clipboard.writeText(el.innerText)
    : (function(){ var r = document.createRange(); r.selectNode(el);
       window.getSelection().removeAllRanges(); window.getSelection().addRange(r);
       document.execCommand('copy'); window.getSelection().removeAllRanges(); })();
  alert('公钥已复制到剪贴板');
}
</script>
</body>
</html>
HTML;
        exit;
    }

    private function renderVerifyResult(bool $success, string $message, string $sessionId): never
    {
        $icon       = $success ? '✅' : '❌';
        $colorClass = $success ? 'success' : 'error';
        $msgHtml    = htmlspecialchars($message);
        $backUrl    = htmlspecialchars($this->baseUrl . '/guide?session=' . $sessionId);

        echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>配置验证结果</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'PingFang SC', sans-serif;
         background: #f0f2f5; display: flex; align-items: center; justify-content: center;
         min-height: 100vh; margin: 0; }
  .card { background: #fff; border-radius: 12px; padding: 40px 32px; text-align: center;
          box-shadow: 0 4px 16px rgba(0,0,0,.1); max-width: 440px; width: 100%; }
  .icon { font-size: 56px; margin-bottom: 16px; }
  .title { font-size: 20px; font-weight: 700; margin-bottom: 12px; }
  .title.success { color: #389e0d; }
  .title.error   { color: #cf1322; }
  .msg { color: #555; font-size: 15px; line-height: 1.6; margin-bottom: 24px; }
  .btn { display: inline-block; padding: 10px 28px; background: #1677ff; color: #fff;
         border-radius: 8px; text-decoration: none; font-size: 15px; }
</style>
</head>
<body>
<div class="card">
  <div class="icon">{$icon}</div>
  <div class="title {$colorClass}">{$msgHtml}</div>
  <div class="msg">
HTML;
        if ($success) {
            echo "CXPAY 将在下次轮询时（约 5 秒内）自动写入通道配置，您可以关闭此页面。";
        } else {
            echo "配置验证失败，请返回检查填写的信息。";
            echo "</div><a class=\"btn\" href=\"{$backUrl}\">返回重新填写</a>";
        }
        echo "</div></div></body></html>";
        exit;
    }
}
