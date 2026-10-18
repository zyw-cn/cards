<?php

declare(strict_types=1);

namespace App\Game\Lz;

/**
 * 癞子斗地主局内扩展状态（存 game_round.extra JSON）
 */
final class RoundState
{
    /**
     * @return array{
     *   phase: string,
     *   askMode: int,
     *   grabTurn: int,
     *   grabs: list<array{seat:int,player_id:int,score:int}>,
     *   farmerDoubles: array<string,int>, // 各座位加倍选择（含地主 seat）
     *   doubleTurn: int, // 兼容字段；同时加倍阶段固定 -1
     *   doubleDeadlineAt: int, // 加倍截止 unix 时间戳
     *   bombCount: int, // 本局已出炸弹/王炸次数（桌面倍数用）
     *   remain: list<string>,
     *   landlordSeat: int,
     *   landlordMultiple: int
     * }
     */
    /**
     * @param array<string,mixed>|string|null $raw
     */
    public static function decode(array|string|null $raw): array
    {
        $defaults = [
            'phase'            => LzConsts::PHASE_GRAB,
            'askMode'          => LzConsts::ASK_GRAB,
            'grabTurn'         => 0,
            'grabs'            => [],
            'farmerDoubles'    => [],
            'doubleTurn'       => -1,
            'bombCount'        => 0,
            'remain'           => [],
            'landlordSeat'     => -1,
            'landlordMultiple' => 1,
        ];

        if (is_array($raw)) {
            return array_merge($defaults, $raw);
        }

        $raw = trim((string) $raw);
        if ($raw === '') {
            return $defaults;
        }

        // 兼容旧格式：纯底牌 CSV
        if ($raw[0] !== '{' && $raw[0] !== '[') {
            $cards = array_values(array_filter(array_map('trim', explode(',', $raw))));
            $defaults['phase'] = LzConsts::PHASE_PLAY;
            $defaults['remain'] = $cards;

            return $defaults;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            // varchar(255) 历史截断：尽量保住 phase / remain，避免误判阶段
            return self::decodeTruncated($raw, $defaults);
        }

        return array_merge($defaults, $decoded);
    }

    /**
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    private static function decodeTruncated(string $raw, array $defaults): array
    {
        if (preg_match('/"phase"\s*:\s*"([^"]+)"/', $raw, $m)) {
            $defaults['phase'] = (string) $m[1];
        }
        if (preg_match('/"askMode"\s*:\s*(\d+)/', $raw, $m)) {
            $defaults['askMode'] = (int) $m[1];
        }
        if (preg_match('/"grabTurn"\s*:\s*(\d+)/', $raw, $m)) {
            $defaults['grabTurn'] = (int) $m[1];
        }
        if (preg_match('/"landlordSeat"\s*:\s*(-?\d+)/', $raw, $m)) {
            $defaults['landlordSeat'] = (int) $m[1];
        }
        if (preg_match('/"landlordMultiple"\s*:\s*(\d+)/', $raw, $m)) {
            $defaults['landlordMultiple'] = max(1, (int) $m[1]);
        }
        if (preg_match('/"remain"\s*:\s*(\[[^\]]*\])/', $raw, $m)) {
            $remain = json_decode($m[1], true);
            if (is_array($remain)) {
                $defaults['remain'] = array_values($remain);
            }
        }
        if (preg_match('/"grabs"\s*:\s*(\[.*)/', $raw, $m)) {
            $grabsRaw = $m[1];
            // 截断时可能缺右括号，尝试补全后解析
            for ($i = 0; $i < 3; $i++) {
                $try = $grabsRaw . str_repeat(']', $i);
                $end = strpos($try, '],"farmerDoubles"');
                if ($end !== false) {
                    $try = substr($try, 0, $end + 1);
                }
                $grabs = json_decode($try, true);
                if (is_array($grabs)) {
                    $defaults['grabs'] = array_values($grabs);
                    break;
                }
            }
        }

        return $defaults;
    }

    public static function encode(array $state): string
    {
        return (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function askModeFromRule(?string $rule): int
    {
        $data = json_decode((string) $rule, true);
        if (! is_array($data)) {
            return LzConsts::ASK_GRAB;
        }
        $mode = (int) ($data['ask_mode'] ?? $data['askMode'] ?? LzConsts::ASK_GRAB);

        return in_array($mode, [LzConsts::ASK_ROTATE, LzConsts::ASK_GRAB, LzConsts::ASK_WINNER], true)
            ? $mode
            : LzConsts::ASK_GRAB;
    }

    /**
     * 每局开局从房间 rule 重新解析（同房间规则不变，但每局都读库内 JSON，不用上局 extra 缓存）
     *
     * @return array{askMode: int, rule: string, ruleObj: array<string, mixed>}
     */
    public static function rulePayloadFromRule(?string $rule): array
    {
        $ruleStr = (string) ($rule ?? '');
        $obj = json_decode($ruleStr, true);
        if (! is_array($obj)) {
            $obj = [];
        }

        return [
            'askMode' => self::askModeFromRule($ruleStr !== '' ? $ruleStr : null),
            'rule'    => $ruleStr,
            'ruleObj' => $obj,
        ];
    }
}
