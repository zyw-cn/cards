<?php

declare(strict_types=1);

namespace App\Game\Pdk;

/**
 * 跑得快局内扩展状态（存 game_round.extra JSON）
 */
final class RoundState
{
    /**
     * @param array<string,mixed>|string|null $raw
     * @return array{
     *   phase: string,
     *   firstSeat: int,
     *   bombCount: int,
     *   bombs: list<int>,
     *   spade3Played: bool,
     *   doubles: array<string, int>,
     *   doubleTurn: int,
     *   surrendered: list<int>
     * }
     */
    public static function decode(array|string|null $raw): array
    {
        $defaults = [
            'phase'        => PdkConsts::PHASE_PLAY,
            'firstSeat'    => 0,
            'bombCount'    => 0,
            'bombs'        => [],
            'spade3Played' => false,
            'doubles'      => [],
            'doubleTurn'   => -1,
            'surrendered'  => [],
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

    /** 炸弹固定分：每位对手付本值；房间 rule.bomb_multiple / bomb_score 可覆盖 */
    public static function bombScoreFromRule(?string $rule): int
    {
        $data = json_decode((string) $rule, true);
        if (! is_array($data)) {
            return PdkConsts::BOMB_SCORE;
        }
        $score = (int) ($data['bomb_multiple'] ?? $data['bomb_score'] ?? $data['bombMultiple'] ?? 0);

        return $score > 0 ? $score : PdkConsts::BOMB_SCORE;
    }

    /** @deprecated 使用 bombScoreFromRule */
    public static function bombMultipleFromRule(?string $rule): int
    {
        return self::bombScoreFromRule($rule);
    }
}
