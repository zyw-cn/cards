<?php

declare(strict_types=1);

namespace App\Dao\Sys;

use App\Dao\BaseDao;
use App\Model\Sys\TemplateContent;

class TemplateContentDao extends BaseDao
{
    protected function setModel(): string
    {
        return TemplateContent::class;
    }
}
