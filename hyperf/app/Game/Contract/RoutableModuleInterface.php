<?php

declare(strict_types=1);

namespace App\Game\Contract;

/**
 * 可路由模块（平台 Room 与玩法 Dou 共用）
 */
interface RoutableModuleInterface
{
    /** WS route 前缀，如 Room / Dou */
    public function code(): string;

    public function action(string $name): ?GameActionInterface;
}
