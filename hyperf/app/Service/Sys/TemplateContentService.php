<?php

declare(strict_types=1);

namespace App\Service\Sys;


use App\Dao\Sys\TemplateContentDao;
use App\Service\BaseService;

class TemplateContentService extends BaseService
{
    public function __construct(TemplateContentDao $dao)
    {
        $this->dao = $dao;
    }
}
