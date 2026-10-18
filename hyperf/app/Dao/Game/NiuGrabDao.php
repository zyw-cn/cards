<?php

declare(strict_types=1);

namespace App\Dao\Game;

use App\Dao\BaseDao;
use App\Model\Game\NiuGrab;

/** 斗牛抢庄表 h_niu_grab 数据访问 */
class NiuGrabDao extends BaseDao
{
    protected function setModel(): string
    {
        return NiuGrab::class;
    }
}
