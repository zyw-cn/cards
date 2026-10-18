<?php

declare(strict_types=1);

namespace App\Game\Niu\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Platform\Action\JoinAction as PlatformJoinAction;
use function Hyperf\Support\make;

/**
 * Niu.Join → 委托平台 Room.Join（兼容旧客户端直接打 Niu.Join）
 *
 * 人数上限由平台 Join 按 room.num 与 Module::maxPlayers() 取小。
 */
final class JoinAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        return make(PlatformJoinAction::class)->handle($ctx);
    }
}
