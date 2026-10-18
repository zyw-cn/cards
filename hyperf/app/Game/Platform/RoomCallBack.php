<?php

declare(strict_types=1);

namespace App\Game\Platform;

/**
 * 平台房间相关 callback（与玩法无关；字符串保持与历史前端一致）
 */
class RoomCallBack
{
    public const JOIN_ROOM = 'joinroom';

    public const ROOM_READY = 'roomready';

    public const ROOM_DISSOLVE = 'roomdissolve';

    /** 房间快捷聊天 */
    public const CHAT = 'chat';

    /** 有人申请解散（投票开始） */
    public const APPLY_DISMISS = 'applydismiss';

    /** 解散投票更新 */
    public const VOTE_DISMISS = 'votedismiss';

    /** 解散投票取消（有人拒绝等） */
    public const CANCEL_DISMISS = 'canceldismiss';

    /** 开启托管 */
    public const TRUST = 'trust';

    /** 取消托管 */
    public const UNTRUST = 'untrust';
}
