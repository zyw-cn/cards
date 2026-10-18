<?php

declare(strict_types=1);

namespace App\Model\Game;

use App\Model\Model;

/**
 * @property int $id
 * @property int $room_id
 * @property int $player_id
 * @property int $seat_no
 * @property string $join_time
 * @property string|null $exit_time
 * @property int $fd
 * @property int $is_auto 是否自动托管 0-否 1-是
 * @property int $time_out_num 连续出牌超时次数，达 3 次进入托管
 * @property int $status 0未开始 1游戏中 2游戏结束
 */
class RoomMember extends Model
{
    /** 游戏未开始（等待/准备） */
    public const STATUS_WAITING = 0;

    /** 游戏中 */
    public const STATUS_PLAYING = 1;

    /** 游戏结束 / 房间已解散（软释放，可再加入复用行） */
    public const STATUS_ENDED = 2;

    protected ?string $table = 'room_member';

    public bool $timestamps = false;

    protected array $fillable = [
        'room_id',
        'player_id',
        'seat_no',
        'join_time',
        'exit_time',
        'fd',
        'is_auto',
        'time_out_num',
        'status',
    ];

    protected array $casts = [
        'id' => 'integer',
        'room_id' => 'integer',
        'player_id' => 'integer',
        'seat_no' => 'integer',
        'fd' => 'integer',
        'is_auto' => 'integer',
        'time_out_num' => 'integer',
        'status' => 'integer',
    ];
}
