<?php

declare(strict_types=1);

namespace App\Game\Zjh;

use App\Game\Platform\RoomCallBack;

/**
 * 炸金花 WebSocket callback 常量
 */
class CallBack
{
    public const JOIN_ROOM = RoomCallBack::JOIN_ROOM;

    public const ROOM_READY = RoomCallBack::ROOM_READY;

    public const WAIT_START = 'waitstart';

    /** 满员开局并发牌，进入下注轮 */
    public const START_GAME = 'startgame';

    /** 看牌（仅本人回包带 myCards；广播只带 seat） */
    public const LOOK = 'look';

    /** 跟注 */
    public const CALL = 'call';

    /** 加注 */
    public const RAISE = 'raise';

    /** 弃牌 */
    public const FOLD = 'fold';

    /** 比牌 */
    public const COMPARE = 'compare';

    /** 结算 */
    public const SETTLE = 'settle';

    /** @see RoomCallBack::TRUST */
    public const TRUST = RoomCallBack::TRUST;

    /** @see RoomCallBack::UNTRUST */
    public const UNTRUST = RoomCallBack::UNTRUST;

    public const ROOM_DISSOLVE = RoomCallBack::ROOM_DISSOLVE;
}
