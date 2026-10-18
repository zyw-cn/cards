<?php

declare(strict_types=1);

namespace App\Service\Sys;

use App\Dao\Sys\TemplateDao;
use App\Service\BaseService;

class TemplateService extends BaseService
{
    public function __construct(TemplateDao $dao)
    {
        $this->dao = $dao;
    }
}
