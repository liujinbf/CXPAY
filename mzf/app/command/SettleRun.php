<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\core\SettlementService;

/**
 * 结算对账命令（替代旧三套 crontab 的回调匹配）
 *
 * 用法：php think settle:run
 * 可由 Swoole 常驻 worker / 宝塔计划任务 周期调用。
 */
class SettleRun extends Command
{
    protected function configure(): void
    {
        $this->setName('settle:run')->setDescription('运行一轮结算对账（匹配到账账单→扣费→通知商户回调）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $stats = (new SettlementService())->run();
        $output->writeln(sprintf(
            '[settle] matched=%d paid=%d notified=%d @ %s',
            $stats['matched'], $stats['paid'], $stats['notified'], date('Y-m-d H:i:s')
        ));
        return 0;
    }
}
