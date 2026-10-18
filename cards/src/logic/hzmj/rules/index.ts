/**
 * 红中麻将规则统一入口
 * - room：协议 / 创房
 * - constants / meld / ops：牌码与碰杠胡提示
 */
export {
  GAME_TYPE,
  MAX_PLAYERS,
  MIN_PLAYERS,
  MODULE_CODE,
  PHASE,
  TIMEOUT,
  WS_ACTION,
  hzmjRules,
  mapServerPhase,
  type HzmjPhase,
} from './room'

export {
  AA_PAY_MODE,
  AGENT_PAY_MODE,
  AGENT_ROOM,
  BAMBOO,
  CHARACTER,
  CLUB_PAY_MODE,
  CLUB_ROOM,
  DOT,
  DRAGONS,
  DRAGON_GREEN,
  DRAGON_RED,
  DRAGON_WHITE,
  FAKE_BEGIN_ANIMATION_TIME,
  FAKE_COUNTDOWN,
  GAME_NAME,
  KING_TILE,
  NORMAL_PAY_MODE,
  NORMAL_ROOM,
  OP,
  ROOM_PLAYING,
  ROOM_WAITING,
  SHORT_TILE_LABEL,
  SHOW_OP,
  TILE_LABEL,
  WINDS,
  WIND_EAST,
  WIND_NORTH,
  WIND_SOUTH,
  WIND_WEST,
} from './constants'

export {
  BOUNDARY,
  classifySuitTiles,
  meldOnlyNeedNum,
  meldWithPairNeedNum,
} from './meld'

export {
  canChow,
  canConcealedKong,
  canContinueKongFromUptiles,
  canExposedKong,
  canPong,
  canWin,
  getCanWinTiles,
  getContinueKongTile,
  getDiscardNotifyOps,
  getDrawNotifyOps,
  getOneConcealedKongTile,
  removeTiles,
} from './ops'
