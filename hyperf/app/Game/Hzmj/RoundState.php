<?php

declare(strict_types=1);

namespace App\Game\Hzmj;

/**
 * 红中麻将局内扩展状态（存 game_round.extra JSON）
 */
final class RoundState
{
    /**
     * @param array<string,mixed>|string|null $raw
     * @return array{
     *   phase: string,
     *   dealerSeat: int,
     *   curSeat: int,
     *   kingTiles: list<int>,
     *   tileWall: list<int>,
     *   discards: array<string, list<int>>,
     *   ups: array<string, list<list<int>>>,
     *   pendingDiscard: array{tile:int,fromSeat:int}|null,
     *   waitOps: array<string, list<int>>,
     *   luckyNum: int,
     *   maxPlayers: int,
     *   diceList: list<list<int>>
     * }
     */
    public static function decode(array|string|null $raw): array
    {
        $defaults = [
            'phase'          => HzmjConsts::PHASE_PLAYING,
            'dealerSeat'     => 0,
            'curSeat'        => 0,
            'kingTiles'      => [HzmjConsts::KING_TILE],
            'tileWall'       => [],
            'discards'       => [],
            'ups'            => [],
            'pendingDiscard' => null,
            'waitOps'        => [],
            'luckyNum'       => 2,
            'maxPlayers'     => HzmjConsts::MAX_PLAYERS,
            /** @var array{tile:int,fromSeat:int}|null 续杠待抢杠胡 */
            'pendingKongWin' => null,
            /** @var array<string, int> 座位累计分 */
            'totalScores'    => [],
            /** 开局不再掷骰；保留字段兼容旧局 */
            'diceList'       => [],
            /** @var array<string, array{seat:int,multiple:int,source:int}> pid => grab */
            'grabs'          => [],
            'bankBeishu'     => 1,
            'df'             => 1.0,
        ];

        if (is_array($raw)) {
            return array_merge($defaults, $raw);
        }

        $raw = trim((string) $raw);
        if ($raw === '') {
            return $defaults;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $defaults;
        }

        return array_merge($defaults, $decoded);
    }

    public static function encode(array $state): string
    {
        return (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{rule: string, ruleObj: array<string, mixed>}
     */
    public static function rulePayloadFromRule(?string $rule): array
    {
        $ruleStr = (string) ($rule ?? '');
        $obj = json_decode($ruleStr, true);
        if (! is_array($obj)) {
            $obj = [];
        }

        return [
            'rule'    => $ruleStr,
            'ruleObj' => $obj,
        ];
    }

    public static function luckyNumFromRule(?string $rule): int
    {
        $data = json_decode((string) $rule, true);
        if (! is_array($data)) {
            return 2;
        }
        $n = (int) ($data['lucky_num'] ?? $data['luckyNum'] ?? 2);

        return $n > 0 ? $n : 2;
    }

    /** 底分：兼容 df / bottom_points / ante */
    public static function dfFromRule(?string $rule): float
    {
        $data = json_decode((string) $rule, true);
        if (! is_array($data)) {
            return 1.0;
        }
        $raw = $data['df'] ?? $data['bottom_points'] ?? $data['ante'] ?? 1;
        $n = (float) $raw;

        return $n > 0 ? $n : 1.0;
    }

    /**
     * 结算分 × 底分 × 庄倍数（庄倍数至少 1）
     *
     * @param array<string, int|float> $deltas
     * @return array<string, int>
     */
    public static function scaleScoreDeltas(array $deltas, float $df, int $bankBeishu): array
    {
        $df = $df > 0 ? $df : 1.0;
        $mult = max(1, $bankBeishu);
        $factor = $df * $mult;
        $out = [];
        foreach ($deltas as $k => $v) {
            $out[(string) $k] = (int) round((float) $v * $factor);
        }

        return $out;
    }

    /** 所需人数：取房间 num，钳制 2~4 */
    public static function requiredPlayers(int $roomNum): int
    {
        return max(HzmjConsts::MIN_PLAYERS, min(HzmjConsts::MAX_PLAYERS, $roomNum));
    }
}
