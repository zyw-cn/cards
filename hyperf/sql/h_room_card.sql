-- =============================================================================
-- 房卡三表：类型 / 我的房卡 / 流水
-- 执行：mysql -uroot -p card < sql/h_room_card.sql
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. 房卡类型
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `h_room_card_type` (
  `id`           int unsigned     NOT NULL AUTO_INCREMENT COMMENT '类型 id',
  `name`         varchar(64)      NOT NULL DEFAULT '' COMMENT '房卡名称',
  `price`        decimal(12,2)    NOT NULL DEFAULT 0.00 COMMENT '单张价格（金币）',
  `round_count`  int unsigned     NOT NULL COMMENT '对应局数',
  `status`       tinyint          NOT NULL DEFAULT 1 COMMENT '1启用 -1下架',
  `sort`         int              NOT NULL DEFAULT 0 COMMENT '排序，越大越靠前',
  `create_time`  datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time`  datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='房卡类型';

INSERT INTO `h_room_card_type` (`id`, `name`, `price`, `round_count`, `status`, `sort`)
VALUES
  (1, '6局房卡',  10.00, 6,  1, 100),
  (2, '12局房卡', 18.00, 12, 1, 90),
  (3, '18局房卡', 25.00, 18, 1, 80)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `price` = VALUES(`price`),
  `round_count` = VALUES(`round_count`);

-- ---------------------------------------------------------------------------
-- 2. 我的房卡（会员 × 类型 → 张数）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `h_member_room_card` (
  `id`           bigint unsigned  NOT NULL AUTO_INCREMENT,
  `member_id`    int unsigned     NOT NULL COMMENT '会员 id',
  `type_id`      int unsigned     NOT NULL COMMENT '房卡类型 id',
  `quantity`     int unsigned     NOT NULL DEFAULT 0 COMMENT '房卡张数',
  `create_time`  datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time`  datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_type` (`member_id`, `type_id`),
  KEY `idx_type` (`type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='我的房卡';

-- ---------------------------------------------------------------------------
-- 3. 房卡流水（已有则补 type_id；没有则新建）
-- flow_type: 1购买 2赠送 3建房消耗 4未开局返还 5续局消耗 6后台调整
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `h_member_room_card_flow` (
  `id`             bigint unsigned  NOT NULL AUTO_INCREMENT,
  `member_id`      int unsigned     NOT NULL COMMENT '会员 id',
  `type_id`        int unsigned     NOT NULL DEFAULT 0 COMMENT '房卡类型 id',
  `change_number`  int              NOT NULL DEFAULT 0 COMMENT '变动张数：正增负减',
  `after_number`   int unsigned     NOT NULL DEFAULT 0 COMMENT '变动后该类型余量',
  `flow_type`      tinyint unsigned NOT NULL COMMENT '1购买 2赠送 3建房消耗 4未开局返还 5续局消耗 6后台调整',
  `rel_room_id`    bigint unsigned           DEFAULT NULL COMMENT '关联房间 id',
  `remark`         varchar(255)     NOT NULL DEFAULT '' COMMENT '备注',
  `create_time`    datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_member_time` (`member_id`, `create_time`),
  KEY `idx_type` (`type_id`),
  KEY `idx_room` (`rel_room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='房卡流水';

-- 旧库缺 type_id 时补上（已存在则忽略错误）
-- MySQL 8 无 IF NOT EXISTS 列语法时用存储过程更稳；这里用简单检测：
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'h_member_room_card_flow'
    AND COLUMN_NAME = 'type_id'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE `h_member_room_card_flow` ADD COLUMN `type_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''房卡类型 id'' AFTER `member_id`, ADD KEY `idx_type` (`type_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
