<?php

declare(strict_types=1);

namespace App\Game;

use App\Exception\ApiException;
use App\Model\Game\GameRoom;
use App\Model\Game\RoomMember;
use App\Service\Game\RoomMemberService;
use App\Service\Game\RoomService;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Service\Member\MemberService;
use Hyperf\DbConnection\Db;
use App\Facades\Log;
use App\Model\Game\GameRound;
use App\Service\Game\GameRoundService;
use function Hyperf\Support\make;
/**
 * 平台「入座」领域服务（与具体玩法无关）
 *
 * 归属边界：
 * - 只做：校验房间可进、入场金币、占座/重连刷新 fd、返回座位与成员列表、首人入座挂超时
 * - 不做：建房（RoomService）、WS 组包/广播（Platform\JoinAction）、resume（各 Module）、
 *         开局/抢庄/出牌/结算（Dou|Niu Action）
 *
 * 调用链：Room.Join / Dou.Join / Niu.Join → Platform\JoinAction → Room::join()
 * maxPlayers 由 JoinAction 按 game_type + room.num 算好再传入，本类不解析玩法。
 */
class Room
{
    public function __construct(
        protected RoomService $roomService,
        protected RoomMemberService $roomMemberService,
        protected MemberService $memberService,
        protected RoomTimeoutService $roomTimeoutService,
    ) {
    }

    /**
     * 加入房间（事务 + 行锁，防止并发重复入座）
     *
     * @param string $roomNum    房间号
     * @param int    $uid        玩家ID
     * @param int    $fd         WebSocket 连接 fd
     * @param int    $maxPlayers 最大人数
     * @return array{
     *     room_num: string,
     *     seat_no: int,
     *     total_play_count: int,
     *     is_new: bool,
     *     member: array{nickname: string, avatar: string},
     *     player_count: int,
     *     is_full: bool,
     *     members: array<int, array{player_id: int, seat_no: int, nickname: string, avatar: string, fd: int}>,
     *     callback: string
     * }
     * @throws ApiException
     */
    public function join(string $roomNum, int $uid, int $fd, int $maxPlayers = 3): array
    {
        // 事务外轻量校验，减少不必要的锁竞争
        $roomInfo = $this->roomService->getByRoomNum($roomNum);

        if ($roomInfo === null) {
            throw new ApiException('不存在对应的房间号');
        }
        if (! GameRoom::isActiveStatus((int) $roomInfo->status)) {
            throw new ApiException('房间当前状态不可加入');
        }

        // 事务外查询玩家信息（读操作，无需持有行锁）
        $memberInfo = $this->memberService->getOne(['id' => $uid],'nickname,avatar,points,realname');
        $nickname   = (string) ($memberInfo?->nickname ?? $memberInfo?->realname ?? ('玩家' . $uid));
        $avatar     = $memberInfo?->avatar ?? '';
        $points     = $this->normalizePoints($memberInfo?->points);

        // 入场金币门槛：至少底分的 5 倍
        $entryPoints = $this->normalizePoints(
            $this->roomService->resolveEntryPoints($roomInfo->rule ?? null)
        );
        $bottomPoints = $this->normalizePoints(
            $this->roomService->resolveBottomPoints($roomInfo->rule ?? null)
        );

        // 事务外：若挂在别的空闲房（局结未准备），先释放，避免「已在其他房间」
        $this->releaseIdleMembershipIfNeeded($uid, (int) $roomInfo->id);

        try {
            $result = Db::transaction(function () use ($roomInfo, $uid, $maxPlayers, $nickname, $fd, $avatar, $points, $entryPoints, $bottomPoints) {
                // 对房间行加排它锁，同一房间并发加入串行化
                $room = $this->roomService->lockForUpdate(
                    ['id' => $roomInfo->id],
                    ['id', 'status', 'total_play_count']
                );
                if ($room === null || ! GameRoom::isActiveStatus((int) $room->status)) {
                    throw new ApiException('房间当前状态不可加入');
                }

                // 检查玩家是否已在某个「未结束」房间
                $existingMember = $this->roomMemberService->getActiveByPlayer($uid);
                if ($existingMember !== null) {
                    if ((int) $existingMember->room_id !== (int) $room->id) {
                        $oldNum = (string) ($this->roomService->getOne(
                            ['id' => (int) $existingMember->room_id],
                            'room_num'
                        )?->room_num ?? '');
                        throw new ApiException(
                            $oldNum !== ''
                                ? sprintf('您已在房间 %s 中，不允许同时进入多个房间', $oldNum)
                                : '您已在其他房间中，不允许同时进入多个房间'
                        );
                    }
                    // 已在本房间（重复点击/断线重连），沿用原座位，刷新 fd
                    $seatNo = (int) $existingMember->seat_no;
                    $patch = [];
                    if ($fd > 0 && (int) ($existingMember->fd ?? 0) !== $fd) {
                        $patch['fd'] = $fd;
                    }
                    // 重连时若仍是等待态保持；游戏中保持游戏中
                    if (!empty($patch)) {
                        $this->roomMemberService->update((int) $existingMember->id, $patch);
                    }
                    $isNew = false;
                } else {
                    // 有进行中牌局：只允许原成员重连，禁止新人中途入座
                    $playingRound = make(GameRoundService::class)->getOne(
                        [
                            'room_id' => (int) $room->id,
                            'status'  => GameRound::STATUS_PLAYING,
                        ],
                        'id'
                    );
                    if ($playingRound !== null) {
                        throw new ApiException('牌局进行中，仅原座位玩家可重连');
                    }

                    // 入场金币门槛：至少底分的 5 倍
                    if (bccomp($points, $entryPoints, 2) < 0) {
                        throw new ApiException(sprintf(
                            '金币数量至少是底分的5倍（底分 %s，需至少 %s）',
                            $bottomPoints,
                            $entryPoints
                        ));
                    }

                    // 仅统计未结束成员占用的座位
                    $usedSeats = $this->roomMemberService->getColumn(
                        [
                            'room_id' => $room->id,
                            ['status', '<', RoomMember::STATUS_ENDED],
                        ],
                        'seat_no'
                    );
                    if (count($usedSeats) >= $maxPlayers) {
                        throw new ApiException('当前房间人数已满，请选择其他房间');
                    }

                    $usedSeatMap = array_flip($usedSeats);
                    $seatNo = 0;
                    for (; $seatNo < $maxPlayers; $seatNo++) {
                        if (! isset($usedSeatMap[$seatNo])) {
                            break;
                        }
                    }

                    // 同房间曾结束的成员行：复用，改 status/fd，不删建
                    $endedRow = $this->roomMemberService->getOne([
                        'room_id'   => $room->id,
                        'player_id' => $uid,
                        'status'    => RoomMember::STATUS_ENDED,
                    ]);
                    if ($endedRow !== null) {
                        // 原座位若已被占用则换空座
                        $oldSeat = (int) $endedRow->seat_no;
                        if (isset($usedSeatMap[$oldSeat])) {
                            // keep newly computed $seatNo
                        } else {
                            $seatNo = $oldSeat;
                        }
                        $this->roomMemberService->update((int) $endedRow->id, [
                            'seat_no'      => $seatNo,
                            'fd'           => $fd,
                            'status'       => RoomMember::STATUS_WAITING,
                            'exit_time'    => null,
                            'is_auto'      => 0,
                            'time_out_num' => 0,
                            'join_time'    => date('Y-m-d H:i:s'),
                        ]);
                    } else {
                        $this->roomMemberService->save([
                            'room_id'   => $room->id,
                            'player_id' => $uid,
                            'seat_no'   => $seatNo,
                            'fd'        => $fd,
                            'status'    => RoomMember::STATUS_WAITING,
                        ]);
                    }
                    $isNew = true;
                }

                // 锁内取最新「未结束」成员名单
                $members = $this->roomMemberService->getActiveInRoom(
                    (int) $room->id,
                    'player_id,seat_no,fd'
                );
                $playerIds = array_values(array_unique(array_map(
                    static fn (array $item): int => (int) ($item['player_id'] ?? 0),
                    $members
                )));
                $memberProfiles = [];
                if (! empty($playerIds)) {
                    $profiles = $this->memberService->getAll(['id' => $playerIds], 'id,avatar,nickname,realname,points');
                    foreach ($profiles as $profile) {
                        $mid = (int) ($profile['id'] ?? 0);
                        if ($mid > 0) {
                            $memberProfiles[$mid] = $profile;
                        }
                    }
                }
                foreach ($members as &$item) {
                    $pid = (int) ($item['player_id'] ?? 0);
                    $profile = $memberProfiles[$pid] ?? [];
                    $relAvatar = (string) ($profile['avatar'] ?? '');
                    $item['nickname'] = (string) ($profile['nickname'] ?? $profile['realname'] ?? ('玩家' . $pid));
                    $item['avatar'] = $relAvatar;
                    $item['avatar_url'] = $this->memberService->buildAvatarUrl($relAvatar);
                    $item['points'] = (float) ($profile['points'] ?? 0);
                    $item['gold'] = (float) ($profile['points'] ?? 0);
                }
                unset($item);
                $playerCount = count($members);
                $avatarUrl = $this->memberService->buildAvatarUrl((string) $avatar);

                return [
                    'room_num'         => $roomInfo->room_num,
                    'seat_no'          => $seatNo,
                    'total_play_count' => $room->total_play_count,
                    'is_new'           => $isNew,
                    'member'           => [
                        'nickname'   => $nickname,
                        'realname'   => (string) ($memberInfo?->realname ?? ''),
                        'avatar'     => (string) $avatar,
                        'avatar_url' => $avatarUrl,
                        'points'     => (float) $points,
                        'gold'       => (float) $points,
                    ],
                    'player_count'     => $playerCount,
                    'is_full'          => $playerCount >= $maxPlayers,
                    'members'          => $members,
                ];
            });

            // 未开局不挂等待超时（解散走申请解散 / 房主解散）；局内回合超时仍由此挂表
            $this->roomTimeoutService->armGameTimeout((int) $roomInfo->id, $maxPlayers);

            return $result;
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("加入房间失败",["error"=>$e->getMessage(),'trace'=>$e->getTraceAsString()]);
            throw new ApiException('加入房间失败，请重试');
        }
    }

    /**
     * 统一金币数值为 2 位精度字符串，便于 bccomp 比较
     */
    private function normalizePoints($value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 2);
    }

    /**
     * 挂在「目标房以外」且无进行中牌局的成员行：解散旧房，避免重登后无法开房。
     */
    private function releaseIdleMembershipIfNeeded(int $uid, int $targetRoomId): void
    {
        if ($uid <= 0) {
            return;
        }
        $existing = $this->roomMemberService->getActiveByPlayer($uid);
        if ($existing === null) {
            return;
        }
        $oldRoomId = (int) ($existing->room_id ?? 0);
        if ($oldRoomId <= 0 || $oldRoomId === $targetRoomId) {
            return;
        }
        $oldPlaying = make(GameRoundService::class)->getOne(
            [
                'room_id' => $oldRoomId,
                'status'  => GameRound::STATUS_PLAYING,
            ],
            'id'
        );
        if ($oldPlaying !== null) {
            return;
        }
        $this->roomTimeoutService->dissolveRoom(
            $oldRoomId,
            '无人继续对局，原房间已关闭'
        );
    }
}
