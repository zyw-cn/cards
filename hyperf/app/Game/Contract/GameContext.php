<?php

declare(strict_types=1);

namespace App\Game\Contract;

/**
 * 一次 WS 分发的上下文
 */
final class GameContext
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public readonly array $body,
        public readonly string $route = '',
        public readonly string $moduleCode = '',
        public readonly string $action = '',
    ) {
    }
}
