<?php

declare(strict_types=1);

namespace App\Game\Pdk;

use App\Game\Pdk\Play\PdkPlayStateResolver as RealPdkPlayStateResolver;
use App\Service\Game\GamePlayCardService;

/**
 * 兼容旧类名 App\Game\Pdk\PdkPlayStateResolver（实际实现在 Play 子命名空间）
 */
class PdkPlayStateResolver
{
    /**
     * @param array<int,int> $seatByPid
     * @param list<int> $surrendered
     * @return array{currentIndex:int, lastPlay: ?array, mustLead: bool}
     */
    public function resolve(
        GamePlayCardService $cardService,
        int $roundId,
        array $seatByPid,
        int $firstSeat,
        array $surrendered,
    ): array {
        return (new RealPdkPlayStateResolver())->resolve(
            $cardService,
            $roundId,
            $seatByPid,
            $firstSeat,
            $surrendered
        );
    }
}
