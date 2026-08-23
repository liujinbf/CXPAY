<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\service\CloudInstanceClient;
use support\Request;
use support\Response;
use Throwable;

/**
 * 主站 OEM 代理商加盟工作台 — 子站点授权一键下发与客户管理控制器
 */
final class AgentHubController
{
    private CloudInstanceClient $client;

    public function __construct(?CloudInstanceClient $client = null)
    {
        $this->client = $client ?? new CloudInstanceClient();
    }

    private function callCloudAgentApi(string $path, array $data = []): array
    {
        $identity = $this->client->getIdentity();
        $agentKey = $identity['public_key'] ?? '';
        $agentDomain = $identity['domain'] ?? 'cs.fcwan.cn';

        $payload = array_merge([
            'agent_key'    => $agentKey,
            'agent_domain' => $agentDomain,
        ], $data);

        $primaryUrl = rtrim((string)config('cloud.api_url', config('cloud.server_url', 'https://cloud.fcwan.cn')), '/');
        $urls = [
            $primaryUrl . $path,
            'http://127.0.0.1:8890' . $path,
            'https://cloud.fcwan.cn' . $path,
        ];

        $lastErr = '';
        $lastHttpCode = 0;

        foreach ($urls as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Agent-Key: ' . $agentKey,
                    'X-Agent-Domain: ' . $agentDomain,
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err === '' && $httpCode === 200 && $response) {
                $res = json_decode((string)$response, true);
                if (is_array($res)) {
                    return $res;
                }
            }

            $lastErr = $err ?: (string)$response;
            $lastHttpCode = $httpCode;
        }

        throw new \RuntimeException("连接官方云端授权中心失败 (HTTP {$lastHttpCode}): " . ($lastErr ?: '网络超时或服务不可达'));
    }

    /**
     * 获取当前代理商资质与配额统计
     */
    public function profile(): Response
    {
        try {
            $res = $this->callCloudAgentApi('/api/agent/v1/profile');
            return json($res);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 一键为下级客户域名下发主站商业授权
     */
    public function issueLicense(Request $request): Response
    {
        $clientDomain = trim((string)$request->post('client_domain', ''));
        $clientName = trim((string)$request->post('client_name', ''));

        if ($clientDomain === '') {
            return json(['code' => -1, 'msg' => '请输入下级客户待绑定的合法主站域名']);
        }

        try {
            $res = $this->callCloudAgentApi('/api/agent/v1/license/issue', [
                'client_domain' => $clientDomain,
                'client_name'   => $clientName,
            ]);
            return json($res);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '下发授权失败：' . $e->getMessage()]);
        }
    }

    /**
     * 查询名下已下发的客户子站点列表
     */
    public function listSubInstances(): Response
    {
        try {
            $res = $this->callCloudAgentApi('/api/agent/v1/sub-instances');
            return json($res);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 注销/冻结子站点授权
     */
    public function revokeLicense(Request $request): Response
    {
        $domain = trim((string)$request->post('domain', ''));
        if ($domain === '') {
            return json(['code' => -1, 'msg' => '缺少待注销域名']);
        }

        try {
            $res = $this->callCloudAgentApi('/api/agent/v1/license/revoke', ['domain' => $domain]);
            return json($res);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 解冻/恢复子站点授权
     */
    public function restoreLicense(Request $request): Response
    {
        $domain = trim((string)$request->post('domain', ''));
        if ($domain === '') {
            return json(['code' => -1, 'msg' => '缺少待恢复域名']);
        }

        try {
            $res = $this->callCloudAgentApi('/api/agent/v1/license/restore', ['domain' => $domain]);
            return json($res);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '恢复授权失败：' . $e->getMessage()]);
        }
    }

    /**
     * 删除子站点授权（彻底吊销并封杀，不退还已消耗配额）
     */
    public function deleteLicense(Request $request): Response
    {
        $domain = trim((string)$request->post('domain', ''));
        if ($domain === '') {
            return json(['code' => -1, 'msg' => '缺少待删除域名']);
        }

        try {
            $res = $this->callCloudAgentApi('/api/agent/v1/license/delete', ['domain' => $domain]);
            return json($res);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '删除授权失败：' . $e->getMessage()]);
        }
    }

    /**
     * 为已授权客户站点更换绑定域名（旧域名作废，新域名接管，不消耗额外配额）
     */
    public function rebindLicense(Request $request): Response
    {
        $oldDomain = trim((string)$request->post('old_domain', ''));
        $newDomain = trim((string)$request->post('new_domain', ''));

        if ($oldDomain === '' || $newDomain === '') {
            return json(['code' => -1, 'msg' => '原域名与新域名均不能为空']);
        }

        try {
            $res = $this->callCloudAgentApi('/api/agent/v1/license/rebind', [
                'old_domain' => $oldDomain,
                'new_domain' => $newDomain,
            ]);
            return json($res);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '更换域名失败：' . $e->getMessage()]);
        }
    }


    /**
     * 代理商购买额外站点配额
     * 向云端授权中心提交购买请求，返回支付链接或支付二维码
     */
    public function buyQuota(Request $request): Response
    {
        $quantity = (int)$request->post('quantity', 0);
        if ($quantity <= 0) {
            return json(['code' => -1, 'msg' => '请选择有效的购买数量']);
        }

        // 回调通知地址（云端支付成功后通知本站更新配额）
        $notifyUrl = rtrim((string)config('app.url', ''), '/') . '/api/agent/quota/notify';

        try {
            $res = $this->callCloudAgentApi('/api/agent/v1/quota/buy', [
                'quantity'   => $quantity,
                'notify_url' => $notifyUrl,
            ]);
            return json($res);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '发起购买失败：' . $e->getMessage()]);
        }
    }
}

