<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dao\Game\GameRoundDao;
use App\Service\BaseService;

class GameRoundService extends BaseService
{
    public function __construct(GameRoundDao $gameRoundDao)
    {
        $this->dao = $gameRoundDao;
    }
}
