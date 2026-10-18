/**
 * 红中麻将协议辅助：座位映射 / 分数归一（对齐 hyperf Hzmj + 旧 useHzmjRoom）
 */

export function toIntList(arr: unknown): number[] {
  if (!Array.isArray(arr)) return []
  return arr.map((x) => Number(x)).filter((n) => Number.isFinite(n))
}

export function emptyTileLists(n: number): number[][] {
  return Array.from({ length: n }, () => [])
}

export function emptyUpLists(n: number): number[][][] {
  return Array.from({ length: n }, () => [])
}

/** discards：后端多为 { "0": number[], ... } */
export function mapDiscardBySeat(raw: unknown, playerNum: number): number[][] {
  const out = emptyTileLists(playerNum)
  if (!raw || typeof raw !== 'object') return out
  const entries = Array.isArray(raw)
    ? raw.map((v, i) => [i, v] as const)
    : (Object.entries(raw) as [string, unknown][])
  for (const [k, v] of entries) {
    const seat = Number(k)
    if (!Number.isFinite(seat) || seat < 0 || seat >= playerNum) continue
    out[seat] = toIntList(v)
  }
  return out
}

/** ups：副露 { "0": number[][], ... } */
export function mapUpBySeat(raw: unknown, playerNum: number): number[][][] {
  const out = emptyUpLists(playerNum)
  if (!raw || typeof raw !== 'object') return out
  const entries = Array.isArray(raw)
    ? raw.map((v, i) => [i, v] as const)
    : (Object.entries(raw) as [string, unknown][])
  for (const [k, v] of entries) {
    const seat = Number(k)
    if (!Number.isFinite(seat) || seat < 0 || seat >= playerNum) continue
    if (!Array.isArray(v)) {
      out[seat] = []
      continue
    }
    out[seat] = v.map((m) => (Array.isArray(m) ? toIntList(m) : toIntList([m])))
  }
  return out
}

/** 局末摊牌 showHands */
export function mapHandsBySeat(raw: unknown, playerNum: number): number[][] {
  return mapDiscardBySeat(raw, playerNum)
}

export function normalizeScores(raw: unknown): Record<number, number> {
  const out: Record<number, number> = {}
  if (!raw || typeof raw !== 'object') return out
  for (const [k, v] of Object.entries(raw as Record<string, unknown>)) {
    const seat = Number(k)
    if (!Number.isFinite(seat)) continue
    out[seat] = Number(v) || 0
  }
  return out
}

export function parsePendingDiscard(
  raw: unknown,
): { tile: number; fromSeat: number } | null {
  if (!raw || typeof raw !== 'object') return null
  const o = raw as Record<string, unknown>
  const tile = Number(o.tile)
  const fromSeat = Number(o.fromSeat ?? o.from_seat ?? 0)
  if (!Number.isFinite(tile) || tile <= 0) return null
  return { tile, fromSeat: Number.isFinite(fromSeat) ? fromSeat : 0 }
}
