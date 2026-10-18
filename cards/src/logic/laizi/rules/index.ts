/**
 * 癞子斗地主规则统一入口
 *
 * - room：协议 / 创房
 * - cards：出牌牌型（含癞子替换，与经典斗地主、跑得快独立）
 */
export {
  ASK_MODE,
  DOUBLE_SCORES,
  GAME_TYPE,
  MAX_PLAYERS,
  MIN_PLAYERS,
  MODULE_CODE,
  PHASE,
  TIMEOUT,
  WS_ACTION,
  laiziRules,
  type LaiziPhase,
} from './room'

export {
  TYPE,
  WILD,
  WILD_SINGLE_WEIGHT,
  analyzeCards,
  canFinishHand,
  findHint,
  getCardType,
  isBigger,
  isWild,
  toPoint,
} from './cards'
