/**
 * 斗地主桌面语音（从 game playDdzTableSfx 迁移）
 * 文件在 public/assets/audio/ddz-voice，经 audioManager 播 sprite key。
 */
import { audioManager } from '@/services/audioManager'
import { cardWeight } from '@/logic/shared/card'
import { cardPoint, serverPointToClient } from '@/logic/shared/serverCard'
import { getCardType as getLandlordCardType } from '@/logic/landlord/rules/cards'
import { getCardType as getPdkCardType } from '@/logic/pdk/rules/cards'
import { getCardType as getLaiziCardType } from '@/logic/laizi/rules/cards'
import type { ClientCard } from '@/logic/shared/serverCard'

const MAN_SINGLE_IDS = new Set([1, 2, 3, 4, 10, 11, 12, 13, 14, 15])
const PDK_TRIPLE_ACE_WEIGHT = 100
const LAIZI_WILD_SINGLE_WEIGHT = 18

export type DdzAudioRules = 'landlord' | 'laizi' | 'pdk'

function pick(list: string[]): string {
  if (!list?.length) return ''
  return list[Math.floor(Math.random() * list.length)]
}

function genderPrefix(sex: number, name: string): string {
  if (Number(sex) !== 1) return 'Woman'
  if (/^\d+$/.test(name) && MAN_SINGLE_IDS.has(Number(name))) return 'Man'
  return 'Woman'
}

function playVoice(name: string, sex = 0): void {
  if (!name) return
  const key = `${genderPrefix(sex, name)}_${name}`
  audioManager.playEffect(key, 'ddz-voice')
}

export function pointToVoiceId(point: number): number {
  const p = Number(point)
  if (p === 14) return 1
  if (p === 15) return 2
  if (p >= 3 && p <= 13) return p
  if (p === 16) return 14
  if (p === 17) return 15
  return 0
}

function weightToVoiceId(weight: number): number {
  const w = Number(weight)
  if (w === 12) return 1
  if (w === 13) return 2
  if (w >= 1 && w <= 11) return w + 2
  if (w === 14) return 14
  if (w === 15) return 15
  return 0
}

function normalizeCards(cards: unknown[]): ClientCard[] {
  if (!Array.isArray(cards) || !cards.length) return []
  return cards.map((c, i) => {
    if (c && typeof c === 'object' && (c as ClientCard).point != null) {
      return c as ClientCard
    }
    const raw =
      typeof c === 'string' || typeof c === 'number'
        ? c
        : (c as { code?: string })?.code ?? ''
    return serverPointToClient(raw, 9000 + i)
  })
}

function primaryPoint(cards: ClientCard[]): number {
  if (!cards?.length) return 0
  const counts: Record<number, number> = Object.create(null)
  for (const c of cards) {
    let p = Number(c?.point)
    if (!Number.isFinite(p) || p <= 0) p = cardPoint(c) || 0
    if (!p) {
      const w = cardWeight(c)
      if (w >= 1 && w <= 13) p = w + 2
      else if (w === 14) p = 16
      else if (w === 15) p = 17
    }
    if (!p || p === 55) continue
    counts[p] = (counts[p] || 0) + 1
  }
  let best = 0
  let bestN = 0
  for (const [p, n] of Object.entries(counts)) {
    const pt = Number(p)
    if (n > bestN || (n === bestN && pt > best)) {
      bestN = n
      best = pt
    }
  }
  return best
}

function voiceIdFromType(
  typeInfo: { weight?: number } | null,
  cards: ClientCard[],
  rules: DdzAudioRules,
): number {
  const w = Number(typeInfo?.weight)
  if (rules === 'landlord') {
    const id = weightToVoiceId(w)
    if (id) return id
    return pointToVoiceId(primaryPoint(cards))
  }
  if (rules === 'pdk') {
    if (w === PDK_TRIPLE_ACE_WEIGHT) return 1
    if (w >= 3 && w <= 17) return pointToVoiceId(w)
    return pointToVoiceId(primaryPoint(cards))
  }
  if (rules === 'laizi') {
    if (w === LAIZI_WILD_SINGLE_WEIGHT) return 0
    if (w >= 3 && w <= 17) return pointToVoiceId(w)
    return pointToVoiceId(primaryPoint(cards))
  }
  return pointToVoiceId(primaryPoint(cards))
}

function resolveType(cards: ClientCard[], pattern: string, rules: DdzAudioRules) {
  if (rules === 'pdk') {
    const t = getPdkCardType(cards)
    if (t) return t
  } else if (rules === 'laizi') {
    const t = getLaiziCardType(cards)
    if (t) return t
  } else {
    const t = getLandlordCardType(cards)
    if (t) return t
  }
  if (pattern === 'rocket') return { type: 'rocket', weight: 0, length: 2 }
  if (pattern === 'bomb') return { type: 'bomb', weight: 0, length: 4 }
  if (pattern === 'airplane' || pattern === 'plane') return { type: 'plane', weight: 0, length: 0 }
  if (pattern === 'straight' || pattern === 'shunzi') return { type: 'straight', weight: 0, length: 0 }
  if (pattern === 'pairStraight' || pattern === 'doubleStraight' || pattern === 'liandui') {
    return { type: 'doubleStraight', weight: 0, length: 0 }
  }
  return null
}

function playNameForType(
  typeInfo: { type?: string; weight?: number } | null,
  cards: ClientCard[],
  rules: DdzAudioRules,
): string {
  const type = String(typeInfo?.type || '')
  const rank = voiceIdFromType(typeInfo, cards, rules)

  switch (type) {
    case 'rocket':
    case '王炸':
      return 'wangzha'
    case 'bomb':
    case '炸弹':
      return 'zhadan'
    case 'plane':
    case 'plane1':
    case 'plane2':
    case 'planeSingle':
    case 'planePair':
    case 'planeTail':
    case 'airplane':
    case '飞机':
    case '飞机带单':
    case '飞机带对':
    case '飞机甩尾':
      return 'feiji'
    case 'straight':
    case '顺子':
      return 'shunzi'
    case 'doubleStraight':
    case 'pairStraight':
    case '连对':
      return 'liandui'
    case 'triple1':
    case '三带一':
      return 'sandaiyi'
    case 'triple2':
    case '三带二':
      return 'sandaiyidui'
    case 'four3':
    case 'four2':
    case '四带二':
    case '四带二单':
      return 'sidaier'
    case 'four22':
    case '四带两对':
      return 'sidailiangdui'
    case 'triple':
    case '三张':
    case '三不带':
      return rank >= 1 && rank <= 13 ? `tuple${rank}` : ''
    case 'pair':
    case '对子':
      return rank >= 1 && rank <= 13 ? `dui${rank}` : ''
    case 'single':
    case '单张':
      return rank >= 1 && rank <= 15 ? String(rank) : ''
    default:
      if ((cards?.length || 0) > 1) return ''
      return rank >= 1 && rank <= 15 ? String(rank) : ''
  }
}

/** 出牌 / 不出 */
export function playDdzPlaySfx(opts: {
  action: string
  pattern?: string
  cards?: unknown[]
  following?: boolean
  sex?: number
  rules?: DdzAudioRules
  enabled?: boolean
}): void {
  const {
    action,
    pattern = '',
    cards = [],
    following = false,
    sex = 0,
    rules = 'landlord',
    enabled = true,
  } = opts
  if (!enabled) return

  if (action === 'pass') {
    // 出牌过牌口播「不要/不出」。ddz-sfx 的 pass 是「不叫」，只用于叫分阶段。
    playVoice(pick(['buyao1', 'buyao2', 'buyao3', 'buyao4']), sex)
    return
  }
  if (action !== 'play') return

  const normalized = normalizeCards(cards)
  const typeInfo = resolveType(normalized, pattern, rules)
  const name = playNameForType(typeInfo as { type?: string; weight?: number }, normalized, rules)
  const t = String(typeInfo?.type || '')
  const simple =
    t === 'single' ||
    t === 'pair' ||
    t === 'triple' ||
    t === '单张' ||
    t === '对子' ||
    t === '三张' ||
    t === '三不带'

  if (name) playVoice(name, sex)

  if (following && simple && name && Math.random() < 0.28) {
    window.setTimeout(() => {
      playVoice(pick(['dani1', 'dani2', 'dani3']), sex)
    }, 280)
  }
}

export function playDdzBidSfx(opts: {
  score: number
  isRob?: boolean
  sex?: number
  enabled?: boolean
}): void {
  const { score, isRob = false, sex = 0, enabled = true } = opts
  if (!enabled) return
  const s = Number(score) || 0
  if (s > 0) {
    if (isRob || s >= 2) playVoice(pick(['Rob1', 'Rob2', 'Rob3']), sex)
    else playVoice('Order', sex)
    audioManager.playEffect(isRob || s >= 2 ? 'grab' : 'callMaster', 'ddz-sfx')
    return
  }
  playVoice(isRob ? 'NoRob' : 'NoOrder', sex)
}

export function playDdzDoubleSfx(opts: {
  doubled?: boolean
  sex?: number
  enabled?: boolean
} = {}): void {
  const { doubled = false, sex = 0, enabled = true } = opts
  if (!enabled) return
  playVoice(doubled ? 'jiabei' : 'bujiabei', sex)
}

export function playDdzWarnSfx(remain: number, enabled = true, sex = 0): void {
  if (!enabled) return
  const n = Number(remain)
  if (n === 1) playVoice('baojing1', sex)
  else if (n === 2) playVoice('baojing2', sex)
}

export function playDdzSettleSfx(selfWin: boolean, enabled = true): void {
  if (!enabled) return
  audioManager.playEffect(selfWin ? 'win' : 'lose', 'ddz-sfx')
}

export function playDdzTableBgm(): void {
  audioManager.playPackBGM('ddz-sfx')
}

export function stopDdzTableBgm(): void {
  audioManager.stopBGM()
}
