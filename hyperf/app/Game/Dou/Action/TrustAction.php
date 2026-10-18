<?php

declare(strict_types=1);

namespace App\Game\Dou\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Dou\CallBack;
use App\Game\Platform\Action\SharedTrustAction;

/** Dou.Trust — 开启托管 */
final class TrustAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        return SharedTrustAction::execute($ctx->body, [
            'callback'  => CallBack::TRUST,
            'immediate' => true,
            'broadcast' => true,
        ]);
    }
}
