<?php

declare(strict_types=1);

namespace App\Game\Dou;

/** 斗地主 Module 内共享常量 */
final class DouConsts
{
    /** 叫地主 / 抢地主阶段（已发手牌，底牌未归人） */
    public const PHASE_GRAB = 'grab';

    /** 加倍阶段（对齐 QQ）：定庄亮底牌后三家同时选加倍，超时默认不加倍，再进入出牌 */
    public const PHASE_DOUBLE = 'double';

    /** 加倍阶段每人可选时长（秒）；到点未选视为不加倍 */
    public const DOUBLE_TIMEOUT = 10;

    /** 兼容旧叫抢阶段名 */
    public const PHASE_BID = 'bid';

    /** 出牌阶段 */
    public const PHASE_PLAY = 'play';

    public const MAX_PLAYERS = 3;

    /** 规则：轮流地主 */
    public const ASK_ROTATE = 1;

    /** 规则：抢庄地主（发牌后叫地主/抢地主） */
    public const ASK_GRAB = 2;

    /** 规则：胜者连庄 */
    public const ASK_WINNER = 3;

    /**
     * @deprecated 已改为叫/抢二元模式，保留兼容旧前端
     * 0=不叫/不抢，1=叫地主；抢地主用 maxBid+1
     */
    public const GRAB_SCORES = [0, 1];

    /** 加倍可选：0=不加倍（结算按 1），2=加倍（×2） */
    public const DOUBLE_SCORES = [0, 2];

    /** 方片 3（先出标记） */
    public const DIAMOND_THREE = 'D3';
}
