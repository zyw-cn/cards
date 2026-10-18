<?php

declare(strict_types=1);

namespace App\Model\Game;

use App\Model\Model;

/**
 * @property int $id
 * @property int $record_id
 * @property int $game_type
 * @property string $room_num
 * @property int $member_id
 * @property int $seat_no
 * @property string $nickname
 * @property string $avatar
 * @property string $score
 * @property int $is_winner
 * @property int $is_banker
 * @property string $create_at
 */
class GameRecordPlayer extends Model
{
    protected ?string $table = 'game_record_player';

    public bool $timestamps = false;

    protected array $fillable = [
        'record_id',
        'game_type',
        'room_num',
        'member_id',
        'seat_no',
        'nickname',
        'avatar',
        'score',
        'is_winner',
        'is_banker',
        'create_at',
    ];

    protected array $casts = [
        'id'        => 'integer',
        'record_id' => 'integer',
        'game_type' => 'integer',
        'member_id' => 'integer',
        'seat_no'   => 'integer',
        'is_winner' => 'integer',
        'is_banker' => 'integer',
    ];
}
