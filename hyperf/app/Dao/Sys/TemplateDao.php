<?php
namespace App\Dao\Sys;
use App\Dao\BaseDao;
use App\Model\Sys\Template;

class TemplateDao extends BaseDao
{

    protected function setModel(): string
    {
         return Template::class;
    }
}