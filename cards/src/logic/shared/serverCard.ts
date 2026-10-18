/**
 * 服务端牌编码约定：
 *   A/B/C/D + 3~15 → 黑桃/红桃/梅花/方片（14=A，15=2）
 *   E16 / E17      → 小王 / 大王
 *   F55            → 癞子（兼容旧码 W55）
 */

const SUIT_TO_SHAPE = Object.freeze({ A: 1, B: 2, C: 3, D: 4 })
const SHAPE_TO_SUIT = Object.freeze({ 1: 'A', 2: 'B', 3: 'C', 4: 'D' }) as Record<number, string>

const SERVER_TO_CLIENT_VALUE = Object.freeze({
  3: 1,
  4: 2,
  5: 3,
  6: 4,
  7: 5,
  8: 6,
  9: 7,
  10: 8,
  11: 9,
  12: 10,
  13: 11,
  14: 12,
  15: 13,
}) as Record<number, number>

const CLIENT_VALUE_TO_SERVER = Object.freeze(
  Object.fromEntries(Object.entries(SERVER_TO_CLIENT_VALUE).map(([k, v]) => [v, Number(k)])),
) as Record<number, number>

export interface ClientCard {
  index: number
  code: string
  point: number
  value?: number
  shape?: number
  king?: number
  wild?: boolean
}

let cardUid = 0

export function serverPointToClient(code: unknown, index = cardUid++): ClientCard {
  if (code != null && typeof code === 'object') {
    const raw = (code as { code?: unknown; card?: unknown; point?: unknown }).code
      ?? (code as { card?: unknown }).card
      ?? (code as { point?: unknown }).point
    if (raw != null && raw !== '') return serverPointToClient(raw, index)
    return { index, code: 'back', point: 0, value: 1, shape: 1 }
  }

  if (typeof code === 'string') {
    const raw = code.trim().toUpperCase()
    if (raw === 'F55' || raw === 'F' || raw === 'W55' || raw === 'LAIZI' || raw === '55') {
      return { index, code: 'F55', point: 55, value: 18, shape: 0, wild: true }
    }
    const suit = raw.charAt(0)
    const point = Number(raw.slice(1))
    if (!Number.isFinite(point)) {
      console.warn('[serverCard] 非法牌编码:', code)
      return { index, code, point: 0, value: 1, shape: 1 }
    }
    if (suit === 'E') {
      return { index, code: raw, point, king: point === 17 ? 15 : 14 }
    }
    const shape = SUIT_TO_SHAPE[suit as keyof typeof SUIT_TO_SHAPE] ?? 1
    const value = SERVER_TO_CLIENT_VALUE[point] ?? point
    return { index, code: raw, point, value, shape }
  }

  const point = Number(code)
  if (point === 16) return { index, code: 'E16', point: 16, king: 14 }
  if (point === 17) return { index, code: 'E17', point: 17, king: 15 }
  const value = SERVER_TO_CLIENT_VALUE[point] ?? point
  const shape = (index % 4) + 1
  const suit = SHAPE_TO_SUIT[shape] ?? 'A'
  return { index, code: `${suit}${point}`, point, value, shape }
}

export function serverHandToClient(codes: unknown): ClientCard[] {
  if (!Array.isArray(codes)) return []
  return codes.map((c) => serverPointToClient(c))
}

export function toServerCode(code: unknown): string {
  const c = String(code ?? '').trim().toUpperCase()
  if (!c) return ''
  if (c === 'F55' || c === 'F' || c === 'W55' || c === 'LAIZI' || c === '55') return 'F55'
  if (c === 'JS' || c === 'SJ' || c === 'E16') return 'E16'
  if (c === 'JB' || c === 'BJ' || c === 'E17') return 'E17'
  const suit = c.charAt(0)
  const point = Number(c.slice(1))
  if (!'ABCD'.includes(suit) || !Number.isFinite(point)) return c
  if (point === 1) return `${suit}14`
  if (point === 2) return `${suit}15`
  return `${suit}${point}`
}

export function clientCardToServer(card: unknown): string {
  if (typeof card === 'string' || typeof card === 'number') return toServerCode(card)
  const c = card as ClientCard | null
  if (c?.code) return toServerCode(c.code)
  if (c?.king === 14) return 'E16'
  if (c?.king === 15) return 'E17'
  if (c?.wild || c?.code === 'F55' || c?.code === 'W55' || c?.code === 'F') return 'F55'
  const point = c?.point ?? (c?.value != null ? CLIENT_VALUE_TO_SERVER[c.value] : undefined) ?? c?.value
  if (point != null && Number.isFinite(Number(point))) {
    const suit =
      (c?.shape != null ? SHAPE_TO_SUIT[c.shape] : undefined) ??
      (typeof c?.code === 'string' && 'ABCD'.includes(String(c.code).charAt(0).toUpperCase())
        ? String(c.code).charAt(0).toUpperCase()
        : 'A')
    return toServerCode(`${suit}${point}`)
  }
  return ''
}

export function cardPoint(cardOrCode: unknown): number {
  if (cardOrCode == null) return 0
  if (typeof cardOrCode === 'number') return cardOrCode
  if (typeof cardOrCode === 'string') {
    const c = cardOrCode.trim().toUpperCase()
    if (c === 'F55' || c === 'F' || c === 'W55' || c === 'LAIZI' || c === '55') return 55
    return Number(c.slice(1)) || 0
  }
  const c = cardOrCode as ClientCard
  if (c.wild || c.code === 'F55' || c.code === 'W55' || c.code === 'F') return 55
  if (c.point != null && Number(c.point) > 0) return Number(c.point)
  if (c.king === 14) return 16
  if (c.king === 15) return 17
  if (c.value != null && CLIENT_VALUE_TO_SERVER[c.value] != null) return CLIENT_VALUE_TO_SERVER[c.value]
  if (c.code) return cardPoint(String(c.code))
  return 0
}

export function resetCardUid(seed = 0): void {
  cardUid = seed
}

export function buildServerDeck(): string[] {
  const deck: string[] = []
  for (const suit of ['A', 'B', 'C', 'D'] as const) {
    for (let point = 3; point <= 15; point++) deck.push(`${suit}${point}`)
  }
  deck.push('E16', 'E17')
  return deck
}

export function cardsToServer(cards: unknown[]): string[] {
  return cards.map(clientCardToServer)
}
