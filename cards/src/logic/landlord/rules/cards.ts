// @ts-nocheck — 从 game 迁移的牌型规则，保持语义
/** 斗地主出牌规则（从 game 迁移，纯逻辑） */
/**
 * 斗地主出牌规则（仅本玩法）
 *
 * - 四带二：四张 + 两张（两单或一对，共 6 张）
 * - 四带两对：四张 + 两个对子（共 8 张）
 * - 飞机：
 * - 纯飞机：≥2 连续三张，不含 2 / 王
 * - 飞机带单：翅膀张数 = 飞机组数，且每张为独立单牌（不能用一对冒充两单）
 * - 飞机带对：翅膀对数 = 飞机组数
 * 不接受「任意张数翅膀」的宽松判定（与跑得快纯飞机规则不同，勿混用）
 *
 * 与 utils/card 的共用部分：仅复用 cardWeight / 排序工具。
 */
import { cardWeight, sortCards } from '@/logic/shared/card'

function sortAsc(cards) {
  return [...cards].sort((a, b) => cardWeight(a) - cardWeight(b))
}

function groupByWeight(cards) {
  const map = {}
  for (const c of cards) {
    const w = cardWeight(c)
    if (!map[w]) map[w] = []
    map[w].push(c)
  }
  return map
}

function isJokerCard(c) {
  if (!c) return false
  if (c.king != null) return true
  const w = cardWeight(c)
  return w === 14 || w === 15
}

/**
 * @param {Array} cards
 * @returns {null | { type: string, weight: number, length: number }}
 */
export function getCardType(cards) {
  if (!cards || cards.length === 0) return null
  const n = cards.length

  // 王炸
  if (n === 2 && cards.every(isJokerCard)) {
    return { type: 'rocket', weight: 100, length: 2 }
  }

  if (n === 1) return { type: 'single', weight: cardWeight(cards[0]), length: 1 }

  const map = groupByWeight(cards)
  const groups = Object.entries(map)
    .map(([w, list]) => ({ w: +w, c: list.length }))
    .sort((a, b) => b.c - a.c || a.w - b.w)
  const counts = groups.map((g) => g.c)

  if (n === 2 && counts[0] === 2) return { type: 'pair', weight: groups[0].w, length: 2 }
  if (n === 3 && counts[0] === 3) return { type: 'triple', weight: groups[0].w, length: 3 }
  if (n === 4 && counts[0] === 4) return { type: 'bomb', weight: groups[0].w, length: 4 }

  // 三带一
  if (n === 4 && counts[0] === 3 && counts[1] === 1) {
    return { type: 'triple1', weight: groups[0].w, length: 4 }
  }

  // 三带二（必须带一对，不能两张散牌）
  if (n === 5 && counts[0] === 3 && counts[1] === 2) {
    return { type: 'triple2', weight: groups[0].w, length: 5 }
  }

  // 四带二：四张 + 两张（两单或一对）
  if (n === 6 && counts[0] === 4) {
    const rest = groups.slice(1).reduce((sum, g) => sum + g.c, 0)
    if (rest === 2) {
      return { type: 'four2', weight: groups[0].w, length: 6 }
    }
  }

  // 四带两对：四张 + 两个对子
  if (n === 8 && counts[0] === 4) {
    const pairGroups = groups.filter((g) => g.c === 2)
    if (pairGroups.length === 2 && groups.filter((g) => g.c === 4).length === 1) {
      return { type: 'four22', weight: groups[0].w, length: 8 }
    }
  }

  // 顺子：5~12，不含 2 和王
  if (n >= 5 && n <= 12 && counts.every((c) => c === 1)) {
    const sorted = [...groups].sort((a, b) => a.w - b.w)
    if (sorted.some((g) => g.w >= 13)) return null
    for (let i = 0; i < sorted.length - 1; i++) {
      if (sorted[i + 1].w - sorted[i].w !== 1) return null
    }
    return { type: 'straight', weight: sorted[0].w, length: n }
  }

  // 连对：≥3 对，不含 2 和王
  if (n >= 6 && n % 2 === 0 && counts.every((c) => c === 2)) {
    const sorted = [...groups].sort((a, b) => a.w - b.w)
    if (sorted.some((g) => g.w >= 13)) return null
    for (let i = 0; i < sorted.length - 1; i++) {
      if (sorted[i + 1].w - sorted[i].w !== 1) return null
    }
    return { type: 'doubleStraight', weight: sorted[0].w, length: n }
  }

  // 纯飞机
  if (n >= 6 && n % 3 === 0 && counts.every((c) => c === 3)) {
    const sorted = [...groups].sort((a, b) => a.w - b.w)
    if (sorted.some((g) => g.w >= 13)) return null
    for (let i = 0; i < sorted.length - 1; i++) {
      if (sorted[i + 1].w - sorted[i].w !== 1) return null
    }
    return { type: 'plane', weight: sorted[0].w, length: n }
  }

  // 飞机带翅膀：翅膀数必须与飞机组数严格对应
  const triples = groups.filter((g) => g.c === 3).sort((a, b) => a.w - b.w)
  const pairs = groups.filter((g) => g.c === 2)
  const singles = groups.filter((g) => g.c === 1)

  if (triples.length >= 2 && !triples.some((g) => g.w >= 13)) {
    let consecutive = true
    for (let i = 0; i < triples.length - 1; i++) {
      if (triples[i + 1].w - triples[i].w !== 1) {
        consecutive = false
        break
      }
    }
    if (consecutive) {
      const tLen = triples.length
      // 带单：恰好 tLen 张独立单牌（禁止「两张散牌 / 一对充两单」等宽松写法）
      if (n === tLen * 4 && singles.length === tLen && pairs.length === 0) {
        return { type: 'plane1', weight: triples[0].w, length: n }
      }
      // 带对：恰好 tLen 个对子
      if (n === tLen * 5 && pairs.length === tLen && singles.length === 0) {
        return { type: 'plane2', weight: triples[0].w, length: n }
      }
    }
  }

  return null
}

export function canBeat(current, last) {
  if (!current || !current.length) return false
  const cur = getCardType(current)
  if (!cur) return false
  if (!last || !last.length) return true
  const prev = getCardType(last)
  if (!prev) return true
  if (cur.type === 'rocket') return true
  if (prev.type === 'rocket') return false
  if (cur.type === 'bomb' && prev.type !== 'bomb') return true
  if (prev.type === 'bomb' && cur.type !== 'bomb') return false
  return cur.type === prev.type && cur.length === prev.length && cur.weight > prev.weight
}

/**
 * 出牌本地 tip：
 * - 牌型不合法 / 跟牌类型对不上 → chuPaiBuFuHe（cpbfhgz）
 * - 牌型合法且同型（或炸对炸）但压不住 → daBuGuoShangJia（dbgsjp）
 * - 可出 → null
 */
export function playRejectTip(current, last, mustFollow) {
  const cur = getCardType(current)
  if (!cur) return 'chuPaiBuFuHe'
  if (!mustFollow || !last || !last.length) return null
  const prev = getCardType(last)
  if (!prev) return null
  if (cur.type === 'rocket') return null
  if (prev.type === 'rocket') return 'daBuGuoShangJia'
  if (cur.type === 'bomb') {
    if (prev.type !== 'bomb') return null
    return cur.weight > prev.weight ? null : 'daBuGuoShangJia'
  }
  if (prev.type === 'bomb') return 'daBuGuoShangJia'
  // 跟牌必须同型同张数，否则算不符合规则（不是「压不住」）
  if (cur.type !== prev.type || cur.length !== prev.length) return 'chuPaiBuFuHe'
  return cur.weight > prev.weight ? null : 'daBuGuoShangJia'
}

export function canFinishHand(hand, lastCards) {
  return canBeat(hand, lastCards || [])
}

/**
 * @param {Array} hand
 * @param {Array} lastCards
 * @returns {Array}
 */
export function findHint(hand, lastCards) {
  const handSorted = sortAsc(hand)
  const map = groupByWeight(handSorted)

  if (!lastCards || lastCards.length === 0) {
    return handSorted.length ? [handSorted[0]] : []
  }

  const lastType = getCardType(lastCards)
  if (!lastType) return []
  if (lastType.type === 'rocket') return []

  const found = findSameTypeBigger(map, handSorted, lastType)
  if (found.length) return found

  const bomb = findBomb(map, lastType.type === 'bomb' ? lastType.weight : -1)
  if (bomb.length) return bomb

  const rocket = findRocket(map)
  if (rocket.length) return rocket

  return []
}

function findSameTypeBigger(map, handSorted, lastType) {
  switch (lastType.type) {
    case 'single':
      return findSingleBigger(handSorted, lastType.weight)
    case 'pair':
      return findPairBigger(map, lastType.weight)
    case 'triple':
      return findTripleBigger(map, lastType.weight)
    case 'triple1':
      return findTripleWithKicker(map, handSorted, lastType.weight, 1)
    case 'triple2':
      return findTripleWithKicker(map, handSorted, lastType.weight, 2)
    case 'four2':
      return findFourWithKicker(map, handSorted, lastType.weight, 2)
    case 'four22':
      return findFourWithKicker(map, handSorted, lastType.weight, 4)
    case 'bomb':
      return findBomb(map, lastType.weight)
    case 'straight':
      return findStraight(map, lastType.length, lastType.weight)
    case 'doubleStraight':
      return findDoubleStraight(map, lastType.length / 2, lastType.weight)
    case 'plane':
      return findPlane(map, lastType.length / 3, lastType.weight)
    case 'plane1':
      return findPlaneWithKicker(map, handSorted, lastType.length / 4, lastType.weight, 1)
    case 'plane2':
      return findPlaneWithKicker(map, handSorted, lastType.length / 5, lastType.weight, 2)
    default:
      return []
  }
}

function findSingleBigger(handSorted, w) {
  for (const c of handSorted) {
    if (cardWeight(c) > w) return [c]
  }
  return []
}

function findPairBigger(map, w) {
  for (const wkStr of Object.keys(map)
    .map(Number)
    .sort((a, b) => a - b)) {
    if (wkStr <= w) continue
    if (map[wkStr].length >= 2) return map[wkStr].slice(0, 2)
  }
  return []
}

function findTripleBigger(map, w) {
  for (const wk of Object.keys(map)
    .map(Number)
    .sort((a, b) => a - b)) {
    if (wk <= w) continue
    if (map[wk].length >= 3) return map[wk].slice(0, 3)
  }
  return []
}

function findTripleWithKicker(map, handSorted, w, kickerCount) {
  for (const wk of Object.keys(map)
    .map(Number)
    .sort((a, b) => a - b)) {
    if (wk <= w) continue
    if (map[wk].length < 3) continue
    const triple = map[wk].slice(0, 3)
    if (kickerCount === 1) {
      for (const c of handSorted) {
        if (cardWeight(c) !== wk) return [...triple, c]
      }
    } else if (kickerCount === 2) {
      for (const wk2 of Object.keys(map)
        .map(Number)
        .sort((a, b) => a - b)) {
        if (wk2 === wk) continue
        if (map[wk2].length >= 2) return [...triple, ...map[wk2].slice(0, 2)]
      }
    }
  }
  return []
}

/** 四带二(kicker=2) / 四带两对(kicker=4，须两对) */
function findFourWithKicker(map, handSorted, w, kickerCount) {
  for (const wk of Object.keys(map)
    .map(Number)
    .sort((a, b) => a - b)) {
    if (wk <= w) continue
    if (map[wk].length < 4) continue
    const four = map[wk].slice(0, 4)
    if (kickerCount === 2) {
      // 优先一对，否则两张散牌
      for (const wk2 of Object.keys(map)
        .map(Number)
        .sort((a, b) => a - b)) {
        if (wk2 === wk) continue
        if (map[wk2].length >= 2) return [...four, ...map[wk2].slice(0, 2)]
      }
      const kickers = []
      for (const c of handSorted) {
        if (cardWeight(c) === wk) continue
        kickers.push(c)
        if (kickers.length === 2) return [...four, ...kickers]
      }
    } else if (kickerCount === 4) {
      const pairWs = Object.keys(map)
        .map(Number)
        .filter((p) => p !== wk && map[p].length >= 2)
        .sort((a, b) => a - b)
      if (pairWs.length >= 2) {
        return [...four, ...map[pairWs[0]].slice(0, 2), ...map[pairWs[1]].slice(0, 2)]
      }
    }
  }
  return []
}

function findBomb(map, w) {
  for (const wk of Object.keys(map)
    .map(Number)
    .sort((a, b) => a - b)) {
    if (wk <= w) continue
    if (map[wk].length === 4) return map[wk].slice()
  }
  return []
}

function findRocket(map) {
  if (map[14] && map[15]) return [...map[14], ...map[15]]
  return []
}

function findStraight(map, len, w) {
  for (let start = w + 1; start + len - 1 <= 12; start++) {
    let ok = true
    for (let i = 0; i < len; i++) {
      if (!map[start + i] || map[start + i].length < 1) {
        ok = false
        break
      }
    }
    if (ok) {
      const result = []
      for (let i = 0; i < len; i++) result.push(map[start + i][0])
      return result
    }
  }
  return []
}

function findDoubleStraight(map, pairCount, w) {
  for (let start = w + 1; start + pairCount - 1 <= 12; start++) {
    let ok = true
    for (let i = 0; i < pairCount; i++) {
      if (!map[start + i] || map[start + i].length < 2) {
        ok = false
        break
      }
    }
    if (ok) {
      const result = []
      for (let i = 0; i < pairCount; i++) result.push(...map[start + i].slice(0, 2))
      return result
    }
  }
  return []
}

function findPlane(map, tripleCount, w) {
  for (let start = w + 1; start + tripleCount - 1 <= 12; start++) {
    let ok = true
    for (let i = 0; i < tripleCount; i++) {
      if (!map[start + i] || map[start + i].length < 3) {
        ok = false
        break
      }
    }
    if (ok) {
      const result = []
      for (let i = 0; i < tripleCount; i++) result.push(...map[start + i].slice(0, 3))
      return result
    }
  }
  return []
}

function findPlaneWithKicker(map, handSorted, tripleCount, w, kickerCount) {
  for (let start = w + 1; start + tripleCount - 1 <= 12; start++) {
    let ok = true
    for (let i = 0; i < tripleCount; i++) {
      if (!map[start + i] || map[start + i].length < 3) {
        ok = false
        break
      }
    }
    if (!ok) continue

    const triplesWeights = new Set()
    for (let i = 0; i < tripleCount; i++) triplesWeights.add(start + i)
    const main = []
    for (let i = 0; i < tripleCount; i++) main.push(...map[start + i].slice(0, 3))

    const kickers = []
    if (kickerCount === 1) {
      for (const c of handSorted) {
        if (triplesWeights.has(cardWeight(c))) continue
        if (kickers.find((k) => cardWeight(k) === cardWeight(c))) continue
        kickers.push(c)
        if (kickers.length === tripleCount) break
      }
      if (kickers.length === tripleCount) return [...main, ...kickers]
    } else {
      const pairWs = Object.keys(map)
        .map(Number)
        .filter((wk) => !triplesWeights.has(wk) && map[wk].length >= 2)
        .sort((a, b) => a - b)
      if (pairWs.length >= tripleCount) {
        for (let i = 0; i < tripleCount; i++) {
          kickers.push(...map[pairWs[i]].slice(0, 2))
        }
        return [...main, ...kickers]
      }
    }
  }
  return []
}

export { sortCards }
