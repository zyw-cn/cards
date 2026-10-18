<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Dao\Game\RoomConfigDao;
use App\Service\BaseService;

/**
 * 房间配置服务（表 h_room_config 已废弃时可安全空返回）
 */
class RoomConfigService extends BaseService
{
    /** 房间配置启用状态 */
    private const STATUS_ON = 1;

    public function __construct(RoomConfigDao $roomConfigDao)
    {
        $this->dao = $roomConfigDao;
    }

    /**
     * 房间配置列表（仅返回启用配置）；表不存在时返回空列表
     */
    public function configList(): array
    {
        try {
            return $this->dao->getAll(
                ['status' => self::STATUS_ON],
                ['id', 'name', 'sub_name', 'bottom_points', 'entry_points', 'status'],
                ['id' => 'asc']
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 按条件取一条配置；id<=0 或表不存在时返回 null，避免建房/入座误查废弃表
     */
    public function getOne(array $where, ?string $field = '*', array $with = [])
    {
        if ((int) ($where['id'] ?? 0) <= 0) {
            return null;
        }

        try {
            return $this->dao->getOne($where, $field, $with);
        } catch (\Throwable) {
            return null;
        }
    }
}
