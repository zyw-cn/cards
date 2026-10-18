<?php

declare(strict_types=1);

namespace App\Game\Pdk\Play;

use App\Game\Pdk\PdkConsts;
use App\Service\Game\GamePlayCardService;

/**
 * 跑得快出牌轮次：跳过已认输座位
 */
final class PdkPlayStateResolver
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
        $state = $cardService->resolvePlayState(
            $roundId,
            $seatByPid,
            $firstSeat,
            PdkConsts::MAX_PLAYERS
        );
        $state['currentIndex'] = SurrenderHelper::advanceToActive(
            (int) ($state['currentIndex'] ?? $firstSeat),
            $surrendered,
            PdkConsts::MAX_PLAYERS
        );

        return $state;
    }
}
