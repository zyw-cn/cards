<?php

// app/Utils/JsonResponse.php
declare(strict_types=1);

namespace App\Utils;

use App\Utils\TraceId;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * 统一 JSON 返回类（自动携带 log_id）
 */

class Json
{
    /**
     * 成功返回
     * @param array $data 业务数据
     * @param string $msg 提示信息
     * @param int $code 业务状态码（默认 0）
     * @return ResponseInterface
     */
    public function success(string $msg = 'ok',int $code = 0): PsrResponseInterface
    {
        return $this->format($code, $msg);
    }

    /**
     * 失败返回
     * @param string $msg 错误信息
     * @param array $data 附加数据（可选）
     * @param int $code 业务状态码（默认 400）
     * @return ResponseInterface
     */
    public function fail(string $msg = 'fail', int $code = 1): PsrResponseInterface
    {
        return $this->format($code, $msg);
    }

    /**
     * 格式化返回（核心）
     * @param int $code 业务状态码
     * @param string $msg 提示信息
     * @param array $data 业务数据
     * @return ResponseInterface
     */
    private function format(int $code, string $msg, array $data=[],$isList=false): PsrResponseInterface
    {
        // 1. 组装返回数组（自动包含 log_id）
        $responseData = [
            'code' => $code,       // 业务状态码
            'msg' => $msg,         // 提示信息
            'log_id' => TraceId::getId(),
            'timestamp' => time(), // 时间戳（可选）
        ];


        if ($isList) {
            $responseData['data'] = $data;
        }

        $container = \Hyperf\Context\ApplicationContext::getContainer();
        $response = $container->get(ResponseInterface::class);
        return $response->json($responseData);
    }

    public function data(array $data = [], string $msg = 'ok',int $code = 0): PsrResponseInterface
    {
        return $this->format($code, $msg, $data,true);
    }

}