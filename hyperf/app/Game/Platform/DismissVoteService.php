<?php

declare(strict_types=1);

namespace App\Game\Platform;

use App\Constants\RedisKey;
use App\Facades\Log;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Service\Game\RoomMemberService;
use Hyperf\Redis\Redis;
use Swoole\Timer;
use function Hyperf\Support\make;

/**
 * 申请解散房间投票（Redis + Swoole Timer，对齐 Cocos ApplyClose ~90s）
 *
 * states: 0=未选 1=同意 2=拒绝
 */
final class DismissVoteService
{
    public const WAIT_SECONDS = 90;

    public const STATE_UNSELECT = 0;

    public const STATE_AGREE = 1;

    public const STATE_REFUSE = 2;

    /** @var array<int, int> room_id => timerId（仅本 worker） */
    private static array $timers = [];

    /**
     * @return array{
     *   fromSeat: int,
     *   fromPid: int,
     *   states: list<int>,
     *   expireAt: int,
     *   playerNum: int,
     *   roomNum: string,
     *   token: string
     * }|null
     */
    public function get(int $roomId): ?array
    {
        if ($roomId <= 0) {
            return null;
        }
        try {
            $raw = make(Redis::class)->get($this->redisKey($roomId));
            if ($raw === false || $raw === null || $raw === '') {
                return null;
            }
            $data = json_decode((string) $raw, true);
            if (! is_array($data) || ! isset($data['states'], $data['expireAt'])) {
                return null;
            }

            return $this->normalize($data);
        } catch (\Throwable $e) {
            Log::error('读取解散投票失败', ['room_id' => $roomId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * 发起投票；若已有投票则视为申请人再投同意（Cocos 行为）
     *
     * timeoutDissolves：超时是否直接解散。红中麻将为 false，必须座位上的人都明确同意。
     * requiredSeats：必须同意的座位；缺省为 0..playerNum-1。
     *
     * @param array{timeoutDissolves?: bool, requiredSeats?: list<int>} $options
     * @return array{ok: bool, message?: string, vote?: array, leftTime?: int, resumed?: bool}
     */
    public function start(
        int $roomId,
        string $roomNum,
        int $fromSeat,
        int $fromPid,
        int $playerNum,
        array $options = [],
    ): array {
        $existing = $this->get($roomId);
        if ($existing !== null) {
            $voteResult = $this->castVote($roomId, $fromSeat, self::STATE_AGREE);
            if (! ($voteResult['ok'] ?? false)) {
                return $voteResult;
            }

            return [
                'ok'       => true,
                'resumed'  => true,
                'vote'     => $voteResult['vote'],
                'leftTime' => $this->leftTime($voteResult['vote']),
                'outcome'  => $voteResult['outcome'] ?? 'continue',
            ];
        }

        $playerNum = max(2, min(10, $playerNum));
        $fromSeat = max(0, min($playerNum - 1, $fromSeat));
        $states = array_fill(0, $playerNum, self::STATE_UNSELECT);
        $states[$fromSeat] = self::STATE_AGREE;
        $requiredSeats = $this->normalizeRequiredSeats($options['requiredSeats'] ?? null, $playerNum);
        $timeoutDissolves = array_key_exists('timeoutDissolves', $options)
            ? (bool) $options['timeoutDissolves']
            : true;
        $token = bin2hex(random_bytes(8));
        $expireAt = time() + self::WAIT_SECONDS;
        $vote = [
            'fromSeat'          => $fromSeat,
            'fromPid'           => $fromPid,
            'states'            => $states,
            'expireAt'          => $expireAt,
            'playerNum'         => $playerNum,
            'roomNum'           => $roomNum,
            'token'             => $token,
            'requiredSeats'     => $requiredSeats,
            'timeoutDissolves'  => $timeoutDissolves,
        ];
        $this->save($roomId, $vote);
        $this->armTimer($roomId, $token, self::WAIT_SECONDS);

        return [
            'ok'       => true,
            'resumed'  => false,
            'vote'     => $vote,
            'leftTime' => self::WAIT_SECONDS,
            'outcome'  => 'continue',
        ];
    }

    /**
     * @return array{ok: bool, message?: string, vote?: array, outcome?: string}
     *         outcome: continue|dissolve|cancel
     */
    public function castVote(int $roomId, int $seat, int $vote): array
    {
        $vote = $vote === self::STATE_REFUSE ? self::STATE_REFUSE : self::STATE_AGREE;
        $data = $this->get($roomId);
        if ($data === null) {
            return ['ok' => false, 'message' => '当前没有解散投票'];
        }
        $playerNum = (int) $data['playerNum'];
        if ($seat < 0 || $seat >= $playerNum) {
            return ['ok' => false, 'message' => '座位无效'];
        }
        $cur = (int) ($data['states'][$seat] ?? self::STATE_UNSELECT);
        if ($cur !== self::STATE_UNSELECT) {
            return ['ok' => false, 'message' => '您已投过票'];
        }

        $data['states'][$seat] = $vote;
        $this->save($roomId, $data);

        if ($vote === self::STATE_REFUSE) {
            $this->clear($roomId);

            return ['ok' => true, 'vote' => $data, 'outcome' => 'cancel'];
        }

        if ($this->allAgreed($data['states'], $data['requiredSeats'] ?? [])) {
            $this->clearTimerOnly($roomId);

            return ['ok' => true, 'vote' => $data, 'outcome' => 'dissolve'];
        }

        return ['ok' => true, 'vote' => $data, 'outcome' => 'continue'];
    }

    public function clear(int $roomId): void
    {
        $this->clearTimerOnly($roomId);
        try {
            make(Redis::class)->del($this->redisKey($roomId));
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $vote
     */
    public function leftTime(array $vote): int
    {
        $left = (int) ($vote['expireAt'] ?? 0) - time();

        return max(0, $left);
    }

    /**
     * Cocos 字段形状
     *
     * @param array<string, mixed> $vote
     * @return array<string, mixed>
     */
    public function clientPayload(array $vote, string $callback): array
    {
        return [
            'callback'            => $callback,
            'roomId'              => (string) ($vote['roomNum'] ?? ''),
            'applyCloseFromSeat'  => (int) ($vote['fromSeat'] ?? 0),
            'applyCloseFrom'      => (int) ($vote['fromSeat'] ?? 0),
            'applyCloseStateList' => array_values(array_map('intval', $vote['states'] ?? [])),
            'applyCloseLeftTime'  => $this->leftTime($vote),
        ];
    }

    /** Timer 到期：默认玩法视为同意并解散；红中麻将未全员同意则取消 */
    public function onTimeout(int $roomId, string $token): void
    {
        unset(self::$timers[$roomId]);
        $data = $this->get($roomId);
        if ($data === null) {
            return;
        }
        if ((string) ($data['token'] ?? '') !== $token) {
            return;
        }

        // 红中麻将：超时不算同意，取消投票，房间继续
        if (empty($data['timeoutDissolves'])) {
            $payload = array_merge(
                $this->clientPayload($data, RoomCallBack::CANCEL_DISMISS),
                ['reason' => '未全部同意，解散已取消']
            );
            $this->clear($roomId);
            $members = make(RoomMemberService::class)->getActiveInRoom($roomId, 'fd,player_id');
            make(RoomWsPusher::class)->pushDataToMembers($members, $payload, 0);
            make(RoomTimeoutService::class)->resumeGameTimeoutAfterDismiss($roomId);

            return;
        }

        $this->clear($roomId);
        $reason = '解散投票超时，房间已解散';
        make(RoomTimeoutService::class)->dissolveRoom($roomId, $reason, 0, [
            'byDismissVote' => true,
            'timeout'       => true,
        ]);
    }

    /**
     * 指定座位全部为同意才解散。requiredSeats 为空时按 states 全员。
     *
     * @param list<int> $states
     * @param list<int> $requiredSeats
     */
    private function allAgreed(array $states, array $requiredSeats = []): bool
    {
        if ($states === []) {
            return false;
        }
        $seats = $requiredSeats !== [] ? $requiredSeats : array_keys($states);
        if ($seats === []) {
            return false;
        }
        foreach ($seats as $seat) {
            if ((int) ($states[(int) $seat] ?? self::STATE_UNSELECT) !== self::STATE_AGREE) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<int>|null $seats
     * @return list<int>
     */
    private function normalizeRequiredSeats(?array $seats, int $playerNum): array
    {
        if ($seats === null || $seats === []) {
            return range(0, $playerNum - 1);
        }
        $out = [];
        foreach ($seats as $seat) {
            $seat = (int) $seat;
            if ($seat >= 0 && $seat < $playerNum) {
                $out[$seat] = $seat;
            }
        }
        $list = array_values($out);

        return $list !== [] ? $list : range(0, $playerNum - 1);
    }

    /**
     * @param array<string, mixed> $vote
     */
    private function save(int $roomId, array $vote): void
    {
        $ttl = max(1, $this->leftTime($vote) + 30);
        try {
            make(Redis::class)->setex(
                $this->redisKey($roomId),
                $ttl,
                json_encode($vote, JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
            Log::error('写入解散投票失败', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        }
    }

    private function armTimer(int $roomId, string $token, int $seconds): void
    {
        $this->clearTimerOnly($roomId);
        $delayMs = max(1, $seconds) * 1000;
        $timerId = Timer::after($delayMs, function () use ($roomId, $token) {
            try {
                $this->onTimeout($roomId, $token);
            } catch (\Throwable $e) {
                Log::error('解散投票超时处理失败', [
                    'room_id' => $roomId,
                    'error'   => $e->getMessage(),
                ]);
            }
        });
        self::$timers[$roomId] = $timerId;
    }

    private function clearTimerOnly(int $roomId): void
    {
        $timerId = self::$timers[$roomId] ?? null;
        if ($timerId !== null) {
            Timer::clear($timerId);
            unset(self::$timers[$roomId]);
        }
    }

    private function redisKey(int $roomId): string
    {
        return sprintf(RedisKey::ROOM_DISMISS_VOTE, $roomId);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   fromSeat: int,
     *   fromPid: int,
     *   states: list<int>,
     *   expireAt: int,
     *   playerNum: int,
     *   roomNum: string,
     *   token: string
     * }
     */
    private function normalize(array $data): array
    {
        $playerNum = max(2, min(10, (int) ($data['playerNum'] ?? 4)));
        $states = array_values(array_map('intval', $data['states'] ?? []));
        while (count($states) < $playerNum) {
            $states[] = self::STATE_UNSELECT;
        }
        if (count($states) > $playerNum) {
            $states = array_slice($states, 0, $playerNum);
        }

        $required = $data['requiredSeats'] ?? null;
        $requiredSeats = is_array($required)
            ? $this->normalizeRequiredSeats($required, $playerNum)
            : range(0, $playerNum - 1);

        return [
            'fromSeat'         => (int) ($data['fromSeat'] ?? 0),
            'fromPid'          => (int) ($data['fromPid'] ?? 0),
            'states'           => $states,
            'expireAt'         => (int) ($data['expireAt'] ?? 0),
            'playerNum'        => $playerNum,
            'roomNum'          => (string) ($data['roomNum'] ?? ''),
            'token'            => (string) ($data['token'] ?? ''),
            'requiredSeats'    => $requiredSeats,
            'timeoutDissolves' => ! array_key_exists('timeoutDissolves', $data) || ! empty($data['timeoutDissolves']),
        ];
    }
}
