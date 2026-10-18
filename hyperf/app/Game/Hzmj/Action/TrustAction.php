<?php

declare(strict_types=1);

namespace App\Game\Hzmj\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Hzmj\CallBack;
use App\Game\Platform\Action\SharedTrustAction;

/** Hzmj.Trust — 开启托管（代打走 2s 超时，不立即出牌） */
final class TrustAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        return SharedTrustAction::execute($ctx->body, [
            'callback'  => CallBack::TRUST,
            'immediate' => false,
            'broadcast' => true,
        ]);
    }
}
