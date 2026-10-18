<?php

declare(strict_types=1);

namespace App\Service\Sys;

use App\Constants\RedisKey;
use App\Dao\Sys\SettingDao;
use App\Service\BaseService;
use Hyperf\Cache\Cache;
use Hyperf\Di\Annotation\Inject;

class SettingService extends BaseService
{
    /** 配置缓存时间：1 小时 */
    private const CACHE_TTL = 3600;

    #[Inject]
    protected Cache $cache;

    public function __construct(SettingDao $dao)
    {
        $this->dao = $dao;
    }

    public function get(string $key): mixed
    {
        $cacheKey = sprintf(RedisKey::SETTING, $key);

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $setting = $this->dao->getOne(['name' => $key]);
        if (! $setting) {
            return null;
        }

        $value = $this->parse((int) $setting->type, (string) $setting->value);
        $this->cache->set($cacheKey, $value, self::CACHE_TTL);

        return $value;
    }

    private function parse(int $type, string $value): mixed
    {
        if ($type !== 3) {
            return $value;
        }

        $array = preg_split('/[,;\r\n]+/', trim($value, ",;\r\n"));

        if (! str_contains($value, ':')) {
            return $array;
        }

        $result = [];
        foreach ($array as $val) {
            [$k, $v] = explode(':', $val);
            $result[$k] = $v;
        }

        return $result;
    }
}
