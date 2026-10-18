<?php

declare(strict_types=1);

namespace App\Game\Platform\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Utils\WebSocketSession;
use function Hyperf\Support\make;

/**
 * 平台离开房间（路由 Room.Leave / Room.Exit / Room.Quit）
 *
 * 进房后不能离座退出：
 * - 当前局进行中：自动托管，保留座位
 * - 其余情况：保留座位，提示用解散/投票离房
 * 真正关房：未开局房主解散，或已开局全员投票同意。
 */
final class LeaveAction implements GameActionInterface
{
    public function handle(GameContext $ctx): array
    {
        $body = $ctx->body;
        $playerId = WebSocketSession::uid($body);
        $fd = (int) ($body['fd'] ?? 0);
        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }

        $roomMemberService = make(RoomMemberService::class);
        $member = $roomMemberService->getActiveByPlayer($playerId);
        if ($member === null) {
            return [
                'code'    => 0,
                'message' => 'ok',
                'data'    => [
                    'callback' => 'leave',
                    'reason'   => '已离开房间',
                    'left'     => true,
                ],
            ];
        }

        $roomId = (int) $member->room_id;
        $seatNo = (int) ($member->seat_no ?? 0);
        $memberRowId = (int) ($member->id ?? 0);
        $room = make(RoomService::class)->getOne(
            ['id' => $roomId],
            'id,room_num,status,num,game_type,played_count'
        );
        if ($room === null || ! GameRoom::isActiveStatus((int) ($room->status ?? 0))) {
            $roomMemberService->markEndedForPlayer($roomId, $playerId);

            return [
                'code'    => 0,
                'message' => 'ok',
                'data'    => [
                    'callback' => 'leave',
                    'reason'   => '已离开房间',
                    'left'     => true,
                ],
            ];
        }

        $roomNum = (string) ($room->room_num ?? '');
        $playing = make(GameRoundService::class)->getOne([
            'room_id' => $roomId,
            'status'  => GameRound::STATUS_PLAYING,
        ], 'id');

        if ($playing !== null) {
            return $this->autoTrustAndKeepSeat(
                $roomMemberService,
                $roomId,
                $playerId,
                $memberRowId,
                $seatNo,
                $fd,
                $roomNum
            );
        }

        // 未在对局中：仍保留座位，不因返回大厅离座
        $data = [
            'callback' => 'leave',
            'trusted'  => false,
            'keepSeat' => true,
            'left'     => false,
            'seatNo'   => $seatNo,
            'playerId' => $playerId,
            'roomId'   => $roomNum,
            'reason'   => '已保留座位；返回大厅不会退出房间，解散请房主操作或全员投票',
        ];

        return [
            'code'    => 0,
            'message' => 'ok',
            'data'    => $data,
        ];
    }

    /**
     * @return array{code:int,message:string,data:mixed,_broadcast?:array}
     */
    private function autoTrustAndKeepSeat(
        RoomMemberService $roomMemberService,
        int $roomId,
        int $playerId,
        int $memberRowId,
        int $seatNo,
        int $fd,
        string $roomNum,
    ): array {
        $roomMemberService->setTrusted($roomId, $playerId);
        if ($fd > 0 && $memberRowId > 0) {
            $roomMemberService->touchMemberFd($memberRowId, $fd);
        }

        $timeoutService = make(RoomTimeoutService::class);
        $timeoutService->tryImmediateAutoPlay($roomId, $playerId);
        $timeoutService->armGameTimeout($roomId);

        $data = [
            'callback' => 'trust',
            'trusted'  => true,
            'keepSeat' => true,
            'left'     => false,
            'seatNo'   => $seatNo,
            'playerId' => $playerId,
            'roomId'   => $roomNum,
            'reason'   => '返回大厅已自动托管，座位保留',
        ];
        $members = $roomMemberService->getActiveInRoom($roomId, 'fd,player_id,seat_no');

        return [
            'code'       => 0,
            'message'    => 'ok',
            'data'       => $data,
            '_broadcast' => [
                'targetFds' => $roomMemberService->otherFds($members, $fd),
                'payload'   => ['code' => 0, 'message' => 'ok', 'data' => $data],
            ],
        ];
    }
}
