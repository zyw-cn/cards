<?php

declare(strict_types=1);

namespace App\Game\Zjh;

/**
 * 炸金花 Action 共用组包（公开桌面快照，不含手牌）
 */
final class Support
{
    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function publicTable(array $state, string $roomNum, int $roundId, ?string $rule = null): array
    {
        $seats = [];
        foreach ($state['seatPids'] ?? [] as $seatKey => $pid) {
            $seat = (int) $seatKey;
            $seats[] = [
                'seatNo'   => $seat,
                'playerId' => (int) $pid,
                'looked'   => RoundState::isLooked($state, $seat),
                'folded'   => RoundState::isFolded($state, $seat),
                'invested' => (float) ($state['invested'][(string) $seat] ?? 0),
            ];
        }
        usort($seats, static fn (array $a, array $b): int => $a['seatNo'] <=> $b['seatNo']);

        $caps = ZjhConsts::capsFromRule($rule);

        return [
            'roomId'       => $roomNum,
            'roundId'      => $roundId,
            'phase'        => (string) ($state['phase'] ?? ZjhConsts::PHASE_BET),
            'pot'          => (float) ($state['pot'] ?? 0),
            'unit'         => (float) ($state['unit'] ?? 0),
            'turnSeat'     => (int) ($state['turnSeat'] ?? 0),
            'dealerSeat'   => (int) ($state['dealerSeat'] ?? 0),
            'seats'        => $seats,
            'selectPoints' => array_map('floatval', $caps['select_points']),
            'menMax'       => (float) $caps['men_max'],
            'lookMax'      => (float) $caps['look_max'],
            'maxFollow'    => (float) $caps['look_max'],
        ];
    }
}
