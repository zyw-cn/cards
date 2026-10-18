-- =============================================================================
-- 全玩法对局录像表设计
-- 适用 game_type: 1斗地主 2斗牛 3炸金花 4跑得快 5癞子斗地主 7红中麻将
--
-- 设计要点:
-- 1. 与 h_game_play_card（手牌审计）分离，本表是「可逐步回放」的指令流
-- 2. 统一外壳: init_json + ops_json + result_json；玩法差异写在 JSON 内
-- 3. 大厅列表靠 h_game_record_player 按 member_id 查，避免扫 JSON
-- 4. 局中可 Redis 攒 ops，局末一次落库；也可每步 append（看量级）
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1) 录像主表：一局一条
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `h_game_record` (
  `id`             bigint unsigned  NOT NULL AUTO_INCREMENT COMMENT '录像ID(对外 recordId)',
  `schema_ver`     tinyint unsigned NOT NULL DEFAULT 1 COMMENT 'JSON 协议版本，便于以后升级',
  `game_type`      tinyint unsigned NOT NULL COMMENT '1斗地主 2斗牛 3金花 4跑得快 5癞子 7红中',
  `room_id`        int unsigned     NOT NULL DEFAULT 0 COMMENT 'h_game_room.id',
  `round_id`       int unsigned     NOT NULL DEFAULT 0 COMMENT 'h_game_round.id',
  `room_num`       varchar(32)      NOT NULL DEFAULT '' COMMENT '房间号(展示/检索)',
  `owner_id`       int unsigned     NOT NULL DEFAULT 0 COMMENT '房主 member_id',
  `cur_round`      smallint unsigned NOT NULL DEFAULT 1 COMMENT '房间内第几局',
  `max_round`      smallint unsigned NOT NULL DEFAULT 1 COMMENT '房间总局数',
  `player_num`     tinyint unsigned NOT NULL DEFAULT 0 COMMENT '本局人数',
  `unit`           decimal(12,2)    NOT NULL DEFAULT 0.00 COMMENT '底分(列表展示用冗余)',
  `rule_json`      text                      DEFAULT NULL COMMENT '开房规则快照(与进房 rule 一致)',
  `init_json`      text             NOT NULL COMMENT '开局冻结快照(不可变)',
  `ops_json`       mediumtext       NOT NULL COMMENT '操作流水 JSON 数组 [{"seq","op","seat","fromSeat","tiles","ext"}]',
  `result_json`    text                      DEFAULT NULL COMMENT '结算快照(局末写入)',
  `status`         tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0录制中 1已完成可回放 2废弃(解散/流局未完)',
  `op_count`       int unsigned     NOT NULL DEFAULT 0 COMMENT 'ops 条数(冗余，便于校验)',
  `start_time`     datetime                  DEFAULT NULL COMMENT '本局开始',
  `end_time`       datetime                  DEFAULT NULL COMMENT '本局结束',
  `create_at`      datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_at`      datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_round_id` (`round_id`),
  KEY `idx_room_id` (`room_id`),
  KEY `idx_room_num` (`room_num`),
  KEY `idx_game_type_end` (`game_type`, `end_time`),
  KEY `idx_status_end` (`status`, `end_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='对局录像主表(全玩法)';

-- ---------------------------------------------------------------------------
-- 2) 参与者索引：大厅「我的战绩」
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `h_game_record_player` (
  `id`          bigint unsigned  NOT NULL AUTO_INCREMENT,
  `record_id`   bigint unsigned  NOT NULL COMMENT 'h_game_record.id',
  `game_type`   tinyint unsigned NOT NULL DEFAULT 0 COMMENT '冗余玩法，列表筛选',
  `room_num`    varchar(32)      NOT NULL DEFAULT '' COMMENT '冗余房间号',
  `member_id`   int unsigned     NOT NULL COMMENT '玩家ID',
  `seat_no`     tinyint unsigned NOT NULL DEFAULT 0 COMMENT '座位号(与局内一致)',
  `nickname`    varchar(64)      NOT NULL DEFAULT '',
  `avatar`      varchar(255)     NOT NULL DEFAULT '',
  `score`       decimal(12,2)    NOT NULL DEFAULT 0.00 COMMENT '本局得分(结算后回填)',
  `is_winner`   tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否赢家侧',
  `is_banker`   tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否庄/地主',
  `create_at`   datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_record_member` (`record_id`, `member_id`),
  KEY `idx_member_create` (`member_id`, `create_at`),
  KEY `idx_member_game` (`member_id`, `game_type`, `create_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='录像参与者(大厅列表)';

-- =============================================================================
-- JSON 协议 (schema_ver = 1)
-- =============================================================================
--
-- ---------- ops_json 统一元素 ----------
-- {
--   "seq": 1,                 -- 从 1 递增
--   "op": "discard",          -- 字符串动作码(见下方各玩法字典)
--   "seat": 0,                -- 主动作座位；无座位用 -1
--   "fromSeat": -1,           -- 来源座位(被碰/被点炮/被比牌等)；无则 -1
--   "tiles": [],              -- 牌面列表；扑克用字符串码(A3)，麻将用 int
--   "ext": {}                 -- 玩法扩展(倍数/是否托管/杠类型/注额等)
-- }
--
-- ---------- init_json 公共壳 ----------
-- {
--   "seats": [                -- 必填：按座位对齐
--     {"seat":0,"memberId":18,"nickname":"A","avatar":"...","tiles":[...]}
--   ],
--   "dealerSeat": 0,          -- 庄/首出/地主候选(玩法语义见下)
--   "ext": { ... }            -- 玩法专有冻结字段
-- }
--
-- ---------- result_json 公共壳 ----------
-- {
--   "scores": {"0": 6, "1": -2},   -- 座位 -> 本局分差
--   "winners": [0],                 -- 赢家座位列表
--   "ext": { ... }                  -- 玩法专有结算
-- }
--
-- =============================================================================
-- 各玩法 init.ext / ops / result.ext 约定
-- =============================================================================
--
-- ### 1 斗地主 Dou / 5 癞子 Lz
-- init.ext:
--   remain: ["A3","B6","C7"]      -- 底牌(斗地主3张 / 癞子4张)
--   askMode, firstBidSeat
--   (癞子) wildCode: "W55"
-- ops:
--   bid     tiles=[]  ext:{score:0|1|2|3}
--   double  tiles=[]  ext:{double:0|1|2}          -- 农民加倍
--   set_landlord tiles=[底牌...] ext:{landlordSeat, multiple}
--   play    tiles=[...] 或 []=过  ext:{trust?:bool}
--   trust / untrust
-- result.ext:
--   landlordSeat, landlordWin, winSide, multiple, bidMultiple,
--   bombCount, spring, springType, farmerDoubles, unit, remainCards
--
-- ### 4 跑得快 Pdk
-- init.ext:
--   firstSeat: 持黑桃3座位
--   spade3: "A3" 或等价码
-- ops:
--   double, play(空=过), trust/untrust
-- result.ext:
--   unit, bombUnit, bombMultiple, bombCount
--
-- ### 2 斗牛 Niu
-- init.ext:
--   (起手5张已在 seats[].tiles；无牌墙回放需求)
-- ops:
--   grab   tiles=[] ext:{multiple:0|1|2|4}       -- 0不抢
--   bet    tiles=[] ext:{multiple:1|2|...}
--   show   tiles=[5张] ext:{niuType,niuMultiple} -- 摊牌
-- result.ext:
--   grabMultiple, bankerSeat, hands:[{seat,cards,niuType,niuLabel,betMultiple,delta,win}]
--
-- ### 3 炸金花 Zjh
-- init.ext:
--   dealerSeat, ante/unit, pot
-- ops:
--   look    tiles=[]            -- 看牌(回放可延迟亮自己的牌)
--   call    tiles=[] ext:{amount}
--   raise   tiles=[] ext:{amount}
--   fold    tiles=[]
--   compare tiles=[] ext:{targetSeat, winSeat}
-- result.ext:
--   pot, winnerSeat, hands:[{seat,cards,type,label,folded,looked,invested,delta,win}]
--
-- ### 7 红中麻将 Hzmj
-- init.ext:
--   kingTiles: [35]
--   diceList: [[3,5],[1,2],[6,4],[2,2]]
--   tileWall: [ ... ]             -- 开局剩余墙(或完整洗牌序+已发长度)
--   luckyNum: 2
--   dealerSeat
-- ops:
--   draw            tiles:[t]     fromSeat=自己
--   discard         tiles:[t]
--   pass            tiles:[]      fromSeat=出牌者
--   pong            tiles:[t]     fromSeat=出牌者
--   kong            tiles:[t]     ext:{kind:"exposed|concealed|continue"}
--   win             tiles:[t]     ext:{winOp:"draw_win|kong_win"}
--   lucky           tiles:[...]   ext:{hits:n}   -- 奖码动画数据
--   draw_end        tiles:[]      -- 流局
-- result.ext:
--   scores, totalScores, winOp, finalTile, luckyTiles, luckyHit,
--   showHands, roomOver
--
-- =============================================================================
-- 写入生命周期 (平台 GameRecordService)
-- =============================================================================
-- begin(round):  INSERT record(status=0,init_json,ops_json='[]') + players
-- append(op):    内存/Redis 追加；或 UPDATE ops_json / op_count
-- finish(result): UPDATE result_json,status=1,end_time + 回填 player.score
-- abort():       UPDATE status=2 或 DELETE（未打完不对外展示）
--
-- 查询:
--   列表: SELECT r.*, p.score FROM h_game_record_player p
--         JOIN h_game_record r ON r.id=p.record_id
--         WHERE p.member_id=? AND r.status=1 ORDER BY p.create_at DESC
--   回放: SELECT * FROM h_game_record WHERE id=? AND status=1
-- =============================================================================
