/**
 * 炸金花牌型（对齐 hyperf App\Game\Zjh\Card\ZjhCard）
 * 牌码 A/B/C/D + 1~13。大小：炸弹 > 同花顺 > 顺子 > 金花 > 对子 > 单张。
 * 正式比牌以服务端为准；本文件供看牌后本地预览。
 */

const SUIT_WEIGHT: Record<string, number> = { A: 4, B: 3, C: 2, D: 1 }

export const FLOWER_TYPE = Object.freeze({
  HIGH: 1,
  PAIR: 2,
  FLUSH: 3,
  STRAIGHT: 4,
  STRAIGHT_FLUSH: 5,
  LEOPARD: 6,
})

export interface FlowerEval {
  type: number
  label: string
  points: number[]
  pairPoint: number
  straightRank: number
  maxCard: string
}

export function facePoint(card: string): number {
  const m = String(card || '').trim().toUpperCase().match(/^[A-D](\d{1,2})$/)
  if (!m) return 0
  return Math.max(1, Math.min(13, Number(m[1])))
}

/** A=14 最大 */
export function rankPoint(face: number): number {
  return face === 1 ? 14 : face
}

export function suitOf(card: string): string {
  return String(card || '').trim().toUpperCase().charAt(0) || 'D'
}

export function typeLabel(type: number): string {
  const map: Record<number, string> = {
    1: '单张',
    2: '对子',
    3: '金花',
    4: '顺子',
    5: '同花顺',
    6: '炸弹',
  }
  return map[type] || '单张'
}

function straightRank(points: number[]): number {
  const uniq = [...new Set(points)].sort((a, b) => a - b)
  if (uniq.length !== 3) return 0
  if (uniq[0] === 1 && uniq[1] === 2 && uniq[2] === 3) return 3
  if (uniq[0] === 1 && uniq[1] === 12 && uniq[2] === 13) return 14
  if (uniq[0] + 1 === uniq[1] && uniq[1] + 1 === uniq[2]) return uniq[2]
  return 0
}

function pickMaxCard(cards: string[]): string {
  const list = [...cards]
  list.sort((x, y) => {
    const px = rankPoint(facePoint(x))
    const py = rankPoint(facePoint(y))
    if (px !== py) return py - px
    return (SUIT_WEIGHT[suitOf(y)] ?? 0) - (SUIT_WEIGHT[suitOf(x)] ?? 0)
  })
  return list[0] || ''
}

export function evaluateFlower(codes: string[]): FlowerEval {
  const cards = (codes || []).map((c) => String(c || '').trim().toUpperCase()).filter((c) => c && c !== 'BACK')
  if (cards.length !== 3) {
    return { type: FLOWER_TYPE.HIGH, label: '单张', points: [], pairPoint: 0, straightRank: 0, maxCard: cards[0] || '' }
  }
  const points = cards.map(facePoint).sort((a, b) => b - a)
  const suits = cards.map(suitOf)
  const isFlush = new Set(suits).size === 1
  const sRank = straightRank(points)
  const isStraight = sRank > 0
  const counts: Record<number, number> = {}
  for (const p of points) counts[p] = (counts[p] || 0) + 1
  let pairPoint = 0
  let triple = 0
  for (const [k, c] of Object.entries(counts)) {
    if (c === 3) triple = Number(k)
    if (c === 2) pairPoint = Number(k)
  }
  const max = pickMaxCard(cards)
  if (triple) {
    return { type: FLOWER_TYPE.LEOPARD, label: '炸弹', points, pairPoint: triple, straightRank: 0, maxCard: max }
  }
  if (isStraight && isFlush) {
    return { type: FLOWER_TYPE.STRAIGHT_FLUSH, label: '同花顺', points, pairPoint: 0, straightRank: sRank, maxCard: max }
  }
  if (isStraight) {
    return { type: FLOWER_TYPE.STRAIGHT, label: '顺子', points, pairPoint: 0, straightRank: sRank, maxCard: max }
  }
  if (isFlush) {
    return { type: FLOWER_TYPE.FLUSH, label: '金花', points, pairPoint: 0, straightRank: 0, maxCard: max }
  }
  if (pairPoint > 0) {
    return { type: FLOWER_TYPE.PAIR, label: '对子', points, pairPoint, straightRank: 0, maxCard: max }
  }
  return { type: FLOWER_TYPE.HIGH, label: '单张', points, pairPoint: 0, straightRank: 0, maxCard: max }
}

function comparePointLists(a: number[], b: number[]): number {
  const aa = a.map(rankPoint).sort((x, y) => y - x)
  const bb = b.map(rankPoint).sort((x, y) => y - x)
  const n = Math.max(aa.length, bb.length)
  for (let i = 0; i < n; i += 1) {
    const cmp = (aa[i] ?? 0) - (bb[i] ?? 0)
    if (cmp !== 0) return cmp
  }
  return 0
}

function compareMaxCard(a: FlowerEval, b: FlowerEval): number {
  const pa = rankPoint(facePoint(a.maxCard))
  const pb = rankPoint(facePoint(b.maxCard))
  if (pa !== pb) return pa - pb
  return (SUIT_WEIGHT[suitOf(a.maxCard)] ?? 0) - (SUIT_WEIGHT[suitOf(b.maxCard)] ?? 0)
}

/** 正数 a 赢。平局时正式规则为开牌方负，由服务端裁决。 */
export function compareFlower(a: FlowerEval, b: FlowerEval): number {
  if (a.type !== b.type) return a.type - b.type
  if (a.type === FLOWER_TYPE.LEOPARD) return rankPoint(a.pairPoint) - rankPoint(b.pairPoint)
  if (a.type === FLOWER_TYPE.STRAIGHT_FLUSH || a.type === FLOWER_TYPE.STRAIGHT) {
    return a.straightRank - b.straightRank || compareMaxCard(a, b)
  }
  if (a.type === FLOWER_TYPE.PAIR) {
    return rankPoint(a.pairPoint) - rankPoint(b.pairPoint) || comparePointLists(a.points, b.points) || compareMaxCard(a, b)
  }
  return comparePointLists(a.points, b.points) || compareMaxCard(a, b)
}
