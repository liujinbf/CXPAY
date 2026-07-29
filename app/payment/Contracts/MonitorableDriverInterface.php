<?php

declare(strict_types=1);

namespace app\payment\Contracts;

/**
 * 支付通道监控能力契约。
 *
 * 个人收款码的到账确认方式并不相同，调用方应按能力调度，不能依赖 c_type 命名猜测。
 */
interface MonitorableDriverInterface
{
    /** 无需平台监控。 */
    public const MODE_NONE = 'none';

    /** 挂机助手主动上报账单和心跳。 */
    public const MODE_PUSH = 'push';

    /** 外部账单服务通过专用回调推送，不要求设备心跳。 */
    public const MODE_CALLBACK = 'callback';

    /** 平台定时任务主动查询收款端账单。 */
    public const MODE_SERVER = 'server';

    public function monitorMode(): string;
}
