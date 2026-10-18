<?php

declare(strict_types=1);

namespace App\Dao\Member;

use App\Dao\BaseDao;
use App\Model\Member\Withdraw;

class WithdrawDao extends BaseDao
{
    protected function setModel(): string
    {
        return Withdraw::class;
    }
}
