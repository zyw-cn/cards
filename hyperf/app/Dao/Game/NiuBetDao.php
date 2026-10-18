<?php

declare(strict_types=1);

namespace App\Dao\Game;

use App\Dao\BaseDao;
use App\Model\Game\NiuBet;

/** 斗牛闲家下注表 h_niu_bet 数据访问 */
class NiuBetDao extends BaseDao
{
    protected function setModel(): string
    {
        return NiuBet::class;
    }
}
