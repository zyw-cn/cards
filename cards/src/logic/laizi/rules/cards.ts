// @ts-nocheck — 从 game 迁移的癞子牌型规则，保持语义
/**
 * 癞子斗地主牌型（前端提示 / 展示）
 * 对齐后端 App\Game\Lz\Card\LaiziCard
 * 编码：A/B/C/D+3~15；E16/E17；F55/W55 癞子
 *
 * 飞机：连续三条 + 翅膀 0 / 组数张单 / 组数对（与经典斗地主相近，含癞子替换；
 * 与跑得快「中途只能纯飞机」不同，请走本模块，勿混用 pdk/rules）
 * 癞子可配 3~2，不能单出，不能和王出现在同一手。
 */

export const WILD = 'F55'
export const WILD_SINGLE_WEIGHT = 18

export const TYPE = Object.freeze({
  SINGLE: '单张',
  PAIR: '对子',
  TRIPLE: '三张',
  TRIPLE_ONE: '三带一',
  TRIPLE_TWO: '三带二',
  FOUR_TWO: '四带二',
  FOUR_TWO_PAIR: '四带两对',
  STRAIGHT: '顺子',
  SEQUENCE_PAIR: '连对',
  PLANE: '飞机',
  BOMB: '炸弹',
  ROCKET: '王炸',
  INVALID: '无效牌型',
})

function codeOf(card) {
  if (card == null) return ''
  if (typeof card === 'string' || typeof card === 'number') return String(card).trim().toUpperCase()
  return String(card.code || '').trim().toUpperCase()
}

export function isWild(card) {
  const c = codeOf(card)
  return c === WILD || c === 'W55' || c === 'F55' || c === 'LAIZI' || c === '55'
}

export function toPoint(card) {
  if (isWild(card)) return 55
  const c = codeOf(card)
  if (!c) return 0
  if (/^\d+$/.test(c)) return Number(c)
  return Number(c.slice(1)) || 0
}

function analyzeNatural(points, len) {
  if (!points.length) return [TYPE.INVALID, 0, len, false]
  const countMap = {}
  points.forEach((p) => {
    countMap[p] = (countMap[p] || 0) + 1
  })
  const values = Object.keys(countMap)
    .map(Number)
    .sort((a, b) => a - b)
  const counts = values.map((v) => countMap[v])
  const maxCount = Math.max(...counts)

  if (len === 2 && points.includes(16) && points.includes(17)) {
    return [TYPE.ROCKET, 17, 2, false]
  }
  if (len === 4 && values.length === 1 && maxCount === 4) {
    return [TYPE.BOMB, values[0], 4, false]
  }
  if (len === 1) return [TYPE.SINGLE, values[0], 1, false]
  if (len === 2 && values.length === 1) return [TYPE.PAIR, values[0], 2, false]
  if (len === 3 && values.length === 1) return [TYPE.TRIPLE, values[0], 3, false]

  if (len === 4 && counts.includes(3) && counts.includes(1)) {
    const base = values.find((v) => countMap[v] === 3)
    return [TYPE.TRIPLE_ONE, base, 4, false]
  }
  if (len === 5 && counts.includes(3) && counts.includes(2)) {
    const base = values.find((v) => countMap[v] === 3)
    return [TYPE.TRIPLE_TWO, base, 5, false]
  }

  // 四带二：四张 + 两张（两单或一对）
  if (len === 6 && maxCount === 4) {
    const base = values.find((v) => countMap[v] === 4)
    const rest = len - 4
    if (rest === 2 && base != null) {
      return [TYPE.FOUR_TWO, base, 6, false]
    }
  }

  // 四带两对：四张 + 两个对子
  if (len === 8 && maxCount === 4) {
    const base = values.find((v) => countMap[v] === 4)
    const pairs = values.filter((v) => countMap[v] === 2)
    if (base != null && pairs.length === 2 && values.every((v) => countMap[v] === 4 || countMap[v] === 2)) {
      return [TYPE.FOUR_TWO_PAIR, base, 8, false]
    }
  }

  if (len >= 5 && values.length === len && maxCount === 1) {
    const min = Math.min(...values)
    const max = Math.max(...values)
    if (max <= 14 && max - min + 1 === len) {
      return [TYPE.STRAIGHT, max, len, false]
    }
  }

  if (len >= 6 && len % 2 === 0 && counts.every((c) => c === 2)) {
    const min = Math.min(...values)
    const max = Math.max(...values)
    const pairCount = len / 2
    if (max <= 14 && max - min + 1 === pairCount) {
      return [TYPE.SEQUENCE_PAIR, max, len, false]
    }
  }

  if (maxCount >= 3) {
    const triples = values.filter((v) => countMap[v] >= 3).sort((a, b) => a - b)
    for (let span = triples.length; span >= 2; span -= 1) {
      for (let i = 0; i <= triples.length - span; i += 1) {
        const slice = triples.slice(i, i + span)
        if (Math.max(...slice) > 14) continue
        if (Math.max(...slice) - Math.min(...slice) + 1 !== span) continue
        const body = span * 3
        const wing = len - body
        if (wing === 0 || wing === span || wing === span * 2) {
          return [TYPE.PLANE, Math.max(...slice), len, false]
        }
      }
    }
  }

  return [TYPE.INVALID, 0, len, false]
}

function typeRank(t) {
  const map = {
    [TYPE.ROCKET]: 100,
    [TYPE.BOMB]: 90,
    [TYPE.PLANE]: 80,
    [TYPE.STRAIGHT]: 70,
    [TYPE.SEQUENCE_PAIR]: 60,
    [TYPE.FOUR_TWO_PAIR]: 55,
    [TYPE.FOUR_TWO]: 52,
    [TYPE.TRIPLE_TWO]: 50,
    [TYPE.TRIPLE_ONE]: 40,
    [TYPE.TRIPLE]: 30,
    [TYPE.PAIR]: 20,
    [TYPE.SINGLE]: 10,
  }
  return map[t] || 0
}

function isBetterInterpretation(a, b) {
  if (b[0] === TYPE.INVALID) return true
  const ra = typeRank(a[0])
  const rb = typeRank(b[0])
  if (ra !== rb) return ra > rb
  return a[1] > b[1]
}

/** @returns {[string, number, number, boolean]} */
export function analyzeCards(cards) {
  const list = (cards || []).map(codeOf).filter(Boolean)
  const len = list.length
  if (!len) return [TYPE.INVALID, 0, 0, false]

  let wilds = 0
  const normals = []
  list.forEach((c) => {
    if (isWild(c)) wilds += 1
    else normals.push(toPoint(c))
  })

  if (
    len === 2 &&
    wilds === 0 &&
    normals.includes(16) &&
    normals.includes(17)
  ) {
    return [TYPE.ROCKET, 17, 2, false]
  }
  if (len === 1 && wilds === 1) {
    return [TYPE.INVALID, 0, 1, false]
  }
  if (wilds > 0 && (normals.includes(16) || normals.includes(17))) {
    return [TYPE.INVALID, 0, len, false]
  }
  if (wilds === 0) return analyzeNatural(normals, len)

  let best = [TYPE.INVALID, 0, len, true]
  for (let p = 3; p <= 15; p += 1) {
    const expanded = normals.slice()
    for (let i = 0; i < wilds; i += 1) expanded.push(p)
    const cand = analyzeNatural(expanded, len)
    if (cand[0] === TYPE.INVALID) continue
    cand[3] = cand[0] === TYPE.BOMB
    if (isBetterInterpretation(cand, best)) best = cand
  }
  return best
}

export function isBigger(current, last) {
  if (!last || !last.length) {
    return analyzeCards(current)[0] !== TYPE.INVALID
  }
  const cur = analyzeCards(current)
  const prev = analyzeCards(last)
  if (cur[0] === TYPE.INVALID) return false
  if (prev[0] === TYPE.INVALID) return true

  const curSoft = !!cur[3]
  const prevSoft = !!prev[3]

  if (cur[0] === TYPE.ROCKET) return true
  if (prev[0] === TYPE.ROCKET) return false

  if (cur[0] === TYPE.BOMB && prev[0] !== TYPE.BOMB) return true
  if (prev[0] === TYPE.BOMB && cur[0] !== TYPE.BOMB) return false

  // 炸弹间：先比点数，同点时硬炸大于软炸
  if (cur[0] === TYPE.BOMB && prev[0] === TYPE.BOMB) {
    if (cur[1] !== prev[1]) return cur[1] > prev[1]
    return !curSoft && prevSoft
  }

  if (cur[0] === prev[0] && cur[2] === prev[2]) return cur[1] > prev[1]
  return false
}

function enumerateSubsets(hand, need, start, picked, visitor) {
  if (picked.length === need) {
    visitor(picked.slice())
    return
  }
  const left = need - picked.length
  for (let i = start; i <= hand.length - left; i += 1) {
    picked.push(hand[i])
    enumerateSubsets(hand, need, i + 1, picked, visitor)
    picked.pop()
  }
}

/**
 * 提示出牌：返回牌对象数组（与 hand 同源引用）
 * @param {Array} handCards 手牌对象
 * @param {Array} lastCards 上手牌（对象或码）
 */
export function findHint(handCards, lastCards = []) {
  const hand = Array.isArray(handCards) ? handCards.slice() : []
  if (!hand.length) return []

  const last = Array.isArray(lastCards) ? lastCards : []
  if (!last.length) {
    const sorted = hand.slice().sort((a, b) => {
      const pa = isWild(a) ? WILD_SINGLE_WEIGHT : toPoint(a)
      const pb = isWild(b) ? WILD_SINGLE_WEIGHT : toPoint(b)
      return pa - pb
    })
    const lead = sorted.find((c) => analyzeCards([c])[0] !== TYPE.INVALID)
    return lead ? [lead] : []
  }

  const lastType = analyzeCards(last)[0]
  if (lastType === TYPE.INVALID || lastType === TYPE.ROCKET) return []

  const lastLen = last.length
  const lengths = [lastLen]
  if (lastType !== TYPE.BOMB) lengths.push(4)
  lengths.push(2)

  let best = []
  let bestScore = Number.POSITIVE_INFINITY
  const uniq = [...new Set(lengths)]

  uniq.forEach((need) => {
    if (need <= 0 || need > hand.length || need > 8) return
    enumerateSubsets(hand, need, 0, [], (subset) => {
      if (!isBigger(subset, last)) return
      const info = analyzeCards(subset)
      let score = subset.length * 10000 + Number(info[1] || 0)
      if (info[3]) score += 500
      if (score < bestScore) {
        bestScore = score
        best = subset
      }
    })
  })

  return best
}

/** 兼容 shared/card.getCardType 返回形态 */
export function getCardType(cards) {
  const [type, weight, len, soft] = analyzeCards(cards)
  if (type === TYPE.INVALID) return null
  return { type, weight, len, soft: !!soft }
}

/** 整手剩牌能否一次合法出完（领出或压过上手） */
export function canFinishHand(hand, lastCards) {
  if (!hand || !hand.length) return false
  return isBigger(hand, lastCards || [])
}
