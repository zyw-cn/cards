<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * 房间 game_type 枚举（与 room.game_type 一致）
 *
 * 与 Module code 的映射写在各 GameModule 内（如 DouModule：1 ↔ Dou）
 */
class GameType
{
    /** 斗地主（Module: Dou） */
    public const LANDLORD = 1;

    /** 斗牛（Module: Niu） */
    public const NIU = 2;

    /** 炸金花（Module: Zjh） */
    public const ZJH = 3;

    /** 跑得快（Module: Pdk）——经典三人 16 张、黑桃 3 先出 */
    public const PAODEKUAI = 4;

    /** 癞子斗地主（Module: Lz）——54+癞子=55 张，每人 17、底牌 4 */
    public const LAIZI_LANDLORD = 5;

    /** 红中麻将（Module: Hzmj）——红中为癞子，不可吃/不可点炮 */
    public const HZMJ = 7;
}
