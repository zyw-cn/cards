<?php

declare(strict_types=1);

namespace App\Model\Member;

use App\Model\Model;

/**
 * 房卡类型
 *
 * @property int $id
 * @property string $name
 * @property string $price
 * @property int $round_count
 * @property int $status
 * @property int $sort
 * @property string $create_time
 * @property string $update_time
 */
class RoomCardType extends Model
{
    protected ?string $table = 'room_card_type';

    public bool $timestamps = false;

    protected array $fillable = [
        'name',
        'price',
        'round_count',
        'status',
        'sort',
    ];

    protected array $casts = [
        'id' => 'integer',
        'round_count' => 'integer',
        'status' => 'integer',
        'sort' => 'integer',
    ];
}
