<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dao\Game\NiuGrabDao;
use App\Model\Game\NiuGrab;
use App\Service\BaseService;

/**
 * 斗牛抢庄记录服务（表 niu_grab，前缀 h_）
 *
 * 职责：
 * - 读写每人每局一条的抢庄记录
 * - decideBanker：根据全员倍数决出庄家（含「全不抢 → 明庄」）
 *
 * 定庄结果由 GrabBankerAction 写回 game_round.master_id / multiple。
 */
class NiuGrabService extends BaseService
{
    public function __construct(NiuGrabDao $niuGrabDao)
    {
        $this->dao = $niuGrabDao;
    }

    /**
     * 加载本局全部抢庄记录（按座位升序）
     *
     * @return list<array{seat:int, player_id:int, multiple:int, source:int, is_auto:int}>
     */
    public function loadByRound(int $roundId): array
    {
        $rows = $this->getAll(
            ['game_round_id' => $roundId],
            'seat_no,member_id,multiple,source,is_auto',
            ['seat_no' => 'asc']
        );
        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'seat'      => (int) $r['seat_no'],
                'player_id' => (int) $r['member_id'],
                'multiple'  => (int) $r['multiple'],
                'source'    => (int) ($r['source'] ?? NiuGrab::SOURCE_PLAYER),
                'is_auto'   => (int) ($r['is_auto'] ?? 0),
            ];
        }

        return $list;
    }

    /**
     * 该玩家本局是否已提交过抢庄（含「不抢」）
     * 用于防重复提交；表上另有 uk_round_member 兜底
     */
    public function hasGrabbed(int $roundId, int $memberId): bool
    {
        return $this->be([
            'game_round_id' => $roundId,
            'member_id'     => $memberId,
        ]);
    }

    /**
     * 根据抢庄列表决出庄家
     *
     * 规则：
     * 1. 取 multiple 最大者当庄；多人并列则随机其一（is_system=false）
     * 2. 全员 multiple=0（都不抢）：随机指定一人为「明庄」
     *    - is_system=true
     *    - multiple 使用 $systemMultiple（默认 1，保证结算有倍数）
     *    - 调用方需把该行 source 回写为 SOURCE_SYSTEM
     *
     * @param list<array{seat:int, player_id:int, multiple:int}> $grabs
     * @return array{
     *   seat:int, player_id:int, multiple:int, is_system:bool,
     *   need_random:bool, candidates:list<array{seat:int, player_id:int, multiple:int}>
     * }|null
     */
    public function decideBanker(array $grabs, int $systemMultiple = 1): ?array
    {
        if ($grabs === []) {
            return null;
        }

        // 找出本局出现过的最高抢庄倍数
        $max = 0;
        foreach ($grabs as $g) {
            $m = (int) ($g['multiple'] ?? 0);
            if ($m > $max) {
                $max = $m;
            }
        }

        // 全员不抢 → 系统明庄（所有人参与随机）
        if ($max <= 0) {
            $candidates = array_values(array_map(static fn (array $g): array => [
                'seat'      => (int) $g['seat'],
                'player_id' => (int) $g['player_id'],
                'multiple'  => 0,
            ], $grabs));
            $pick = self::pickCandidate($candidates);

            return [
                'seat'        => (int) $pick['seat'],
                'player_id'   => (int) $pick['player_id'],
                'multiple'    => max(1, $systemMultiple),
                'is_system'   => true,
                'need_random' => count($candidates) > 1,
                'candidates'  => $candidates,
            ];
        }

        // 最高倍数可能多人并列 → 随机抽一个
        $candidates = array_values(array_map(
            static fn (array $g): array => [
                'seat'      => (int) $g['seat'],
                'player_id' => (int) $g['player_id'],
                'multiple'  => (int) $g['multiple'],
            ],
            array_filter(
                $grabs,
                static fn (array $g): bool => (int) ($g['multiple'] ?? 0) === $max
            )
        ));
        $pick = self::pickCandidate($candidates);

        return [
            'seat'        => (int) $pick['seat'],
            'player_id'   => (int) $pick['player_id'],
            'multiple'    => $max,
            'is_system'   => false,
            'need_random' => count($candidates) > 1,
            'candidates'  => $candidates,
        ];
    }

    /**
     * 并列候选人里均匀随机抽一个。下标 0 是最先抢庄的人，不能固定取他。
     *
     * @param list<array{seat:int, player_id:int, multiple:int}> $candidates
     * @return array{seat:int, player_id:int, multiple:int}
     */
    private static function pickCandidate(array $candidates): array
    {
        $n = count($candidates);
        if ($n <= 1) {
            return $candidates[0];
        }

        return $candidates[random_int(0, $n - 1)];
    }
}
