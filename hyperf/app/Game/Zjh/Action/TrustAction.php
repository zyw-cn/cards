<?php

declare(strict_types=1);

namespace App\Game\Zjh\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Platform\Action\SharedTrustAction;
use App\Game\Zjh\CallBack;

/** Zjh.Trust — 开启托管 */
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
