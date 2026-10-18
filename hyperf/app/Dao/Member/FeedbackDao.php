<?php

declare(strict_types=1);

namespace App\Dao\Member;

use App\Dao\BaseDao;
use App\Model\Member\Feedback;

class FeedbackDao extends BaseDao
{
    protected function setModel(): string
    {
        return Feedback::class;
    }
}
