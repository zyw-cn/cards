<?php

declare(strict_types=1);

namespace App\Game\Pdk;

/**
 * 跑得快 Module 常量（经典三人 16 张、红桃 3 先出）
 *
 * 牌副：52 张去掉方片 A、红桃 2、梅花 2、方片 2（留黑桃 2）→ 48 张，每人 16。
 * 出牌：强制管牌（能压必须压）。
 * 结算：炸弹/三个A 打出时当场结算，其余两人分别各付固定炸弹分（默认 10 分）；局末仅算剩余张数。
 */
final class PdkConsts
{
    /** @deprecated 已取消加倍阶段，保留常量兼容旧数据 */
    public const PHASE_DOUBLE = 'double';

    /** 出牌阶段 */
    public const PHASE_PLAY = 'play';

    public const MAX_PLAYERS = 3;

    public const HAND_SIZE = 16;

    /** @deprecated 已取消加倍 */
    public const DOUBLE_SCORES = [0, 2];

    /** 红桃 3（持红桃3者先出，不必带出）。编码 B=红桃 */
    public const SPADE_THREE = 'B3';

    /**
     * 发牌时剔除的牌（编码：A♠ B♥ C♣ D♦）
     * 留黑桃 2(A15)，去掉方片 A + 其余三张 2
     */
    public const REMOVED_CARDS = ['D14', 'B15', 'C15', 'D15'];

    /** 炸弹固定分：每位对手付本值；房间 rule.bomb_multiple 可覆盖 */
    public const BOMB_SCORE = 10;

    /** @deprecated 使用 BOMB_SCORE */
    public const BOMB_SCORE_MULTIPLE = 10;
}
