<?php

use Hyperf\Context\ApplicationContext;

if (!function_exists('app')) {
    function app(string $service)
    {
        static $aliases = [
            "Json"=>\App\Utils\Json::class,
            'Request'       => \Hyperf\HttpServer\Contract\RequestInterface::class,
            'Response'      => \Hyperf\HttpServer\Contract\ResponseInterface::class,
            'Cache'         => \Hyperf\Cache\CacheManager::class,
            'Redis'         => \Hyperf\Redis\Redis::class,
            'Logger'        => \Hyperf\Logger\LoggerFactory::class,
            'Event'         => \Psr\EventDispatcher\EventDispatcherInterface::class,
            'Config'        => \Hyperf\Contract\ConfigInterface::class,
            'DB'            => \Hyperf\DbConnection\Db::class,
            'Snowflake'     => \Hyperf\Snowflake\IdGeneratorInterface::class,
            'AsyncQueue'    => \Hyperf\AsyncQueue\Driver\DriverFactory::class,
            'Guzzle'        => \Hyperf\Guzzle\ClientFactory::class,
            'Elasticsearch' => \Hyperf\Elasticsearch\ClientBuilderFactory::class,
        ];

        $service = $aliases[$service] ?? $service;

        $container = ApplicationContext::getContainer();
        if (!$container->has($service)) {
            throw new \InvalidArgumentException("Service not found: {$service}");
        }
        return $container->get($service);
    }
}

if (! function_exists('get_client_ip')) {
    /**
     * 获取客户端 IP（优先代理头 x-real-ip / x-forwarded-for）.
     */
    function get_client_ip(): string
    {
        $request = app('Request');

        foreach (['x-real-ip', 'x-forwarded-for'] as $header) {
            $value = $request->header($header, '');
            if ($value !== '') {
                return trim(explode(',', $value)[0]);
            }
        }

        return (string) $request->server('remote_addr', '');
    }
}


if (!function_exists('build_order_sn')) {
    function build_order_sn($businessCode):string
    {
        // 1. 业务编号（固定长度，便于识别）
        $code = strtoupper($businessCode);

        // 2. 日期部分：年月日时分秒
        $date = date('YmdHis');

        // 3. 随机数（6位，防止并发冲突）
        $random = mt_rand(100000, 999999);

        // 4. 微秒（增加唯一性）
        $micro = substr(microtime(), 2, 4);
        return $code . $date . $micro . $random;
    }
}