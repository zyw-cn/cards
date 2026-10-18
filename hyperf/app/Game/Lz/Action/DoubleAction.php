<?php

declare(strict_types=1);

namespace App\Game\Lz\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Landlord\LandlordVariant;
use App\Game\Landlord\SharedDoubleAction;
use function Hyperf\Support\make;

/**
 * Lz.Double — 加倍（委托 SharedDoubleAction）
 */
final class DoubleAction implements GameActionInterface
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
        return make(SharedDoubleAction::class)->run($body, LandlordVariant::lz());
    }
}
