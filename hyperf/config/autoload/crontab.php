<?php

declare(strict_types=1);

/**
 * 定时任务总开关。具体任务见 App\Crontab\* 上的 #[Crontab] 注解。
 * 需配合 processes.php 中的 CrontabDispatcherProcess。
 */
return [
    'enable' => true,
];
