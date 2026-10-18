<?php

declare(strict_types=1);

namespace App\Game\Lz;

use App\Game\Platform\RoomCallBack;

/**
 * 癞子斗地主 WebSocket 推送 callback 字段常量
 *
 * 房间级常量与 {@see RoomCallBack} 对齐；玩法专属常量留在本类。
 */
class CallBack
{
    /** @see RoomCallBack::JOIN_ROOM */
    public const JOIN_ROOM = RoomCallBack::JOIN_ROOM;

    /** @see RoomCallBack::ROOM_READY */
    public const ROOM_READY = RoomCallBack::ROOM_READY;

    /** 发牌开局，进入叫抢地主 */
    public const START_GAME = 'startgame';

    /** 已准备，等待其他人 */
    public const WAIT_START = 'waitstart';

    /** 叫 / 抢地主推进或决出 */
    public const BID = 'bid';

    /** 农民加倍推进或发牌 */
    public const DOUBLE = 'double';

    /** 出牌 / 不出 / 局末结算 */
    public const PLAY = 'play';

    /** @see RoomCallBack::TRUST */
    public const TRUST = RoomCallBack::TRUST;

    /** @see RoomCallBack::UNTRUST */
    public const UNTRUST = RoomCallBack::UNTRUST;

    /** @see RoomCallBack::ROOM_DISSOLVE */
    public const ROOM_DISSOLVE = RoomCallBack::ROOM_DISSOLVE;
}
