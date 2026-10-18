<?php

declare(strict_types=1);

namespace App\Game\Contract;

/**
 * 单个玩法动作（对应一条 WS route）
 */
interface GameActionInterface
{
    /**
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    public function handle(GameContext $ctx): array;
}
