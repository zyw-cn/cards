<?php

declare(strict_types=1);

namespace App\Game\Hzmj\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Hzmj\CallBack;
use App\Game\Platform\Action\SharedUntrustAction;

/** Hzmj.Untrust — 取消托管 */
final class UntrustAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        return SharedUntrustAction::execute($ctx->body, [
            'callback'  => CallBack::UNTRUST,
            'broadcast' => true,
        ]);
    }
}
