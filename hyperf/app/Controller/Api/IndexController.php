<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Controller\Api;

use App\Controller\AbstractController;
use Hyperf\HttpServer\Annotation\AutoController;
use App\Facades\Log;
#[AutoController]
class IndexController extends AbstractController
{
    public function index()
    {
         Log::info('用户登录成功', ['user_id' => 123]);
         Log::error("error测试", ['user_id' => 123]);
         Log::warn("warn测试", ['user_id' => 123]);
         return app("Json")->success();

    }
}
