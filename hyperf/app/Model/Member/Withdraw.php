<?php

declare(strict_types=1);

namespace App\Model\Member;

use App\Model\Model;

/**
 * @property int $id
 * @property string $num
 * @property string $receive_num
 * @property string $fee
 * @property int $member_id
 * @property int $status
 * @property string $remark
 * @property string $order_sn
 * @property string $channel
 * @property string $real_name
 * @property string $pay_account
 */
class Withdraw extends Model
{
    protected ?string $table = 'withdraw';

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'integer',
        'member_id' => 'integer',
        'status' => 'integer',
    ];
}
