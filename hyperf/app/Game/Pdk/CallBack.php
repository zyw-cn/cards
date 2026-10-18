<?php

declare(strict_types=1);

namespace App\Game\Pdk;

use App\Game\Platform\RoomCallBack;

/**
 * 跑得快 WebSocket 推送 callback 字段常量
 */
class CallBack
{
    public const JOIN_ROOM = RoomCallBack::JOIN_ROOM;

    public const ROOM_READY = RoomCallBack::ROOM_READY;

    /** 发牌开局，进入加倍 */
    public const START_GAME = 'startgame';

    /** 已准备，等待其他人 */
    public const WAIT_START = 'waitstart';

    /** 加倍 / 不加倍 */
    public const DOUBLE = 'double';

    /** 出牌 / 不出 / 局末结算 */
    public const PLAY = 'play';

    /** @see RoomCallBack::TRUST */
    public const TRUST = RoomCallBack::TRUST;

    /** @see RoomCallBack::UNTRUST */
    public const UNTRUST = RoomCallBack::UNTRUST;

    public const ROOM_DISSOLVE = RoomCallBack::ROOM_DISSOLVE;
}
