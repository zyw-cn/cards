import { getCardType } from '@/logic/table/cardRules'

/** 出牌队列 / PatternFx 使用的特效类型 */
export type LandlordFxType = 'play' | 'bomb' | 'rocket' | 'plane' | 'shunzi' | 'liandui' | 'spring'

/**
 * 根据手牌识别要播的桌面特效（无特效则 'play'）。
 */
export function resolvePlayFxType(cards, data) {
  const server = String(data?.cardType || '')
  if (server.includes('王炸')) return 'rocket'

  const typed = getCardType(cards)
  const type = String(typed?.type || '')
  if (
    type === 'rocket' ||
    type === 'wang_bomb' ||
    type === '王炸' ||
    type.includes('王炸')
  ) {
    return 'rocket'
  }

  // 服务端王炸也带 isBomb，必须先按牌面认出王炸，再落到普通炸弹
  if (server === '炸弹' || data?.isBomb) return 'bomb'
  if (server === '飞机' || server.includes('飞机')) return 'plane'
  if (server === '顺子') return 'shunzi'
  if (server === '连对') return 'liandui'

  if (type === 'bomb' || type === '炸弹') return 'bomb'
  if (
    type === 'plane' ||
    type === 'planeSingle' ||
    type === 'planePair' ||
    type === 'planeTail' ||
    type === 'plane1' ||
    type === 'plane2' ||
    type === '飞机' ||
    type.includes('飞机')
  ) {
    return 'plane'
  }
  if (type === 'straight' || type === 'shunzi' || type === '顺子') return 'shunzi'
  if (type === 'doubleStraight' || type === 'liandui' || type === '连对') return 'liandui'
  return 'play'
}
