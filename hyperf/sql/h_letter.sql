-- =============================================================================
-- 大厅站内信（Hyperf）
-- 单发：member_id > 0，仅该玩家可见
-- 全员：member_id = 0，所有人可见；已读/删除记在 h_letter_user
-- =============================================================================

SET NAMES utf8mb4;

-- 正文表：若已存在则保留；缺索引时补上
CREATE TABLE IF NOT EXISTS `h_letter` (
  `id`              int(11) unsigned NOT NULL AUTO_INCREMENT,
  `content`         text                      DEFAULT NULL COMMENT '正文',
  `read_status`     tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '兼容旧字段；新逻辑以 h_letter_user 为准',
  `title`           varchar(255)        NOT NULL DEFAULT '' COMMENT '标题',
  `create_at`       datetime                     DEFAULT CURRENT_TIMESTAMP,
  `update_at`       datetime                     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `create_operator` varchar(31)         NOT NULL DEFAULT '',
  `update_operator` varchar(31)         NOT NULL DEFAULT '',
  `member_id`       int(11) unsigned    NOT NULL DEFAULT 0 COMMENT '0=全员；>0=仅该玩家',
  `is_del`          tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '0正常 1后台删除',
  `thumb`           varchar(255)        NOT NULL DEFAULT '',
  `type`            tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT '1系统 2业务',
  PRIMARY KEY (`id`),
  KEY `idx_member_del_time` (`member_id`, `is_del`, `create_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站内信正文';

-- 每人已读 / 玩家侧删除（全员与单发统一）
CREATE TABLE IF NOT EXISTS `h_letter_user` (
  `id`          bigint unsigned  NOT NULL AUTO_INCREMENT,
  `letter_id`   int unsigned     NOT NULL COMMENT 'h_letter.id',
  `member_id`   int unsigned     NOT NULL COMMENT '玩家 id',
  `read_status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0未读 1已读',
  `is_del`      tinyint unsigned NOT NULL DEFAULT 0 COMMENT '玩家侧删除，不影响他人',
  `read_at`     datetime                  DEFAULT NULL,
  `create_at`   datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_letter_member` (`letter_id`, `member_id`),
  KEY `idx_member_read` (`member_id`, `read_status`, `is_del`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站内信每人状态';

-- 示例：一条全员公告（可删）
INSERT INTO `h_letter` (`content`, `title`, `member_id`, `type`, `create_operator`, `update_operator`)
SELECT '欢迎来到棋牌大厅，好友房随时开局。', '系统公告', 0, 1, 'system', 'system'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `h_letter` WHERE `member_id` = 0 AND `title` = '系统公告' AND `is_del` = 0 LIMIT 1
);
