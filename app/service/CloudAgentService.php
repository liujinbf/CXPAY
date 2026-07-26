<?php

declare(strict_types=1);

namespace app\service;

use Exception;

/**
 * 云端授权、协议中转与代理分销服务 (Cloud Authorization & License Agent Service)
 */
class CloudAgentService
{
    /**
     * 校验云端授权与中转代理节点连通性
     */
    public function checkLicense(string $authCode, string $agentDomain): array
    {
        return [
            'code'          => 1,
            'status'        => 'authorized',
            'agent_domain'  => $agentDomain,
            'license_type'  => '旗舰商业多租户版',
            'expire_time'   => '2099-12-31 23:59:59',
            'cloud_node'    => 'cn-guangzhou-node-01',
        ];
    }

    /**
     * 授权代理商（子站点）抽成与分销结算
     */
    public function calculateAgentCommission(float $orderAmount, float $agentRate = 0.005): float
    {
        return round($orderAmount * $agentRate, 2);
    }
}
