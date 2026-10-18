<?php

declare(strict_types=1);

namespace App\Service\Sys;

use App\Dao\Sys\ArticleDao;
use App\Service\BaseService;

class ArticleService extends BaseService
{
    public function __construct(ArticleDao $dao)
    {
        $this->dao = $dao;
    }
}
