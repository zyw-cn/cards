<?php

declare(strict_types=1);

namespace App\Model\Member;

use App\Model\Model;

/**
 * 我的房卡（按类型持有）
 *
 * @property int $id
 * @property int $member_id
 * @property int $type_id
 * @property int $quantity
 * @property string $create_time
 * @property string $update_time
 */
class MemberRoomCard extends Model
{
    protected ?string $table = 'member_room_card';

    public bool $timestamps = false;

    protected array $fillable = [
        'member_id',
        'type_id',
        'quantity',
    ];

    protected array $casts = [
        'id' => 'integer',
        'member_id' => 'integer',
        'type_id' => 'integer',
        'quantity' => 'integer',
    ];
}
