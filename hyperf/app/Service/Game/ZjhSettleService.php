<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Facades\Log;
use App\Game\Zjh\Card\ZjhCard;
use App\Game\Zjh\RoundState;
use App\Game\Zjh\ZjhConsts;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\BaseService;

/**
 * 炸金花结算：底注、闷牌、跟牌、加注、比牌已在局中扣入奖池。
 * 只剩一家未弃牌时，该家赢得奖池（不必比牌）。输家失去已投入的金币。
 *
 * 与斗牛/斗地主对赌模型不同，禁止复用其它 SettleService。
 */
class ZjhSettleService extends BaseService
{
    public function __construct(
        protected GameRoundService $gameRoundService,
        protected GamePlayCardService $playCardService,
        protected RoomService $roomService,
        protected RoomMemberService $roomMemberService,
        protected ZjhChipService $chipService,
    ) {
    }

    /**
     * @param array<string, mixed> $state
     * @return array{
     *     winnerPid:int, winnerSeat:int, pot:string,
     *     hands:list<array<string,mixed>>, rewards:list<array<string,mixed>>,
     *     playedCount:int, totalCount:int, roomOver:bool, room_num:string, noCompare:bool
     * }
     */
    public function settleToWinner(int $roomId, int $roundId, int $winnerPid, array $state): array
    {
        $empty = [
            'winnerPid'   => 0,
            'winnerSeat'  => -1,
            'pot'         => '0.00',
            'hands'       => [],
            'rewards'     => [],
            'playedCount' => 0,
            'totalCount'  => 0,
            'roomOver'    => false,
            'room_num'    => '',
            'noCompare'   => false,
        ];
        if ($winnerPid <= 0 || $roundId <= 0) {
            return $empty;
        }

        $pot = bcadd((string) ($state['pot'] ?? '0'), '0', 2);
        $after = '0.00';
        try {
            $after = $this->chipService->credit(
                $winnerPid,
                $pot,
                sprintf('炸金花结算 房间%d 局%d 奖池', $roomId, $roundId)
            );
        } catch (\Throwable $e) {
            Log::error('炸金花派彩失败', [
                'room_id'  => $roomId,
                'round_id' => $roundId,
                'winner'   => $winnerPid,
                'error'    => $e->getMessage(),
            ]);
            $after = '0.00';
        }

        $hands = [];
        $rewards = [];
        $winnerSeat = -1;
        foreach ($state['seatPids'] ?? [] as $seatKey => $pid) {
            $seat = (int) $seatKey;
            $pid = (int) $pid;
            $cards = $this->playCardService->getLatestHand($roundId, $pid);
            $ev = ZjhCard::evaluate($cards);
            $folded = RoundState::isFolded($state, $seat);
            $looked = RoundState::isLooked($state, $seat);
            $invested = bcadd((string) ($state['invested'][(string) $seat] ?? '0'), '0', 2);
            $isWinner = $pid === $winnerPid;
            if ($isWinner) {
                $winnerSeat = $seat;
            }
            $cols = $isWinner
                ? $this->columnAmountsOfOthers($state, $seat)
                : $this->columnAmounts($state, $seat);
            $colSum = $this->sumColumns($cols);
            $delta = $isWinner ? $colSum : bcsub('0', $colSum, 2);
            $points = $isWinner
                ? (float) $after
                : (float) $this->chipService->balance($pid);
            $bill = $this->formatColumns($cols);
            $hands[] = [
                'playerId' => $pid,
                'seatNo'   => $seat,
                'cards'    => $cards,
                'type'     => $ev['type'],
                'label'    => $ev['label'],
                'folded'   => $folded,
                'looked'   => $looked,
                'invested' => (float) $invested,
                'ante'     => (float) $cols['ante'],
                'men'      => (float) $cols['men'],
                'follow'   => (float) $cols['follow'],
                'pk'       => (float) $cols['pk'],
                'delta'    => (float) $delta,
                'bill'     => $bill,
                'win'      => $isWinner,
                'points'   => $points,
            ];
            $rewards[] = [
                'playerId' => $pid,
                'seatNo'   => $seat,
                'delta'    => (float) $delta,
                'invested' => (float) $invested,
                'bill'     => $bill,
                'points'   => $points,
            ];
        }

        $state['phase'] = ZjhConsts::PHASE_SETTLE;
        $state['pot'] = '0.00';
        $this->gameRoundService->update($roundId, [
            'status'          => GameRound::STATUS_ENDED,
            'extra'           => RoundState::encode($state),
            'winner_type'     => 1,
            'winner_user_ids' => (string) $winnerPid,
            'end_time'        => date('Y-m-d H:i:s'),
        ]);

        $room = $this->roomService->lockForUpdate(
            ['id' => $roomId],
            ['id', 'played_count', 'total_play_count', 'status', 'room_num']
        );
        if ($room === null) {
            return array_merge($empty, [
                'winnerPid'  => $winnerPid,
                'winnerSeat' => $winnerSeat,
                'pot'        => $pot,
                'hands'      => $hands,
                'rewards'    => $rewards,
                'noCompare'  => false,
            ]);
        }
        $playedCount = (int) ($room->played_count ?? 0) + 1;
        $total = (int) ($room->total_play_count ?? 0);
        $roomOver = $total > 0 && $playedCount >= $total;
        $roomUpdate = ['played_count' => $playedCount];
        if ($roomOver) {
            $roomUpdate['status'] = GameRoom::STATUS_ENDED;
            $roomUpdate['end_time'] = date('Y-m-d H:i:s');
        }
        $this->roomService->update($roomId, $roomUpdate);

        return [
            'winnerPid'   => $winnerPid,
            'winnerSeat'  => $winnerSeat,
            'pot'         => $pot,
            'hands'       => $hands,
            'rewards'     => $rewards,
            'playedCount' => $playedCount,
            'totalCount'  => $total,
            'roomOver'    => $roomOver,
            'room_num'    => (string) ($room->room_num ?? ''),
            'noCompare'   => false,
        ];
    }

    /**
     * 将 amount 记入 pot / invested，并按花费类型累加（调用方已完成扣款）
     *
     * kind：ante 打底 / men 闷牌 / call 跟牌 / raise 加注 / compare 比牌
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function applyChipIn(array $state, int $seat, string $amount, string $kind = 'call'): array
    {
        $amount = bcadd($amount, '0', 2);
        $state['pot'] = bcadd((string) ($state['pot'] ?? '0'), $amount, 2);
        $key = (string) $seat;
        $state['invested'][$key] = bcadd((string) ($state['invested'][$key] ?? '0'), $amount, 2);
        $kind = in_array($kind, ['ante', 'men', 'call', 'raise', 'compare'], true) ? $kind : 'call';
        if (! isset($state['spend']) || ! is_array($state['spend'])) {
            $state['spend'] = [];
        }
        if (! isset($state['spend'][$key]) || ! is_array($state['spend'][$key])) {
            $state['spend'][$key] = [];
        }
        $state['spend'][$key][$kind] = bcadd((string) ($state['spend'][$key][$kind] ?? '0'), $amount, 2);
        if ($kind === 'compare') {
            $state['compared'] = true;
        }

        return $state;
    }

    /**
     * 本家四列：打底、闷牌、跟牌（含加注）、比牌。
     *
     * @param array<string, mixed> $state
     * @return array{ante:string, men:string, follow:string, pk:string}
     */
    private function columnAmounts(array $state, int $seat): array
    {
        $row = $this->spendRow($state, $seat);
        $follow = bcadd((string) ($row['call'] ?? '0'), (string) ($row['raise'] ?? '0'), 2);

        return [
            'ante'   => bcadd((string) ($row['ante'] ?? '0'), '0', 2),
            'men'    => bcadd((string) ($row['men'] ?? '0'), '0', 2),
            'follow' => $follow,
            'pk'     => bcadd((string) ($row['compare'] ?? '0'), '0', 2),
        ];
    }

    /**
     * 赢家四列 = 其余各家交出的打底、闷牌、跟牌、比牌，相加即为赢的金额。
     *
     * @param array<string, mixed> $state
     * @return array{ante:string, men:string, follow:string, pk:string}
     */
    private function columnAmountsOfOthers(array $state, int $seat): array
    {
        $sum = ['ante' => '0.00', 'men' => '0.00', 'follow' => '0.00', 'pk' => '0.00'];
        foreach ($state['seatPids'] ?? [] as $seatKey => $_pid) {
            $other = (int) $seatKey;
            if ($other === $seat) {
                continue;
            }
            $cols = $this->columnAmounts($state, $other);
            foreach ($sum as $key => $value) {
                $sum[$key] = bcadd($value, $cols[$key], 2);
            }
        }

        return $sum;
    }

    /**
     * @param array{ante:string, men:string, follow:string, pk:string} $cols
     */
    private function sumColumns(array $cols): string
    {
        return bcadd(bcadd(bcadd($cols['ante'], $cols['men'], 2), $cols['follow'], 2), $cols['pk'], 2);
    }

    /**
     * @param array{ante:string, men:string, follow:string, pk:string} $cols
     */
    private function formatColumns(array $cols): string
    {
        $labels = [
            'ante'   => '打底',
            'men'    => '闷牌',
            'follow' => '跟牌',
            'pk'     => '比牌',
        ];
        $parts = [];
        foreach ($labels as $key => $name) {
            $amount = bcadd($cols[$key], '0', 2);
            if (bccomp($amount, '0', 2) <= 0) {
                continue;
            }
            $parts[] = $name . ' ' . $this->moneyText($amount);
        }

        return implode(' + ', $parts);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, string>
     */
    private function spendRow(array $state, int $seat): array
    {
        $row = $state['spend'][(string) $seat] ?? [];
        if (! is_array($row)) {
            $row = [];
        }
        $ante = bcadd((string) ($row['ante'] ?? '0'), '0', 2);
        if (bccomp($ante, '0', 2) <= 0) {
            $opening = bcadd((string) ($state['ante'] ?? '0'), '0', 2);
            if (bccomp($opening, '0', 2) > 0) {
                $row['ante'] = $opening;
            }
        }

        return $row;
    }

    private function moneyText(string $amount): string
    {
        $amount = bcadd($amount, '0', 2);
        if (str_ends_with($amount, '.00')) {
            return substr($amount, 0, -3);
        }

        return rtrim(rtrim($amount, '0'), '.');
    }
}
