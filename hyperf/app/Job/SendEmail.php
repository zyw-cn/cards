<?php

declare(strict_types=1);

namespace App\Job;

use Hyperf\AsyncQueue\Job;
use App\Facades\Log;
class SendEmail extends Job
{
    public $params;

    /**
     * 任务执行失败后的重试次数，即最大执行次数为 $maxAttempts+1 次
     */
    protected int $maxAttempts = 2;

    public function __construct($params)
    {
        $this->params = $params;
    }

    public function handle()
    {
        Log::info("job", $this->params);
        throw new \RuntimeException('模拟邮件发送失败，测试重试机制');
    }
}
