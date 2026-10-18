<?php

declare(strict_types=1);

namespace App\Game\Zjh;

/**
 * 炸金花局内桌面状态（存于 game_round.extra JSON）
 *
 * 示例：
 * {
 *   "phase":"bet","pot":"30.00","ante":"1.00","unit":"5.00","turnSeat":1,"dealerSeat":0,
 *   "looked":[1],"folded":[],"compared":false,
 *   "invested":{"0":"10.00","1":"10.00"},
 *   "spend":{"0":{"ante":"1.00","men":"2.00","call":"0.00","raise":"0.00","compare":"0.00"}},
 *   "seatPids":{"0":101,"1":102}
 * }
 */
final class RoundState
{
    /**
     * @return array{
     *     phase:string, pot:string, ante:string, unit:string, turnSeat:int, dealerSeat:int,
     *     looked:list<int>, folded:list<int>, compared:bool,
     *     invested:array<string,string>,
     *     spend:array<string,array<string,string>>,
     *     seatPids:array<string,int>
     * }
     */
    public static function decode(?string $extra): array
    {
        $empty = [
            'phase'      => ZjhConsts::PHASE_BET,
            'pot'        => '0.00',
            'ante'       => '0.00',
            'unit'       => '0.00',
            'turnSeat'   => 0,
            'dealerSeat' => 0,
            'looked'     => [],
            'folded'     => [],
            'compared'   => false,
            'invested'   => [],
            'spend'      => [],
            'seatPids'   => [],
        ];
        $raw = trim((string) $extra);
        if ($raw === '' || ($raw[0] ?? '') !== '{') {
            return $empty;
        }
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return $empty;
        }

        $phase = (string) ($data['phase'] ?? ZjhConsts::PHASE_BET);
        if (! in_array($phase, [ZjhConsts::PHASE_BET, ZjhConsts::PHASE_SETTLE], true)) {
            $phase = ZjhConsts::PHASE_BET;
        }

        $looked = array_values(array_unique(array_map('intval', (array) ($data['looked'] ?? []))));
        $folded = array_values(array_unique(array_map('intval', (array) ($data['folded'] ?? []))));

        $invested = [];
        foreach ((array) ($data['invested'] ?? []) as $k => $v) {
            $invested[(string) (int) $k] = bcadd((string) $v, '0', 2);
        }
        $spend = [];
        foreach ((array) ($data['spend'] ?? []) as $k => $row) {
            if (! is_array($row)) {
                continue;
            }
            $seatKey = (string) (int) $k;
            $item = [];
            foreach (['ante', 'men', 'call', 'raise', 'compare'] as $kind) {
                $item[$kind] = bcadd((string) ($row[$kind] ?? '0'), '0', 2);
            }
            $spend[$seatKey] = $item;
        }
        $seatPids = [];
        foreach ((array) ($data['seatPids'] ?? []) as $k => $v) {
            $seatPids[(string) (int) $k] = (int) $v;
        }

        return [
            'phase'      => $phase,
            'pot'        => bcadd((string) ($data['pot'] ?? '0'), '0', 2),
            'ante'       => bcadd((string) ($data['ante'] ?? '0'), '0', 2),
            'unit'       => bcadd((string) ($data['unit'] ?? '0'), '0', 2),
            'turnSeat'   => (int) ($data['turnSeat'] ?? 0),
            'dealerSeat' => (int) ($data['dealerSeat'] ?? 0),
            'looked'     => $looked,
            'folded'     => $folded,
            'compared'   => ! empty($data['compared']),
            'invested'   => $invested,
            'spend'      => $spend,
            'seatPids'   => $seatPids,
        ];
    }

    /** @param array<string, mixed> $state */
    public static function encode(array $state): string
    {
        $payload = [
            'phase'      => (string) ($state['phase'] ?? ZjhConsts::PHASE_BET),
            'pot'        => bcadd((string) ($state['pot'] ?? '0'), '0', 2),
            'ante'       => bcadd((string) ($state['ante'] ?? '0'), '0', 2),
            'unit'       => bcadd((string) ($state['unit'] ?? '0'), '0', 2),
            'turnSeat'   => (int) ($state['turnSeat'] ?? 0),
            'dealerSeat' => (int) ($state['dealerSeat'] ?? 0),
            'looked'     => array_values(array_unique(array_map('intval', (array) ($state['looked'] ?? [])))),
            'folded'     => array_values(array_unique(array_map('intval', (array) ($state['folded'] ?? [])))),
            'compared'   => ! empty($state['compared']),
            'invested'   => $state['invested'] ?? [],
            'spend'      => $state['spend'] ?? [],
            'seatPids'   => $state['seatPids'] ?? [],
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{"phase":"bet"}';
    }

    public static function phaseOf(?string $extra): string
    {
        return self::decode($extra)['phase'];
    }

    /** @param array<string, mixed> $state */
    public static function isLooked(array $state, int $seat): bool
    {
        return in_array($seat, $state['looked'] ?? [], true);
    }

    /** @param array<string, mixed> $state */
    public static function isFolded(array $state, int $seat): bool
    {
        return in_array($seat, $state['folded'] ?? [], true);
    }

    /**
     * 仍存活座位（未弃牌）
     *
     * @param array<string, mixed> $state
     * @return list<int>
     */
    public static function aliveSeats(array $state): array
    {
        $seats = [];
        foreach (array_keys($state['seatPids'] ?? []) as $k) {
            $seat = (int) $k;
            if (! self::isFolded($state, $seat)) {
                $seats[] = $seat;
            }
        }
        sort($seats);

        return $seats;
    }

    /**
     * 跟注/比牌应付额（比牌与跟注同额，不另收双倍）：
     * - 闷牌：unit
     * - 看牌：select_points 中 ≥ unit×2 的最小档（闷2→5，闷4→10）
     *
     * @param array<string, mixed> $state
     * @param list<string|int|float>|null $selectPoints
     */
    public static function callAmount(array $state, int $seat, ?array $selectPoints = null): string
    {
        $unit = bcadd((string) ($state['unit'] ?? '0'), '0', 2);
        if (self::isLooked($state, $seat)) {
            return ZjhConsts::lookCallFromUnit($unit, $selectPoints);
        }

        return $unit;
    }

    /**
     * 从 fromSeat 的下一家存活座位；若只剩一人返回其座位
     *
     * @param array<string, mixed> $state
     */
    public static function nextAliveSeat(array $state, int $fromSeat): int
    {
        $alive = self::aliveSeats($state);
        if ($alive === []) {
            return $fromSeat;
        }
        if (count($alive) === 1) {
            return $alive[0];
        }
        $ordered = array_values(array_map('intval', array_keys($state['seatPids'] ?? [])));
        sort($ordered);
        $n = count($ordered);
        $idx = array_search($fromSeat, $ordered, true);
        if ($idx === false) {
            return $alive[0];
        }
        for ($i = 1; $i <= $n; $i++) {
            $cand = $ordered[($idx + $i) % $n];
            if (! self::isFolded($state, $cand)) {
                return $cand;
            }
        }

        return $alive[0];
    }

    /** @param array<string, mixed> $state */
    public static function playerIdOf(array $state, int $seat): int
    {
        return (int) ($state['seatPids'][(string) $seat] ?? 0);
    }
}
