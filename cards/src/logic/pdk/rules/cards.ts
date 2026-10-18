// @ts-nocheck — 从 game 迁移的跑得快牌型规则，保持语义
/**
 * 跑得快牌型识别与出牌提示（与 app/Game/Pdk/Card/PdkCard.php 保持一致）
 *
 * 光山跑得快（勿与斗地主混用）：
 * - 三带一随时可出；三带二必须是一对；三不带只能最后一手
 * - 飞机不带只能最后一手；带单、带对随时可出。没有甩尾只带一张
 * - 四带二单、四带两对（不算炸弹）；没有四带一、四带三
 * - 无王炸；连对≥2 对；顺子/连对/飞机主体不含 2；三个 A 可压炸弹
 *
 * 点数：3~13=3~K，14=A，15=2
 */
import { cardPoint } from '@/logic/shared/serverCard'

const ACE_POINT = 14
/** 顺子/连对/飞机不能含 2，最大到 A */
const CHAIN_MAX = ACE_POINT
const CHAIN_MIN = 3
/** 三个 A 的比较权重，高于 2（15）与任何炸弹 */
const TRIPLE_ACE_WEIGHT = 100

const TYPE_LABEL = {
  single: '单张',
  pair: '对子',
  triple: '三不带',
  triple1: '三带一',
  triple2: '三带二', // 必须一对
  four2: '四带二单',
  four2pair: '四带两对',
  planeTail: '飞机甩尾',
  straight: '顺子',
  doubleStraight: '连对',
  plane: '飞机',
  bomb: '炸弹',
}

export function cardTypeLabel(type) {
  if (!type) return ''
  return TYPE_LABEL[type.type] || type.type
}

function tripleWeight(point) {
  return point === ACE_POINT ? TRIPLE_ACE_WEIGHT : point
}

/** 三个 A（单纯三张，不含带牌）：可压一切 */
export function isTripleAce(type) {
  return !!type && type.type === 'triple' && type.weight === TRIPLE_ACE_WEIGHT
}

function groupByPoint(cards) {
  const map = {}
  for (const c of cards) {
    const p = cardPoint(c)
    if (p <= 0) continue
    if (!map[p]) map[p] = []
    map[p].push(c)
  }
  return map
}

function sortAsc(cards) {
  return [...cards].sort((a, b) => cardPoint(a) - cardPoint(b))
}

function pointsAsc(map) {
  return Object.keys(map)
    .map(Number)
    .sort((a, b) => a - b)
}

/** 点数组里最长的连续段 */
function longestTripleRun(triplePoints) {
  if (!triplePoints.length) return []
  let best = []
  let run = []
  for (const p of triplePoints) {
    if (!run.length || p === run[run.length - 1] + 1) run.push(p)
    else {
      if (run.length > best.length) best = run
      run = [p]
    }
  }
  if (run.length > best.length) best = run
  return best
}

/**
 * 识别牌型
 * @param {Array} cards
 * @param {{ isLastHand?: boolean }} [opts] 保留参数。三不带是否允许由出牌校验看是不是最后一手
 * @returns {null | { type: string, weight: number, length: number, wing?: string }}
 */
export function getCardType(cards, opts = {}) {
  if (!cards || cards.length === 0) return null
  void opts
  const n = cards.length
  const map = groupByPoint(cards)
  const pointKeys = pointsAsc(map)
  if (!pointKeys.length) return null

  const groups = pointKeys.map((p) => ({ p, c: map[p].length }))
  const counts = groups.map((g) => g.c)
  const maxCount = Math.max(...counts)

  if (n === 4 && groups.length === 1) return { type: 'bomb', weight: groups[0].p, length: 4 }
  if (n === 1) return { type: 'single', weight: groups[0].p, length: 1 }
  if (n === 2 && groups.length === 1) return { type: 'pair', weight: groups[0].p, length: 2 }
  if (n === 3 && groups.length === 1) {
    return { type: 'triple', weight: tripleWeight(groups[0].p), length: 3 }
  }

  // 三带一：三张 + 一张单牌
  if (n === 4 && maxCount === 3 && !counts.includes(4)) {
    const base = groups.find((g) => g.c === 3)
    const kick = groups.filter((g) => g.p !== base.p)
    if (kick.length === 1 && kick[0].c === 1) {
      return { type: 'triple1', weight: tripleWeight(base.p), length: 4 }
    }
  }

  // 三带二：三张 + 一对。两张零牌不算
  if (n === 5 && maxCount === 3 && !counts.includes(4)) {
    const base = groups.find((g) => g.c === 3)
    const kick = groups.filter((g) => g.p !== base.p)
    if (kick.length === 1 && kick[0].c === 2) {
      return { type: 'triple2', weight: tripleWeight(base.p), length: 5 }
    }
  }

  // 四带二单：四张 + 两张不同的单牌，一对不算
  if (n === 6 && maxCount === 4) {
    const base = groups.find((g) => g.c === 4)
    const kick = groups.filter((g) => g.p !== base.p)
    if (kick.length === 2 && kick.every((g) => g.c === 1)) {
      return { type: 'four2', weight: base.p, length: 6 }
    }
  }

  // 四带两对
  if (n === 8 && maxCount === 4) {
    const base = groups.find((g) => g.c === 4)
    const kick = groups.filter((g) => g.p !== base.p)
    if (kick.length === 2 && kick.every((g) => g.c === 2)) {
      return { type: 'four2pair', weight: base.p, length: 8 }
    }
  }

  const min = groups[0].p
  const max = groups[groups.length - 1].p

  // 顺子：≥5 张单牌连续，不含 2
  if (n >= 5 && groups.length === n && max <= CHAIN_MAX && max - min + 1 === n) {
    return { type: 'straight', weight: max, length: n }
  }

  // 连对：≥2 对连续，不含 2
  if (n >= 4 && n % 2 === 0 && counts.every((c) => c === 2)) {
    const pairCount = n / 2
    if (max <= CHAIN_MAX && max - min + 1 === pairCount) {
      return { type: 'doubleStraight', weight: max, length: n }
    }
  }

  const plane = matchPlane(groups, n)
  if (plane) return plane

  return null
}

/** 飞机：不带 / 每组一张单 / 每组一对。翅膀可以含 2，主体不能含 2。 */
function matchPlane(groups, n) {
  const bodyRanks = groups.filter((g) => g.c >= 3 && g.p >= CHAIN_MIN && g.p <= CHAIN_MAX).map((g) => g.p)
  const run = longestTripleRun(bodyRanks)
  const runLen = run.length
  if (runLen < 2) return null
  const inRun = new Set(run)
  const wings = []
  for (const g of groups) {
    const extra = inRun.has(g.p) ? g.c - 3 : g.c
    if (extra < 0) return null
    if (extra > 0) wings.push(extra)
  }
  const wingCards = wings.reduce((sum, c) => sum + c, 0)
  if (n !== runLen * 3 + wingCards) return null
  const tMax = run[runLen - 1]
  const weight = tripleWeight(tMax)
  const base = { weight, length: n, runLen }
  if (wings.length === 0) {
    return { type: 'plane', wing: 'none', ...base }
  }
  if (wings.length === runLen && wingCards === runLen && wings.every((c) => c === 1)) {
    return { type: 'planeSingle', wing: 'single', ...base }
  }
  if (wings.length === runLen && wingCards === runLen * 2 && wings.every((c) => c === 2)) {
    return { type: 'planePair', wing: 'pair', ...base }
  }
  return null
}

function isPlaneType(type) {
  return type === 'plane' || type === 'planeSingle' || type === 'planePair' || type === 'planeTail'
}

/**
 * 当前牌是否压过上家
 * @param {Array} current
 * @param {Array} last
 * @param {{ isLastHand?: boolean }} [opts]
 */
export function canBeat(current, last, opts = {}) {
  if (!current?.length) return false
  const cur = getCardType(current, opts)
  if (!cur) return false
  if (!last?.length) return true
  const prev = getCardType(last)
  if (!prev) return true

  // 三个 A = 最大炸弹：压一切；任何牌（含炸弹）都压不过三个 A
  if (isTripleAce(cur)) return true
  if (isTripleAce(prev)) return false
  if (cur.type === 'bomb' && prev.type !== 'bomb') return true
  if (prev.type === 'bomb' && cur.type !== 'bomb') return false

  if (isPlaneType(cur.type) && isPlaneType(prev.type)) {
    if (!cur.runLen || cur.runLen !== prev.runLen) return false
    if (cur.type !== prev.type) return false
    return cur.weight > prev.weight
  }

  // 同型同张数才可比，带牌不参与比较
  if (cur.type === prev.type && cur.length === prev.length) {
    return cur.weight > prev.weight
  }
  return false
}

/**
 * 出牌提示
 * @param {Array} hand
 * @param {Array} lastCards
 * @param {boolean|{forceLargestSingle?:boolean}} opts
 */
export function findHint(hand, lastCards, opts = false) {
  const forceLargestSingle =
    typeof opts === 'boolean' ? opts : !!opts?.forceLargestSingle
  const handSorted = sortAsc(hand || [])
  const map = groupByPoint(handSorted)

  if (!lastCards || lastCards.length === 0) {
    if (!handSorted.length) return []
    const allType = getCardType(handSorted, { isLastHand: true })
    if (allType) return handSorted
    return [forceLargestSingle ? handSorted[handSorted.length - 1] : handSorted[0]]
  }

  const last = getCardType(lastCards)
  if (!last) return []
  if (isTripleAce(last)) return []

  let same = []
  if (forceLargestSingle && last.type === 'single') {
    const largest = handSorted[handSorted.length - 1]
    if (largest && cardPoint(largest) > last.weight) same = [largest]
  } else {
    same = findSameTypeBigger(map, handSorted, last)
  }
  if (same.length) return same

  const bomb = findBomb(map, last.type === 'bomb' ? last.weight : -1)
  if (bomb.length) return bomb

  // 三个 A 只有剩这三张时才能当最大炸弹打出
  if (handSorted.length !== 3) return []
  return findTripleAce(map)
}

function findSameTypeBigger(map, handSorted, last) {
  switch (last.type) {
    case 'single':
      return findSingleBigger(handSorted, last.weight)
    case 'pair':
      return findNOfAKind(map, last.weight, 2)
    case 'triple':
      if (handSorted.length !== 3) return []
      return findNOfAKind(map, last.weight, 3)
    case 'triple1':
      return findTripleWithKicker(map, handSorted, last.weight, 1)
    case 'triple2':
      return findTripleWithKicker(map, handSorted, last.weight, 2)
    case 'four2':
      return findFourWithKickers(map, handSorted, last.weight, 'two')
    case 'four2pair':
      return findFourWithKickers(map, handSorted, last.weight, 'twoPair')
    case 'bomb':
      return findBomb(map, last.weight)
    case 'straight':
      return findChain(map, last.length, last.weight, 1)
    case 'doubleStraight':
      return findChain(map, last.length / 2, last.weight, 2)
    case 'plane':
    case 'planeSingle':
    case 'planePair':
    case 'planeTail':
      return findPlane(map, handSorted, last)
    default:
      return []
  }
}

function findSingleBigger(handSorted, w) {
  for (const c of handSorted) {
    if (cardPoint(c) > w) return [c]
  }
  return []
}

function findNOfAKind(map, w, n) {
  for (const pk of pointsAsc(map)) {
    if (map[pk].length < n) continue
    const cmp = n >= 3 ? tripleWeight(pk) : pk
    if (cmp <= w) continue
    return map[pk].slice(0, n)
  }
  return []
}

function findTripleAce(map) {
  return map[ACE_POINT] && map[ACE_POINT].length >= 3 ? map[ACE_POINT].slice(0, 3) : []
}

function findTripleWithKicker(map, handSorted, w, kickerCount = 2) {
  for (const pk of pointsAsc(map)) {
    if (map[pk].length < 3) continue
    if (tripleWeight(pk) <= w) continue
    const triple = map[pk].slice(0, 3)
    if (kickerCount === 2) {
      for (const pk2 of pointsAsc(map)) {
        if (pk2 === pk || map[pk2].length < 2) continue
        return [...triple, ...map[pk2].slice(0, 2)]
      }
      continue
    }
    const kicker = pickKickers(handSorted, [pk], 1)
    if (kicker.length === 1) return [...triple, ...kicker]
  }
  return []
}

function findFourWithKickers(map, handSorted, w, kind) {
  for (const pk of pointsAsc(map)) {
    if (map[pk].length < 4 || pk <= w) continue
    const four = map[pk].slice(0, 4)
    if (kind === 'two') {
      const kicker = pickTwoSingles(handSorted, pk)
      if (kicker.length === 2) return [...four, ...kicker]
    } else if (kind === 'twoPair') {
      const pairs = []
      for (const p2 of pointsAsc(map)) {
        if (p2 === pk || map[p2].length < 2) continue
        pairs.push(...map[p2].slice(0, 2))
        if (pairs.length === 4) return [...four, ...pairs]
      }
    }
  }
  return []
}

function pickTwoSingles(handSorted, excludePoint) {
  const taken = new Set()
  const picked = []
  for (const card of handSorted) {
    const p = cardPoint(card)
    if (p === excludePoint || taken.has(p)) continue
    picked.push(card)
    taken.add(p)
    if (picked.length === 2) return picked
  }
  return []
}

function pickKickers(handSorted, excludePoints, count) {
  const excluded = new Set(excludePoints)
  const picked = []
  for (const c of handSorted) {
    if (excluded.has(cardPoint(c))) continue
    picked.push(c)
    if (picked.length === count) break
  }
  return picked
}

function findBomb(map, w) {
  for (const pk of pointsAsc(map)) {
    if (pk > w && map[pk].length >= 4) return map[pk].slice(0, 4)
  }
  return []
}

function findChain(map, len, w, per) {
  for (let max = w + 1; max <= CHAIN_MAX; max++) {
    const min = max - len + 1
    if (min < CHAIN_MIN) continue
    let ok = true
    for (let p = min; p <= max; p++) {
      if (!map[p] || map[p].length < per) {
        ok = false
        break
      }
    }
    if (!ok) continue
    const result = []
    for (let p = min; p <= max; p++) result.push(...map[p].slice(0, per))
    return result
  }
  return []
}

function findPlane(map, handSorted, last) {
  const whole = getCardType(handSorted, { isLastHand: true })
  if (
    whole &&
    isPlaneType(whole.type) &&
    whole.runLen === last.runLen &&
    whole.weight > last.weight &&
    whole.type === last.type
  ) {
    return handSorted
  }
  const wing = last.wing || (last.type === 'planeSingle' ? 'single' : last.type === 'planePair' ? 'pair' : 'none')
  const runLen = last.runLen || (wing === 'single' ? last.length / 4 : wing === 'pair' ? last.length / 5 : last.length / 3)
  if (!Number.isInteger(runLen) || runLen < 2) return []
  for (let max = CHAIN_MIN + runLen - 1; max <= CHAIN_MAX; max++) {
    if (tripleWeight(max) <= last.weight) continue
    const min = max - runLen + 1
    let ok = true
    for (let p = min; p <= max; p++) {
      if (!map[p] || map[p].length < 3) {
        ok = false
        break
      }
    }
    if (!ok) continue
    const main = []
    for (let p = min; p <= max; p++) main.push(...map[p].slice(0, 3))
    if (wing === 'none') {
      if (handSorted.length === main.length) return main
      continue
    }
    if (wing === 'single') {
      const used = new Set(main)
      const taken = new Set()
      const singles = []
      for (const card of handSorted) {
        if (used.has(card)) continue
        const p = cardPoint(card)
        if (taken.has(p)) continue
        singles.push(card)
        taken.add(p)
        if (singles.length === runLen) return [...main, ...singles]
      }
    } else {
      const pairs = []
      for (const pk of pointsAsc(map)) {
        const usedInBody = pk >= min && pk <= max ? 3 : 0
        if (map[pk].length - usedInBody < 2) continue
        pairs.push(...map[pk].slice(usedInBody, usedInBody + 2))
        if (pairs.length === runLen * 2) return [...main, ...pairs]
      }
    }
  }
  return []
}
