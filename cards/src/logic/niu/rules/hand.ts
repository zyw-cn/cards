/**
 * 斗牛牌型（对齐 hyperf App\Game\Niu\Card\NiuCard）
 * 牌码：A/B/C/D + 1~13（1=A … 13=K）；算牛时 J/Q/K 计 10。
 */

import { assetUrl } from '@/services/assetUrl'

const SUIT_WEIGHT: Record<string, number> = { A: 4, B: 3, C: 2, D: 1 }

export const NIU_TYPE = Object.freeze({
  NONE: 0,
  NIU_NIU: 10,
  FIVE_FLOWER: 11,
  BOMB: 12,
  FIVE_SMALL: 13,
})

export interface NiuEval {
  type: number
  label: string
  multiple: number
  maxCard: string
}

export interface NiuMultipliers {
  niu_niu?: number
  niu_9?: number
  niu_8?: number
  five_flower?: number
  bomb?: number
  five_small?: number
}

const DEFAULT_MULT: Required<NiuMultipliers> = {
  niu_niu: 4,
  niu_9: 3,
  niu_8: 2,
  five_flower: 5,
  bomb: 6,
  five_small: 8,
}

export function facePoint(card: string): number {
  const m = String(card || '').trim().toUpperCase().match(/^[A-D](\d{1,2})$/)
  if (!m) return 0
  return Math.max(1, Math.min(13, Number(m[1])))
}

export function niuPoint(card: string): number {
  const p = facePoint(card)
  return p >= 10 ? 10 : p
}

export function suitOf(card: string): string {
  const c = String(card || '').trim().toUpperCase()
  return c.charAt(0) || 'D'
}

export function typeLabel(type: number): string {
  if (type === NIU_TYPE.FIVE_SMALL) return '五小牛'
  if (type === NIU_TYPE.BOMB) return '炸弹牛'
  if (type === NIU_TYPE.FIVE_FLOWER) return '五花牛'
  if (type <= 0) return '无牛'
  if (type >= NIU_TYPE.NIU_NIU) return '牛牛'
  const map = ['', '一', '二', '三', '四', '五', '六', '七', '八', '九']
  return `牛${map[type] || type}`
}

export function typeMultiple(type: number, multipliers: NiuMultipliers = {}): number {
  const rules = { ...DEFAULT_MULT, ...multipliers }
  if (type === NIU_TYPE.FIVE_SMALL) return Math.max(1, Number(rules.five_small) || 1)
  if (type === NIU_TYPE.BOMB) return Math.max(1, Number(rules.bomb) || 1)
  if (type === NIU_TYPE.FIVE_FLOWER) return Math.max(1, Number(rules.five_flower) || 1)
  if (type === NIU_TYPE.NIU_NIU) return Math.max(1, Number(rules.niu_niu) || 1)
  if (type === 9) return Math.max(1, Number(rules.niu_9) || 1)
  if (type === 8) return Math.max(1, Number(rules.niu_8) || 1)
  return 1
}

export function niuSfxKey(type: number): string {
  const t = Number(type)
  if (t === NIU_TYPE.FIVE_SMALL) return 'wuxiao'
  if (t === NIU_TYPE.BOMB) return 'zhadan'
  if (t === NIU_TYPE.FIVE_FLOWER) return 'wuhua'
  if (t === NIU_TYPE.NIU_NIU) return 'niuniu'
  if (t >= 0 && t <= 9) return `niu${t}`
  return 'niu0'
}

/** 牛型徽章（public/assets/images/niu） */
export function niuBadgeUrl(niuType: number): string {
  const t = Number(niuType)
  const map: Record<number, string> = {
    0: 'images/niu/nm.png',
    1: 'images/niu/n1.png',
    2: 'images/niu/n2.png',
    3: 'images/niu/n3.png',
    4: 'images/niu/n4.png',
    5: 'images/niu/n5.png',
    6: 'images/niu/n6.png',
    7: 'images/niu/n7.png',
    8: 'images/niu/n8.png',
    9: 'images/niu/n9.png',
    10: 'images/niu/nn.png',
    11: 'images/niu/nwh.png',
    12: 'images/niu/niu12.png',
    13: 'images/niu/nwx.png',
  }
  return assetUrl(map[t] || 'images/niu/nm.png')
}

export function maxCard(cards: string[]): string {
  let best = ''
  let bestFace = -1
  let bestSuit = -1
  for (const c of cards) {
    const face = facePoint(c)
    const sw = SUIT_WEIGHT[suitOf(c)] ?? 0
    if (face > bestFace || (face === bestFace && sw > bestSuit)) {
      best = c
      bestFace = face
      bestSuit = sw
    }
  }
  return best
}

function specialType(cards: string[]): number {
  const points = cards.map(facePoint)
  const niuPoints = cards.map(niuPoint)
  if (Math.max(...niuPoints) <= 5 && niuPoints.reduce((s, n) => s + n, 0) <= 10) {
    return NIU_TYPE.FIVE_SMALL
  }
  const counts: Record<number, number> = {}
  for (const p of points) counts[p] = (counts[p] || 0) + 1
  if (Math.max(...Object.values(counts), 0) >= 4) return NIU_TYPE.BOMB
  if (Math.min(...points) >= 11) return NIU_TYPE.FIVE_FLOWER
  return NIU_TYPE.NONE
}

/** 评估 5 张。特殊牌型优先，否则取能组成的最高普通牛型。 */
export function evaluateNiu(codes: string[], multipliers: NiuMultipliers = {}): NiuEval {
  const cards = (codes || []).map((c) => String(c || '').trim().toUpperCase()).filter((c) => c && c !== 'BACK')
  if (cards.length !== 5) {
    return { type: NIU_TYPE.NONE, label: '无牛', multiple: 1, maxCard: cards[0] || '' }
  }
  let type = specialType(cards)
  if (type === NIU_TYPE.NONE) {
    const n = cards.length
    for (let i = 0; i < n; i += 1) {
      for (let j = i + 1; j < n; j += 1) {
        for (let k = j + 1; k < n; k += 1) {
          const sum3 = niuPoint(cards[i]) + niuPoint(cards[j]) + niuPoint(cards[k])
          if (sum3 % 10 !== 0) continue
          let rest = 0
          for (let t = 0; t < n; t += 1) {
            if (t === i || t === j || t === k) continue
            rest += niuPoint(cards[t])
          }
          const niu = rest % 10
          const cur = niu === 0 ? NIU_TYPE.NIU_NIU : niu
          if (cur > type) type = cur
        }
      }
    }
  }
  return {
    type,
    label: typeLabel(type),
    multiple: typeMultiple(type, multipliers),
    maxCard: maxCard(cards),
  }
}

/** 正数 a 赢，负数 b 赢，0 平。顺序：牛型 → 最大牌点数 → 花色。 */
export function compareNiu(a: NiuEval, b: NiuEval): number {
  if (a.type !== b.type) return a.type - b.type
  const fa = facePoint(a.maxCard)
  const fb = facePoint(b.maxCard)
  if (fa !== fb) return fa - fb
  return (SUIT_WEIGHT[suitOf(a.maxCard)] ?? 0) - (SUIT_WEIGHT[suitOf(b.maxCard)] ?? 0)
}
