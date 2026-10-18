<?php

declare(strict_types=1);

namespace App\Model\Game;

use App\Model\Model;

/**
 * @property int $id
 * @property int $schema_ver
 * @property int $game_type
 * @property int $room_id
 * @property int $round_id
 * @property string $room_num
 * @property int $owner_id
 * @property int $cur_round
 * @property int $max_round
 * @property int $player_num
 * @property string $unit
 * @property string|null $rule_json
 * @property string $init_json
 * @property string $ops_json
 * @property string|null $result_json
 * @property int $status
 * @property int $op_count
 * @property string|null $start_time
 * @property string|null $end_time
 * @property string $create_at
 * @property string $update_at
 */
class GameRecord extends Model
{
    /** 录制中 */
    public const STATUS_RECORDING = 0;

    /** 已完成可回放 */
    public const STATUS_DONE = 1;

    /** 废弃（中途解散等） */
    public const STATUS_ABORTED = 2;

    protected ?string $table = 'game_record';

    public bool $timestamps = false;

    protected array $fillable = [
        'schema_ver',
        'game_type',
        'room_id',
        'round_id',
        'room_num',
        'owner_id',
        'cur_round',
        'max_round',
        'player_num',
        'unit',
        'rule_json',
        'init_json',
        'ops_json',
        'result_json',
        'status',
        'op_count',
        'start_time',
        'end_time',
        'create_at',
        'update_at',
    ];

    protected array $casts = [
        'id'         => 'integer',
        'schema_ver' => 'integer',
        'game_type'  => 'integer',
        'room_id'    => 'integer',
        'round_id'   => 'integer',
        'owner_id'   => 'integer',
        'cur_round'  => 'integer',
        'max_round'  => 'integer',
        'player_num' => 'integer',
        'status'     => 'integer',
        'op_count'   => 'integer',
    ];
}
