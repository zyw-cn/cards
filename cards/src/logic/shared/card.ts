import { cardPoint, toServerCode, type ClientCard } from './serverCard'

export type CardLike = Partial<ClientCard> & {
  wild?: boolean
  code?: string
  king?: number
  value?: number
  shape?: number
}

const SHAPE_TO_SUIT = Object.freeze({ 1: 'A', 2: 'B', 3: 'C', 4: 'D' }) as Record<number, string>

function isWildLike(card: CardLike | null | undefined): boolean {
  if (!card) return false
  if (card.wild) return true
  const c = String(card.code || '').toUpperCase()
  return c === 'F55' || c === 'F' || c === 'W55' || c === 'LAIZI' || c === '55'
}

/**
 * 牌对象 → Pixi 图集帧名（与服务端协议码一致）
 * A14=黑桃A，A15=黑桃2，E16/E17，F55，BACK
 */
export function cardAtlasFrame(card: CardLike | null | undefined): string {
  if (!card) return 'BACK'
  if (isWildLike(card)) return 'F55'
  if (card.king === 15) return 'E17'
  if (card.king === 14) return 'E16'

  const raw = String(card.code || '').trim().toUpperCase()
  if (!raw || raw === 'BACK' || raw === '0') return 'BACK'
  if (raw === 'E16' || raw === 'JS' || raw === 'SJ') return 'E16'
  if (raw === 'E17' || raw === 'JB' || raw === 'BJ') return 'E17'
  if (raw === 'F55' || raw === 'F') return 'F55'

  // 兼容旧雪碧 A_1 → 协议 A14
  const faceAlias = raw.match(/^([ABCD])_([0-9]{1,2})$/)
  if (faceAlias) {
    const suit = faceAlias[1]
    const face = Number(faceAlias[2])
    if (face === 1) return `${suit}14`
    if (face === 2) return `${suit}15`
    if (face >= 3 && face <= 13) return `${suit}${face}`
  }

  const code = toServerCode(raw)
  if (/^[ABCD](?:[3-9]|1[0-5])$/.test(code)) return code
  if (code === 'E16' || code === 'E17' || code === 'F55') return code

  if (card.shape != null) {
    const suit = SHAPE_TO_SUIT[card.shape]
    const p = cardPoint(card)
    if (suit && p >= 3 && p <= 15) return `${suit}${p}`
  }
  return 'BACK'
}

export function cardWeight(card: CardLike | null | undefined): number {
  if (!card) return 0
  if (isWildLike(card)) return 18
  if (card.king != null) return Number(card.king) || 0
  if (card.value != null && Number.isFinite(Number(card.value))) return Number(card.value)
  const p = cardPoint(card)
  if (p >= 3 && p <= 15) return p - 2
  if (p === 16) return 14
  if (p === 17) return 15
  if (p === 55) return 18
  return 0
}

export function ddzSortRank(card: CardLike | null | undefined): number {
  if (!card) return 0
  if (isWildLike(card)) return 16
  if (card.king === 15 || Number(card.point) === 17) return 15
  if (card.king === 14 || Number(card.point) === 16) return 14
  const p = cardPoint(card)
  if (p === 17) return 15
  if (p === 16) return 14
  if (p >= 3 && p <= 15) return p - 2
  if (card.value != null && Number.isFinite(Number(card.value))) return Number(card.value)
  return 0
}

export function sortCards<T extends CardLike>(cards: T[]): T[] {
  return [...cards].sort((a, b) => ddzSortRank(a) - ddzSortRank(b))
}
