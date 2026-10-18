<?php

declare(strict_types=1);

namespace App\Game\Pdk\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Platform\Action\JoinAction as PlatformJoinAction;
use function Hyperf\Support\make;

/** Pdk.Join → 委托平台 Room.Join */
final class JoinAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        return make(PlatformJoinAction::class)->handle($ctx);
    }
}
