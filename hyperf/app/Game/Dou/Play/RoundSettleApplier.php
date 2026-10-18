<?php

declare(strict_types=1);

namespace App\Game\Dou\Play;

use App\Exception\ApiException;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\GameSettleService;
use App\Service\Game\RoomFeeService;
use App\Service\Game\RoomService;
use function Hyperf\Support\make;

/**
 * 斗地主一局结束：输赢结算、平台费、房间/局状态
 */
final class RoundSettleApplier
{
    /**
     * @return array<string, mixed>
     */
    public function apply(
        int $roomId,
        int $roundId,
        int $masterPid,
        int $winnerPid,
        int $winnerSeat,
        GameRoundService $gameRoundService,
    ): array {
        $settleResult = make(GameSettleService::class)->settle(
            $roomId,
            $roundId,
            $masterPid,
            $winnerPid
        );

        $feePack = make(RoomFeeService::class)->chargeAfterSettle($roomId, $roundId, $settleResult);
        $settleResult = $feePack['settle'];
        $feeResult = $feePack['fee'];
        $rewards = $settleResult['rewards'] ?? [];

        $roomService = make(RoomService::class);
        $room = $roomService->lockForUpdate(
            ['id' => $roomId],
            ['id', 'played_count', 'total_play_count', 'status']
        );
        if ($room === null) {
            throw new ApiException('房间不存在');
        }
        $playedCount = (int) ($room->played_count ?? 0) + 1;
        $total = (int) ($room->total_play_count ?? 0);
        $roomOver = $total > 0 && $playedCount >= $total;

        $roomUpdate = ['played_count' => $playedCount];
        if ($roomOver) {
            $roomUpdate['status'] = GameRoom::STATUS_ENDED;
            $roomUpdate['end_time'] = date('Y-m-d H:i:s');
        }
        $roomService->update($roomId, $roomUpdate);

        $gameRoundService->update($roundId, [
            'status'          => GameRound::STATUS_ENDED,
            'winner_user_ids' => (string) $winnerPid,
            'end_time'        => date('Y-m-d H:i:s'),
        ]);

        $feePayload = self::feePayload($feeResult);

        return [
            'winnerIndex'   => $winnerSeat,
            'landlordWin'   => $settleResult['landlordWin'],
            'winSide'       => $settleResult['winSide'],
            'multiple'      => $settleResult['multiple'],
            'bidMultiple'   => $settleResult['bidMultiple'],
            'unit'          => $settleResult['unit'],
            'bombCount'     => $settleResult['bombCount'],
            'spring'        => $settleResult['spring'],
            'springType'    => $settleResult['springType'],
            'farmerDoubles' => $settleResult['farmerDoubles'],
            'rewards'       => $rewards,
            'hands'         => $settleResult['hands'] ?? [],
            'remainCards'   => $settleResult['remainCards'] ?? [],
            'platformFee'   => $feePayload,
            'roomFee'       => $feePayload,
            'playedCount'   => $playedCount,
            'totalCount'    => $total,
            'roomOver'      => $roomOver,
        ];
    }

    /**
     * @param array<string, mixed> $feeResult
     * @return array{charged: bool, payMode: int, payAmount: float, charges: mixed}
     */
    public static function feePayload(array $feeResult): array
    {
        return [
            'charged'   => (bool) ($feeResult['charged'] ?? false),
            'payMode'   => (int) ($feeResult['payMode'] ?? 0),
            'payAmount' => (float) ($feeResult['payAmount'] ?? 0),
            'charges'   => $feeResult['charges'] ?? [],
        ];
    }
}
