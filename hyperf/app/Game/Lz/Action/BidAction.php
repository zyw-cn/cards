<?php

declare(strict_types=1);

namespace App\Game\Lz\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Landlord\LandlordVariant;
use App\Game\Landlord\SharedBidAction;
use function Hyperf\Support\make;

/**
 * Lz.Bid — 叫地主 / 抢地主（委托 SharedBidAction）
 */
final class BidAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        return $this->run($ctx->body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{code: int, message: string, data: mixed, _broadcast?: array}
     */
    public function run(array $body): array
    {
        return make(SharedBidAction::class)->run($body, LandlordVariant::lz());
    }
}
