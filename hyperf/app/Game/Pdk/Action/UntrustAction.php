<?php

declare(strict_types=1);

namespace App\Game\Pdk\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Pdk\CallBack;
use App\Game\Platform\Action\SharedUntrustAction;

/** Pdk.Untrust — 取消托管 */
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
