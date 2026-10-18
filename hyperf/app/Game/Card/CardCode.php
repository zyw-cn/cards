<?php

declare(strict_types=1);

namespace App\Game\Card;

/**
 * 扑克编码归一：切图码 A1/A2/JS/JB → 服务端 A14/A15/E16/E17
 *
 * 服务端约定：A/B/C/D + 3~15（14=A，15=2），E16/E17 王，F55 癞子。
 * 兼容旧癞子码 W55 / LAIZI / 55。
 */
final class CardCode
{
    public static function normalize(string|int $card): string
    {
        if (is_int($card)) {
            if ($card === 55) {
                return 'F55';
            }
            if ($card === 16) {
                return 'E16';
            }
            if ($card === 17) {
                return 'E17';
            }

            return (string) $card;
        }

        $c = strtoupper(trim($card));
        if ($c === '') {
            return '';
        }
        if (in_array($c, ['F55', 'F', 'W55', 'LAIZI', '55'], true)) {
            return 'F55';
        }
        if ($c === 'JS' || $c === 'SJ' || $c === 'E16') {
            return 'E16';
        }
        if ($c === 'JB' || $c === 'BJ' || $c === 'E17') {
            return 'E17';
        }

        $suit = $c[0];
        if (! in_array($suit, ['A', 'B', 'C', 'D', 'E'], true)) {
            return $c;
        }
        if ($suit === 'E') {
            return $c;
        }

        $point = (int) substr($c, 1);
        if ($point === 1) {
            $point = 14;
        } elseif ($point === 2) {
            $point = 15;
        }
        if ($point <= 0) {
            return $c;
        }

        return $suit . $point;
    }
}
