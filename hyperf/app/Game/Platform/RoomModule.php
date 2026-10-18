<?php

declare(strict_types=1);

namespace App\Game\Platform;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\RoutableModuleInterface;
use App\Game\Platform\Action\ApplyDismissAction;
use App\Game\Platform\Action\ChatAction;
use App\Game\Platform\Action\DismissAction;
use App\Game\Platform\Action\JoinAction;
use App\Game\Platform\Action\LeaveAction;
use App\Game\Platform\Action\VoteDismissAction;
use function Hyperf\Support\make;

/**
 * 平台房间 Module（路由前缀 Room）
 *
 * 与玩法无关的入座等动作；resume 仍按房间 game_type 委托对应 GameModule。
 */
final class RoomModule implements RoutableModuleInterface
{
    public function code(): string
    {
        return 'Room';
    }

    public function action(string $name): ?GameActionInterface
    {
        $key = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');

        return match ($key) {
            'join' => make(JoinAction::class),
            'chat', 'say', 'message' => make(ChatAction::class),
            'leave', 'exit', 'quit', 'dissolve' => make(LeaveAction::class),
            'dismiss', 'dismissroom', 'ownerdismiss' => make(DismissAction::class),
            'applydismiss', 'applyclose' => make(ApplyDismissAction::class),
            'votedismiss', 'voteclose' => make(VoteDismissAction::class),
            default => null,
        };
    }
}
