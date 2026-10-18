<?php

declare(strict_types=1);

namespace App\Dao\Game;

use App\Dao\BaseDao;
use App\Model\Game\GameRecordPlayer;

class GameRecordPlayerDao extends BaseDao
{
    protected function setModel(): string
    {
        return GameRecordPlayer::class;
    }
}
