<?php

declare(strict_types=1);

namespace App\Game\Lz\Play;

use App\Game\Lz\Card\LaiziCard;
use App\Game\Lz\LzConsts;
use App\Service\Game\GamePlayCardService;

/**
 * 癞子斗地主出牌校验（独立于斗地主 LandlordCard）
 */
final class PlayValidator
{
    /**
     * @param list<string> $played
     * @param array<int,int> $seatByPid
     * @return array{
     *   ok: bool,
     *   message?: string,
     *   isPass?: bool,
     *   hand?: list<string>,
     *   remain?: list<string>,
     *   cardType?: string,
     *   playState?: array{currentIndex:int, lastPlay: ?array, mustLead: bool}
     * }
     */
    public function validate(
        GamePlayCardService $cardService,
        int $roundId,
        int $playerId,
        int $seatNo,
        array $played,
        array $seatByPid,
        int $landlordSeat,
    ): array {
        $playState = $cardService->resolvePlayState(
            $roundId,
            $seatByPid,
            $landlordSeat,
            LzConsts::MAX_PLAYERS
        );
        if ($playState['currentIndex'] !== $seatNo) {
            return ['ok' => false, 'message' => '还没轮到你'];
        }

        $hand = $cardService->getLatestHand($roundId, $playerId);
        if ($hand === []) {
            return ['ok' => false, 'message' => '手牌数据异常'];
        }

        if ($played === []) {
            if ($playState['mustLead']) {
                return ['ok' => false, 'message' => '首出不能不出'];
            }

            return [
                'ok'        => true,
                'isPass'    => true,
                'hand'      => $hand,
                'remain'    => $hand,
                'playState' => $playState,
            ];
        }

        [$cardType] = LaiziCard::analyzeCards($played);
        if ($cardType === LaiziCard::CARD_TYPE_INVALID) {
            return ['ok' => false, 'message' => '牌型不合法'];
        }

        $lastCards = $playState['lastPlay']['cards'] ?? [];
        if ($lastCards !== [] && ! LaiziCard::isBigger($played, $lastCards)) {
            return ['ok' => false, 'message' => '压不过上家'];
        }

        $remain = GamePlayCardService::subtractCards($hand, $played);
        if ($remain === null) {
            return ['ok' => false, 'message' => '手牌不足或牌面不匹配'];
        }

        return [
            'ok'        => true,
            'isPass'    => false,
            'hand'      => $hand,
            'remain'    => $remain,
            'cardType'  => $cardType,
            'playState' => $playState,
        ];
    }
}
