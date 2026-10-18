<?php

declare(strict_types=1);

namespace App\Game\Niu;

use App\Game\Platform\RoomCallBack;

/**
 * 斗牛 WebSocket 推送 callback 字段常量
 *
 * 房间级常量与 {@see RoomCallBack} 对齐；玩法专属常量留在本类。
 * 前端根据 data.callback 分支渲染。
 */
class CallBack
{
    /** @see RoomCallBack::JOIN_ROOM */
    public const JOIN_ROOM = RoomCallBack::JOIN_ROOM;

    /** @see RoomCallBack::ROOM_READY */
    public const ROOM_READY = RoomCallBack::ROOM_READY;

    /** 已准备，等待其他人（未满 room.num） */
    public const WAIT_START = 'waitstart';

    /** 满员开局，进入抢庄 */
    public const START_GAME = 'startgame';

    /** 抢庄推进或定庄 */
    public const GRAB = 'grab';

    /** 闲家下注推进，或下注完毕发牌 */
    public const BET = 'bet';

    /** 亮牌 / 结算结果 */
    public const SHOW = 'show';

    /** @see RoomCallBack::TRUST */
    public const TRUST = RoomCallBack::TRUST;

    /** @see RoomCallBack::UNTRUST */
    public const UNTRUST = RoomCallBack::UNTRUST;

    /** @see RoomCallBack::ROOM_DISSOLVE */
    public const ROOM_DISSOLVE = RoomCallBack::ROOM_DISSOLVE;
}
