<?php

namespace App\Constants;

class RedisKey
{
    public const REGISTER_KEY = 'message:code:%s:%s';

    public const SEND_LIMIT = 'message:send:limit:%s:%s';

    public const SETTING = 'sys:setting:%s';

    public const USER_ROOM = 'user:room:%d';

    /** WebSocket fd → uid 反向索引，用于连接关闭时清理在线状态 */
    public const USER_FD = 'ws:fd:%d';

    /**
     * 房间回合超时令牌（跨 worker 校验，避免旧 Timer 误触发）
     * value=JSON {token,player_id,phase}，%d=房间ID
     */
    public const ROOM_TIMEOUT = 'room:timeout:%d';

    /**
     * 申请解散投票状态（%d=房间ID）
     * value=JSON {fromSeat,fromPid,states,expireAt,playerNum,roomNum,token}
     */
    public const ROOM_DISMISS_VOTE = 'room:dismiss:%d';

    /**
     * 对局录像操作流水缓冲（%d=roundId）
     * Redis LIST，每项为一条 op JSON
     */
    public const GAME_RECORD_OPS = 'record:ops:%d';
}