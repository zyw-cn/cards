<?php

declare(strict_types=1);

namespace App\Crontab;

use App\Facades\Log;
use App\Game\Platform\DismissVoteService;
use App\Game\Platform\Timeout\RoomTimeoutService;
use App\Model\Game\GameRoom;
use App\Model\Game\GameRound;
use App\Model\Game\RoomMember;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\DbConnection\Db;
use function Hyperf\Support\make;

/**
 * 空房 / 局间扫房（每分钟）：
 * 1) 未开过局：创建后等人（如 2/3）超过 3 分钟仍未开打 → 解散并退房卡
 * 2) 已打过局：上一局结束、下一局未开打超过 3 分钟 → 解散
 *
 * 对局进行中（存在 status=PLAYING 的 round）不处理。
 */
class InterRoundIdleDissolveCrontab
{
    /** 空等最长秒数 */
    private const IDLE_SECONDS = 180;

    /** 每轮最多处理房间数 */
    private const BATCH_LIMIT = 50;

    #[Crontab(
        rule: '* * * * *',
        name: 'InterRoundIdleDissolve',
        singleton: true,
        onOneServer: true,
        mutexExpires: 120,
        memo: '未开局等人/局间空等超过3分钟则解散',
    )]
    public function execute(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::IDLE_SECONDS);

        try {
            $targets = $this->findIdleRooms($cutoff);
        } catch (\Throwable $e) {
            Log::error('空房扫房查询失败', ['error' => $e->getMessage()]);

            return;
        }

        if ($targets === []) {
            return;
        }

        $timeout = make(RoomTimeoutService::class);
        $dismiss = make(DismissVoteService::class);

        foreach ($targets as $item) {
            $roomId = (int) $item['id'];
            $reason = (string) $item['reason'];
            try {
                if ($dismiss->get($roomId) !== null) {
                    continue;
                }
                if ($this->hasPlayingRound($roomId)) {
                    continue;
                }
                // 解散前再判一次，避免刚开局被误散
                $again = $this->classifyIdle($roomId, $cutoff);
                if ($again === null) {
                    continue;
                }
                $timeout->dissolveRoom($roomId, $again['reason']);
                Log::info('空房超时解散', [
                    'room_id' => $roomId,
                    'kind'    => $again['kind'],
                    'cutoff'  => $cutoff,
                ]);
            } catch (\Throwable $e) {
                Log::error('空房超时解散失败', [
                    'room_id' => $roomId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return list<array{id:int, kind:string, reason:string}>
     */
    private function findIdleRooms(string $cutoff): array
    {
        $candidates = Db::table('room')
            ->whereIn('status', GameRoom::activeStatuses())
            ->orderBy('id')
            ->limit(300)
            ->get(['id', 'status', 'played_count', 'create_time', 'update_time']);

        $out = [];
        foreach ($candidates as $row) {
            $roomId = (int) ($row->id ?? 0);
            if ($roomId <= 0) {
                continue;
            }
            if ($this->hasPlayingRound($roomId)) {
                continue;
            }
            $hit = $this->classifyIdleRow($row, $cutoff);
            if ($hit === null) {
                continue;
            }
            $out[] = $hit;
            if (count($out) >= self::BATCH_LIMIT) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array{id:int, kind:string, reason:string}|null
     */
    private function classifyIdle(int $roomId, string $cutoff): ?array
    {
        $row = Db::table('room')->where('id', $roomId)->first([
            'id', 'status', 'played_count', 'create_time', 'update_time',
        ]);
        if ($row === null) {
            return null;
        }

        return $this->classifyIdleRow($row, $cutoff);
    }

    /**
     * @param object{id:mixed,status:mixed,played_count:mixed,create_time?:mixed,update_time?:mixed} $row
     * @return array{id:int, kind:string, reason:string}|null
     */
    private function classifyIdleRow(object $row, string $cutoff): ?array
    {
        $roomId = (int) ($row->id ?? 0);
        $status = (int) ($row->status ?? 0);
        $played = (int) ($row->played_count ?? 0);

        if (! GameRoom::isActiveStatus($status)) {
            return null;
        }

        // ① 未开过局：等人凑桌 / 人齐不点开始
        if ($played <= 0) {
            $lastActive = $this->preStartLastActiveAt($roomId, $row);
            if ($lastActive === null || $lastActive > $cutoff) {
                return null;
            }

            return [
                'id'     => $roomId,
                'kind'   => 'pre_start',
                'reason' => '等待开局超过3分钟，房间已解散',
            ];
        }

        // ② 已打过局：局间不点开始
        $lastEnd = Db::table('game_round')
            ->where('room_id', $roomId)
            ->where('status', GameRound::STATUS_ENDED)
            ->selectRaw('MAX(COALESCE(end_time, update_at, create_at)) AS last_end')
            ->value('last_end');
        if ($lastEnd === null || $lastEnd === '' || (string) $lastEnd > $cutoff) {
            return null;
        }

        return [
            'id'     => $roomId,
            'kind'   => 'inter_round',
            'reason' => '局间等待超过3分钟未开始下一局，房间已解散',
        ];
    }

    /**
     * 未开局活跃时间：建房 / 房间更新 / 最后有人入座，取最晚。
     * 有人新进来会刷新，避免刚凑到第 2 人就被上一波时钟误散。
     *
     * @param object{create_time?:mixed,update_time?:mixed} $row
     */
    private function preStartLastActiveAt(int $roomId, object $row): ?string
    {
        $times = [];
        foreach ([(string) ($row->create_time ?? ''), (string) ($row->update_time ?? '')] as $t) {
            if ($t !== '') {
                $times[] = $t;
            }
        }

        $lastJoin = Db::table('room_member')
            ->where('room_id', $roomId)
            ->where('status', '<', RoomMember::STATUS_ENDED)
            ->max('join_time');
        if ($lastJoin !== null && (string) $lastJoin !== '') {
            $times[] = (string) $lastJoin;
        }

        if ($times === []) {
            return null;
        }

        return max($times);
    }

    private function hasPlayingRound(int $roomId): bool
    {
        return Db::table('game_round')
            ->where('room_id', $roomId)
            ->where('status', GameRound::STATUS_PLAYING)
            ->exists();
    }
}
