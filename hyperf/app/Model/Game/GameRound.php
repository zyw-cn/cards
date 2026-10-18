<?php

declare(strict_types=1);

namespace App\Model\Game;

use App\Model\Model;

/**
 * @property int $id
 * @property int $room_id
 * @property int $master_id
 * @property int $first_bid_seat
 * @property int $multiple
 * @property string|null $extra
 * @property int $winner_type
 * @property string $winner_user_ids
 * @property string|null $create_at
 * @property string|null $update_at
 * @property string $player_ids
 * @property int $status 0未开始 1游戏中 2游戏结束
 */
class GameRound extends Model
{
    /** 未开始（等待确认/发牌） */
    public const STATUS_PENDING = 0;

    /** 游戏中（已发牌） */
    public const STATUS_PLAYING = 1;

    /** 游戏结束 */
    public const STATUS_ENDED = 2;

    protected ?string $table = 'game_round';

    public bool $timestamps = false;

    protected array $fillable = [
        'room_id',
        'master_id',
        'first_bid_seat',
        'multiple',
        'extra',
        'winner_type',
        'winner_user_ids',
        'player_ids',
        'status',
    ];

    protected array $casts = [
        'id' => 'integer',
        'room_id' => 'integer',
        'master_id' => 'integer',
        'first_bid_seat' => 'integer',
        'multiple' => 'integer',
        'winner_type' => 'integer',
        'status' => 'integer',
    ];
}
