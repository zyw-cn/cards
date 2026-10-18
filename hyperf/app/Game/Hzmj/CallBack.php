<?php

declare(strict_types=1);

namespace App\Game\Hzmj;

use App\Game\Platform\RoomCallBack;

/**
 * 红中麻将 WebSocket 推送 callback 字段常量
 */
class CallBack
{
    public const JOIN_ROOM = RoomCallBack::JOIN_ROOM;

    public const ROOM_READY = RoomCallBack::ROOM_READY;

    /** 已准备，等待其他人 */
    public const WAIT_START = 'waitstart';

    /** 进入抢庄 */
    public const GRAB = 'grab';

    /** 发牌开局 */
    public const START_GAME = 'startgame';

    /** 出牌 / 碰杠胡 / 局末结算 */
    public const OP = 'op';

    /** @see RoomCallBack::TRUST */
    public const TRUST = RoomCallBack::TRUST;

    /** @see RoomCallBack::UNTRUST */
    public const UNTRUST = RoomCallBack::UNTRUST;

    public const ROOM_DISSOLVE = RoomCallBack::ROOM_DISSOLVE;

    /** @see RoomCallBack::APPLY_DISMISS */
    public const APPLY_DISMISS = RoomCallBack::APPLY_DISMISS;

    /** @see RoomCallBack::VOTE_DISMISS */
    public const VOTE_DISMISS = RoomCallBack::VOTE_DISMISS;

    /** @see RoomCallBack::CANCEL_DISMISS */
    public const CANCEL_DISMISS = RoomCallBack::CANCEL_DISMISS;
}
