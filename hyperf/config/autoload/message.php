<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    // 邮箱验证码有效期（秒），最长 15 分钟
    'code_ttl' => min((int) env('MESSAGE_CODE_TTL', 900), 900),
    // 同一邮箱发送间隔（秒）
    'send_interval' => (int) env('MESSAGE_SEND_INTERVAL', 60),
    // 允许的业务标识（对应 h_template.mark）
    'allowed_marks' => ['REGISTER', 'FORGET'],
    // 邮件模板占位符 [!company]
    'company_name' => env('MAIL_COMPANY', env('COMPANY_NAME', env('MAIL_FROM_NAME', 'Hyperf'))),
];
