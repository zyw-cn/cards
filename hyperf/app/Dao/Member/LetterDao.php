<?php

declare(strict_types=1);

namespace App\Dao\Member;

use App\Dao\BaseDao;
use App\Model\Member\Letter;

class LetterDao extends BaseDao
{
    protected function setModel(): string
    {
        return Letter::class;
    }
}
