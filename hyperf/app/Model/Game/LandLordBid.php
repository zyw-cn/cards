<?php

declare(strict_types=1);

namespace App\Model\Game;

use App\Model\Model;

/**
 * 叫抢地主记录表
 *
 * 约定：
 * - seq=0 为「首叫指派」种子行，仅用 seat_no 记录随机首叫座位（不是一次真实叫抢）
 * - seq>=1 为真实叫抢：action 0-不叫/不抢 1-叫地主 2-抢地主
 *
 * 托管字段 is_auto / time_out_num 在 h_room_member，不在本表使用。
 *
 * @property int $id
 * @property int $game_round_id
 * @property int $seq
 * @property int $seat_no
 * @property int $member_id
 * @property int $action
 * @property int $score
 * @property int $multiple
 * @property string $create_time
 */
class LandLordBid extends Model
{
    protected ?string $table = 'land_lord_bid';

    public bool $timestamps = false;

    protected array $fillable = [
        'game_round_id',
        'seq',
        'seat_no',
        'member_id',
        'action',
        'score',
        'multiple',
        'create_time',
    ];

    protected array $casts = [
        'id' => 'integer',
        'game_round_id' => 'integer',
        'seq' => 'integer',
        'seat_no' => 'integer',
        'member_id' => 'integer',
        'action' => 'integer',
        'score' => 'integer',
        'multiple' => 'integer',
    ];
}
