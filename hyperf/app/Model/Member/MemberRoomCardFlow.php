<?php

declare(strict_types=1);

namespace App\Model\Member;

use App\Model\Model;

/**
 * 房卡流水
 *
 * @property int $id
 * @property int $member_id
 * @property int $type_id
 * @property int $change_number
 * @property int $after_number
 * @property int $flow_type
 * @property int|null $rel_room_id
 * @property string $remark
 * @property string $create_time
 */
class MemberRoomCardFlow extends Model
{
    protected ?string $table = 'member_room_card_flow';

    public bool $timestamps = false;

    protected array $fillable = [
        'member_id',
        'type_id',
        'change_number',
        'after_number',
        'flow_type',
        'rel_room_id',
        'remark',
        'create_time',
    ];

    protected array $casts = [
        'id' => 'integer',
        'member_id' => 'integer',
        'type_id' => 'integer',
        'change_number' => 'integer',
        'after_number' => 'integer',
        'flow_type' => 'integer',
        'rel_room_id' => 'integer',
    ];
}
