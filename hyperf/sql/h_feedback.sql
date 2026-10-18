-- =============================================================================
-- 大厅玩家反馈 + 系统答复（Hyperf）
-- 玩家只看自己的反馈；有 reply 即「答复」页可见
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `h_feedback` (
  `id`          int unsigned     NOT NULL AUTO_INCREMENT,
  `member_id`   int unsigned     NOT NULL COMMENT '玩家 id',
  `content`     varchar(500)     NOT NULL DEFAULT '' COMMENT '玩家反馈内容',
  `reply`       varchar(1000)             DEFAULT NULL COMMENT '系统/客服答复，空=尚未答复',
  `status`      tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0待答复 1已答复',
  `read_status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '玩家是否已读答复：0未读 1已读（无答复时为0）',
  `is_del`      tinyint unsigned NOT NULL DEFAULT 0 COMMENT '玩家侧删除',
  `reply_at`    datetime                  DEFAULT NULL COMMENT '答复时间',
  `reply_by`    varchar(31)      NOT NULL DEFAULT '' COMMENT '答复人',
  `create_at`   datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_at`   datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_member_del_time` (`member_id`, `is_del`, `create_at`),
  KEY `idx_member_reply_read` (`member_id`, `status`, `read_status`, `is_del`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大厅玩家反馈与答复';
