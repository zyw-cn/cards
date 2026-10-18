/**
 * 跑得快规则统一入口
 *
 * - room：协议常量、创房选项
 * - cards：牌型（三带一/二、纯飞机、三飞机收官等，见 cards.ts 头注释）
 *
 * 与斗地主差异：中途飞机不带翅膀；两飞机不能带 4 张；三带二可为两张零牌或一对。
 */
export {
  DOUBLE_SCORES,
  GAME_TYPE,
  HAND_SIZE,
  MAX_PLAYERS,
  MIN_PLAYERS,
  MODULE_CODE,
  PHASE,
  TIMEOUT,
  WS_ACTION,
  pdkRules,
  type PdkPhase,
} from './room'

export {
  canBeat,
  cardTypeLabel,
  findHint,
  getCardType,
  isTripleAce,
} from './cards'
