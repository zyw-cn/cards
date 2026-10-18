<?php

declare(strict_types=1);

/**
 * 外部服务配置
 *
 * 每个 key 为 service 名称，HttpClient::service('user') 时自动读取对应配置
 * - host:            基础地址（含协议，不带末尾斜杠）
 * - timeout:         请求超时(秒)
 * - connect_timeout: 连接超时(秒)
 * - retry_times:     重试次数（默认 0 不重试，仅对网络异常和 5xx 重试）
 * - retry_interval:  重试间隔(毫秒)，实际等待 = interval * 第N次，递增退避
 */
return [
    // 示例：用户服务
    // 'user' => [
    //     'host'            => env('USER_SERVICE_HOST', 'http://user-service.internal'),
    //     'timeout'         => 10,
    //     'connect_timeout' => 5,
    //     'retry_times'     => 2,
    //     'retry_interval'  => 200,
    // ],

    // 示例：订单服务
    // 'order' => [
    //     'host'            => env('ORDER_SERVICE_HOST', 'http://order-service.internal'),
    //     'timeout'         => 15,
    //     'connect_timeout' => 5,
    // ],

    // 示例：第三方支付
    // 'payment' => [
    //     'host'            => env('PAYMENT_HOST', 'https://api.payment.com'),
    //     'timeout'         => 30,
    //     'connect_timeout' => 10,
    // ],
];
