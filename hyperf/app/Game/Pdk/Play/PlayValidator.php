<?php

declare(strict_types=1);

namespace App\Game\Pdk\Play;

use App\Game\Pdk\Card\PdkCard;
use App\Game\Pdk\PdkConsts;
use App\Service\Game\GamePlayCardService;
use function Hyperf\Support\make;

/**
 * 跑得出牌校验（不改共享 GamePlayCardService 中的斗地主逻辑）
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
     *   played?: list<string>,
     *   forcedPlay?: bool,
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
        int $firstSeat,
        bool $spade3Played,
        array $surrendered = [],
    ): array {
        unset($spade3Played);
        if (SurrenderHelper::isSurrendered($seatNo, $surrendered)) {
            return ['ok' => false, 'message' => '你已认输，无法出牌'];
        }

        $playState = make(PdkPlayStateResolver::class)->resolve(
            $cardService,
            $roundId,
            $seatByPid,
            $firstSeat,
            $surrendered
        );
        if ($playState['currentIndex'] !== $seatNo) {
            return ['ok' => false, 'message' => '还没轮到你'];
        }

        $hand = $cardService->getLatestHand($roundId, $playerId);
        if ($hand === []) {
            return ['ok' => false, 'message' => '手牌数据异常'];
        }

        $nextCount = $this->nextSeatCardCount(
            $cardService,
            $roundId,
            $seatNo,
            $seatByPid,
            $surrendered
        );
        $forceLargestSingle = $nextCount === 1;
        // 下家剩 2 张不限制牌型，随便出
        $lastCards = $playState['lastPlay']['cards'] ?? [];
        $forcedPlay = false;

        if ($played === []) {
            // 领出传空牌不能代出。否则 A 出牌、B/C 管不上后，过期的「不出」会被收成自动出一张。
            // 跟牌能管则仍由服务端代出；真正超时由倒计时把建议牌带上再打。
            if (! empty($playState['mustLead'])) {
                return ['ok' => false, 'message' => '首出不能不出'];
            }
            $forcedCards = PdkCard::suggestPlay(
                $hand,
                $playState['mustLead'] ? [] : $lastCards,
                false,
                $forceLargestSingle,
                false
            );
            if ($forcedCards !== []) {
                $played = $forcedCards;
                $forcedPlay = true;
            } elseif ($playState['mustLead']) {
                return ['ok' => false, 'message' => '首出不能不出'];
            } else {
                return [
                    'ok'        => true,
                    'isPass'    => true,
                    'hand'      => $hand,
                    'remain'    => $hand,
                    'played'    => [],
                    'playState' => $playState,
                ];
            }
        }

        $remain = GamePlayCardService::subtractCards($hand, $played);
        if ($remain === null) {
            return ['ok' => false, 'message' => '手牌不足或牌面不匹配'];
        }

        $isLastHand = $remain === [];
        [$cardType] = PdkCard::analyzeCards($played, $isLastHand);
        if ($cardType === PdkCard::CARD_TYPE_INVALID) {
            return ['ok' => false, 'message' => '牌型不合法'];
        }
        if ($cardType === PdkCard::CARD_TYPE_TRIPLE && ! $isLastHand) {
            return ['ok' => false, 'message' => '三不带只能最后一手出'];
        }
        if ($cardType === PdkCard::CARD_TYPE_PLANE && ! $isLastHand) {
            return ['ok' => false, 'message' => '飞机不带只能最后一手出'];
        }

        // 下家剩 1 张时，出单可以不是最大，但记为放走：下家若因此赢牌，由放走者包赔。
        // 托管代出仍优先最大单，避免自动放走。
        $fangzou = false;
        if ($forceLargestSingle && $cardType === PdkCard::CARD_TYPE_SINGLE && count($played) === 1) {
            $handPoints = array_map([PdkCard::class, 'toPoint'], $hand);
            $playedPoint = PdkCard::toPoint($played[0]);
            if ($handPoints !== [] && $playedPoint !== max($handPoints)) {
                $fangzou = true;
            }
        }

        if ($lastCards !== [] && ! PdkCard::isBigger($played, $lastCards)) {
            return ['ok' => false, 'message' => '压不过上家'];
        }

        return [
            'ok'         => true,
            'isPass'     => false,
            'hand'       => $hand,
            'remain'     => $remain,
            'played'     => $played,
            'forcedPlay' => $forcedPlay,
            'cardType'   => $cardType,
            'fangzou'    => $fangzou,
            'playState'  => $playState,
        ];
    }

    /**
     * 下家（seat+1）剩余张数；认输则视为 0
     *
     * @param array<int,int> $seatByPid
     */
    private function nextSeatCardCount(
        GamePlayCardService $cardService,
        int $roundId,
        int $seatNo,
        array $seatByPid,
        array $surrendered = [],
    ): int {
        $nextSeat = ($seatNo + 1) % PdkConsts::MAX_PLAYERS;
        if (SurrenderHelper::isSurrendered($nextSeat, $surrendered)) {
            return 0;
        }
        $pidBySeat = array_flip($seatByPid);
        $nextPid = (int) ($pidBySeat[$nextSeat] ?? 0);
        if ($nextPid <= 0) {
            return 0;
        }

        return count($cardService->getLatestHand($roundId, $nextPid));
    }
}
