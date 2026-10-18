<?php

declare(strict_types=1);

namespace App\Game\Niu;

/**
 * 斗牛 Module 内共享常量
 *
 * 阶段流转：grab（抢庄）→ bet（闲家下注）→ show（亮牌结算）
 * 阶段字符串写入 game_round.extra JSON，见 {@see RoundPhase}
 */
final class NiuConsts
{
    /** 抢庄阶段：全员并行报倍数，尚未发牌 */
    public const PHASE_GRAB = 'grab';

    /** 下注阶段：仅闲家下注，庄家跳过 */
    public const PHASE_BET = 'bet';

    /** 亮牌阶段：手牌已发，等待 Show 开牌结算 */
    public const PHASE_SHOW = 'show';

    /**
     * 玩法硬上限（去掉大小王 52 张，每人 5 张 → 最多 10 人）
     * 实际开局人数取房间 room.num，且不得超过本值
     */
    public const MAX_PLAYERS = 10;

    /** 最少开局人数（庄 + 至少 1 闲） */
    public const MIN_PLAYERS = 2;

    /** 每人最终手牌张数 */
    public const CARDS_PER_PLAYER = 5;

    /** 抢庄可选倍数：0=不抢，1/2/3/5=叫分（最高当庄，并列随机） */
    public const GRAB_MULTIPLES = [0, 1, 2, 3, 5];

    /** 闲家下注可选倍数（相对房间底分 bottom_points） */
    public const BET_MULTIPLES = [1, 2, 3, 5];

    /** 各阶段默认超时（秒）；超时由 NiuTimeoutHandler 代操作 */
    public const GRAB_TIMEOUT = 10;
    public const BET_TIMEOUT = 15;
    public const SHOW_TIMEOUT = 10;
}
