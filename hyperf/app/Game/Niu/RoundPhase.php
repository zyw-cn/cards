<?php

declare(strict_types=1);

namespace App\Game\Niu;

/**
 * 局内阶段读写（存于 game_round.extra）
 *
 * 格式示例：
 *   {"phase":"grab","current_player_id":1}
 *   {"phase":"show","deal_step":5,"shown_ids":[101,102]}
 *
 * 与斗地主不同：斗牛在发牌前就进入 STATUS_PLAYING，阶段靠 extra 区分，
 * 不能仅用 master_id 判断（下注后 master_id 已有，但仍可能未发牌）。
 */
final class RoundPhase
{
    /**
     * 解析 extra；非法或空串时回退到抢庄阶段
     *
     * @return array{
     *     phase: string,
     *     deal_step?: int,
     *     current_player_id?: int,
     *     current_seat?: int,
     *     shown_ids?: list<int>
     * }
     */
    public static function decode(?string $extra): array
    {
        $raw = trim((string) $extra);
        if ($raw === '' || ($raw[0] ?? '') !== '{') {
            return ['phase' => NiuConsts::PHASE_GRAB];
        }
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return ['phase' => NiuConsts::PHASE_GRAB];
        }
        $phase = (string) ($data['phase'] ?? NiuConsts::PHASE_GRAB);
        if (! in_array($phase, [NiuConsts::PHASE_GRAB, NiuConsts::PHASE_BET, NiuConsts::PHASE_SHOW], true)) {
            $phase = NiuConsts::PHASE_GRAB;
        }
        $out = ['phase' => $phase];
        if (isset($data['deal_step'])) {
            $out['deal_step'] = (int) $data['deal_step'];
        }
        if (isset($data['current_player_id'])) {
            $out['current_player_id'] = (int) $data['current_player_id'];
        }
        if (isset($data['current_seat'])) {
            $out['current_seat'] = (int) $data['current_seat'];
        }
        if (isset($data['shown_ids']) && is_array($data['shown_ids'])) {
            $ids = [];
            foreach ($data['shown_ids'] as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $out['shown_ids'] = array_values(array_unique($ids));
        }

        return $out;
    }

    /**
     * 编码写入 round.extra
     *
     * @param list<int>|null $shownIds 亮牌阶段已开牌玩家
     */
    public static function encode(
        string $phase,
        ?int $dealStep = null,
        ?int $currentPlayerId = null,
        ?int $currentSeat = null,
        ?array $shownIds = null
    ): string {
        $data = ['phase' => $phase];
        if ($dealStep !== null) {
            $data['deal_step'] = $dealStep;
        }
        if ($currentPlayerId !== null) {
            $data['current_player_id'] = $currentPlayerId;
        }
        if ($currentSeat !== null) {
            $data['current_seat'] = $currentSeat;
        }
        if ($shownIds !== null) {
            $ids = [];
            foreach ($shownIds as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $data['shown_ids'] = array_values(array_unique($ids));
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{"phase":"grab"}';
    }

    /** 快捷读取当前阶段名 */
    public static function phaseOf(?string $extra): string
    {
        return self::decode($extra)['phase'];
    }

    /**
     * @return list<int>
     */
    public static function shownIdsOf(?string $extra): array
    {
        $ids = self::decode($extra)['shown_ids'] ?? [];

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }
}
