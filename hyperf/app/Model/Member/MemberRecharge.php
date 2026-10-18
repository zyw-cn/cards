<?php

declare(strict_types=1);

namespace App\Model\Member;

use App\Model\Model;

/**
 * @property int $id
 * @property int $member_id
 * @property string $order_sn
 * @property string $money
 * @property int $channel
 * @property string $screenshot
 * @property int $status
 * @property string $remark
 */
class MemberRecharge extends Model
{
    protected ?string $table = 'member_recharge';

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'integer',
        'member_id' => 'integer',
        'channel' => 'integer',
        'status' => 'integer',
    ];
}
