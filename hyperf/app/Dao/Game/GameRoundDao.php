<?php

declare(strict_types=1);

namespace App\Dao\Game;

use App\Dao\BaseDao;
use App\Model\Game\GameRound;

class GameRoundDao extends BaseDao
{
    protected function setModel(): string
    {
        return GameRound::class;
    }
}
