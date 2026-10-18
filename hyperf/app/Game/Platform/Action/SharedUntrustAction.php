<?php

declare(strict_types=1);

namespace App\Game\Platform\Action;

use App\Game\Contract\GameActionInterface;
use App\Game\Contract\GameContext;
use App\Game\Platform\RoomCallBack;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use App\Service\Game\RoomMemberService;
use App\Utils\WebSocketSession;
use function Hyperf\Support\make;

/**
 * 全玩法共用「取消托管」
 *
 * @param array{callback?:string,broadcast?:bool} $options
 */
final class SharedUntrustAction implements GameActionInterface
{
    /** @var array{callback:string,broadcast:bool} */
    private array $options;

    /**
     * @param array{callback?:string,broadcast?:bool} $options
     */
    public function __construct(array $options = [])
    {
        $this->options = [
            'callback'  => (string) ($options['callback'] ?? RoomCallBack::UNTRUST),
            'broadcast' => array_key_exists('broadcast', $options) ? (bool) $options['broadcast'] : true,
        ];
    }

    public function handle(GameContext $ctx): array
    {
        return $this->run($ctx->body);
    }

    /**
     * @param array<string, mixed> $body
     * @param array{callback?:string,broadcast?:bool} $options
     * @return array{code:int,message:string,data:mixed,_broadcast?:array}
     */
    public static function execute(array $body, array $options = []): array
    {
        return (new self($options))->run($body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{code:int,message:string,data:mixed,_broadcast?:array}
     */
    public function run(array $body): array
    {
        $playerId = WebSocketSession::uid($body);
        $fd = (int) ($body['fd'] ?? 0);
        if ($playerId <= 0) {
            return ['code' => 401, 'message' => '请先登录', 'data' => null];
        }

        $roomMemberService = make(RoomMemberService::class);
        $member = $roomMemberService->getActiveByPlayer($playerId);
        if ($member === null) {
            return ['code' => 400, 'message' => '您还未加入任何房间', 'data' => null];
        }
        $roomId = (int) $member->room_id;
        $seatNo = (int) ($member->seat_no ?? 0);

        $round = make(GameRoundService::class)->getOne(
            [
                'room_id' => $roomId,
                'status'  => GameRound::STATUS_PLAYING,
            ],
            'id,status'
        );
        if ($round !== null) {
            $roomMemberService->clearTrust($roomId, $playerId);
            make(RoomTimeoutService::class)->armGameTimeout($roomId);
        }

        if ($fd > 0) {
            $roomMemberService->touchMemberFd((int) $member->id, $fd);
        }

        $data = [
            'callback' => $this->options['callback'],
            'trusted'  => false,
            'seatNo'   => $seatNo,
            'playerId' => $playerId,
        ];

        if (! $this->options['broadcast']) {
            return ['code' => 0, 'message' => 'ok', 'data' => $data];
        }

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
