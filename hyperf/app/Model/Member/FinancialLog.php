<?php

declare(strict_types=1);

namespace App\Model\Member;

use App\Model\Model;

/**
 * @property int $id
 * @property string $create_at 发生时间
 * @property string $change_amount 变动金额
 * @property string $before_amount 变动前金额
 * @property string $after_amount 变动后金额
 * @property int $member_id 会员id
 * @property int $flow_type 流水类型 1:充值 2:提现 3:购买房卡(历史) 4:游戏获胜 5:游戏结算 6:平台费用
 * @property string $remark 备注
 * @property string $order_sn 流水号
 * @property string $before_frozen_amount 变动前冻结金额
 * @property string $after_froze_amount 变动后冻结金额
 */
class FinancialLog extends Model
{
    protected ?string $table = 'financial_log';

    public bool $timestamps = false;

    protected array $casts = [
        'id' => 'integer',
        'member_id' => 'integer',
        'flow_type' => 'integer',
    ];
}
