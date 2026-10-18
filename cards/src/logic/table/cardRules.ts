/**
 * 桌面共用出牌规则门面：按 gameType 分发到 landlord / laizi / pdk 纯规则包。
 */
import { currentTableFeatures, type TableKind } from '@/logic/table/playMode'
import { landlordState } from '@/services/landlordState'
import * as landlordCards from '@/logic/landlord/rules/cards'
import * as laiziCards from '@/logic/laizi/rules/cards'
import * as pdkCards from '@/logic/pdk/rules/cards'

function kind(): TableKind {
  return currentTableFeatures().kind
}

export function getCardType(cards: unknown, opts?: { isLastHand?: boolean }) {
  if (kind() === 'laizi') return laiziCards.getCardType(cards)
  if (kind() === 'pdk') return pdkCards.getCardType(cards, opts || {})
  return landlordCards.getCardType(cards)
}

export function canBeat(current: unknown, last: unknown, opts?: { isLastHand?: boolean }) {
  if (kind() === 'laizi') return laiziCards.isBigger(current, last)
  if (kind() === 'pdk') return pdkCards.canBeat(current, last, opts || {})
  return landlordCards.canBeat(current, last)
}

export function canFinishHand(hand: unknown, lastCards: unknown) {
  if (kind() === 'laizi') return laiziCards.canFinishHand(hand, lastCards)
  if (kind() === 'pdk') {
    if (!Array.isArray(hand) || !hand.length) return false
    return pdkCards.canBeat(hand, lastCards || [], { isLastHand: true })
  }
  return landlordCards.canFinishHand(hand, lastCards)
}

export function findHint(hand: unknown, lastCards: unknown, opts?: unknown) {
  if (kind() === 'laizi') return laiziCards.findHint(hand as never, (lastCards as never) || [])
  if (kind() === 'pdk') {
    return pdkCards.findHint(hand as never, (lastCards as never) || [], (opts as boolean) || false)
  }
  return landlordCards.findHint(hand, lastCards)
}

/** 本地出牌 tip：不合规 / 打不过 */
export function playRejectTip(current: unknown, last: unknown, mustFollow: boolean): string | null {
  if (kind() === 'laizi') {
    const cur = laiziCards.analyzeCards(current)
    if (cur[0] === laiziCards.TYPE.INVALID) return 'chuPaiBuFuHe'
    if (!mustFollow || !last || !(last as unknown[]).length) return null
    if (laiziCards.isBigger(current, last)) return null
    const prev = laiziCards.analyzeCards(last)
    if (prev[0] === laiziCards.TYPE.INVALID) return null
    // 同型同长但压不住 → 打不过；否则不符合
    if (cur[0] === prev[0] && cur[2] === prev[2]) return 'daBuGuoShangJia'
    if (cur[0] === laiziCards.TYPE.BOMB || cur[0] === laiziCards.TYPE.ROCKET) {
      return 'daBuGuoShangJia'
    }
    return 'chuPaiBuFuHe'
  }
  if (kind() === 'pdk') {
    const played = Array.isArray(current) ? current : []
    const isLast = played.length > 0 && played.length === landlordState.hand.length
    const cur = pdkCards.getCardType(played, { isLastHand: isLast })
    if ((cur?.type === 'triple' || cur?.type === 'plane') && !isLast) {
      return 'chuPaiBuFuHe'
    }
    if (!cur) {
      const lastHand = pdkCards.getCardType(current, { isLastHand: true })
      if (!lastHand || !isLast) return 'chuPaiBuFuHe'
      if (!mustFollow || !last || !(last as unknown[]).length) return null
      return pdkCards.canBeat(current, last, { isLastHand: true }) ? null : 'daBuGuoShangJia'
    }
    if (!mustFollow || !last || !(last as unknown[]).length) return null
    const prev = pdkCards.getCardType(last)
    if (!prev) return null
    if (pdkCards.canBeat(current, last, { isLastHand: isLast })) return null
    if (cur.type === prev.type && cur.length === prev.length) return 'daBuGuoShangJia'
    if (cur.type === 'bomb' || pdkCards.isTripleAce(cur)) return 'daBuGuoShangJia'
    return 'chuPaiBuFuHe'
  }
  return landlordCards.playRejectTip(current, last, mustFollow)
}
