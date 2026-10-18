<?php

declare(strict_types=1);

namespace App\Game\Lz\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Platform\Action\JoinAction as PlatformJoinAction;
use function Hyperf\Support\make;

/**
 * Lz.Join → 委托平台 Room.Join（兼容旧客户端）
 */
final class JoinAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        return make(PlatformJoinAction::class)->handle($ctx);
    }
}
