<?php

declare(strict_types=1);

namespace App\Game\Pdk\Play;

use App\Exception\ApiException;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\PdkSettleService;
use App\Service\Game\RoomFeeService;
use App\Service\Game\RoomService;
use function Hyperf\Support\make;

/**
 * 跑得快一局结束：按剩余张数结算、平台费、房间/局状态
 */
final class RoundSettleApplier
{
    /**
     * @return array<string, mixed>
     */
    public function apply(
        int $roomId,
        int $roundId,
        int $winnerPid,
        int $winnerSeat,
        GameRoundService $gameRoundService,
    ): array {
        $settleResult = make(PdkSettleService::class)->settle($roomId, $roundId, $winnerPid);

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

        $feePayload = [
            'charged'   => (bool) ($feeResult['charged'] ?? false),
            'payMode'   => (int) ($feeResult['payMode'] ?? 0),
            'payAmount' => (float) ($feeResult['payAmount'] ?? 0),
            'charges'   => $feeResult['charges'] ?? [],
        ];

        return [
            'winnerIndex'  => $winnerSeat,
            'unit'         => $settleResult['unit'] ?? 0,
            'bombUnit'     => $settleResult['bombUnit'] ?? 0,
            'bombMultiple' => $settleResult['bombMultiple'] ?? 0,
            'bombCount'    => $settleResult['bombCount'] ?? 0,
            'fangzou'      => $settleResult['fangzou'] ?? null,
            'rewards'      => $rewards,
            'platformFee'  => $feePayload,
            'roomFee'      => $feePayload,
            'playedCount'  => $playedCount,
            'totalCount'   => $total,
            'roomOver'     => $roomOver,
        ];
    }
}
