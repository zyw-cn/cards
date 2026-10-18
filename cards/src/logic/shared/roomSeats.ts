import { CallBack } from './CallBack'
import {
  resetCardUid,
  serverHandToClient,
  serverPointToClient,
  type ClientCard,
} from './serverCard'
import { sortCards } from './card'
import { pickAvatarRaw, resolveAvatarUrl } from '@/services/avatarUrl'

export type SeatSide = 'self' | 'left' | 'right'
export const SEAT_COUNT = 3

export interface SeatView {
  /** 该侧是否有真实玩家；未入座不画头像 */
  occupied: boolean
  nickName: string
  avatar: string
  /** 玩家 uid，用于独立默认头像 */
  uid: number
  gold: number
  isReady: boolean
  isOffline: boolean
  /** 托管中：头像上展示 tuoguan tip，与离线无关 */
  isTrusted: boolean
  isMaster: boolean
  robState: 'rob' | 'pass' | null
  cardsLeft: number | null
  seatIndex: number | null
}

/** 相对座位：0 自己 / 1 左上家 / 2 右下家 */
export interface RelativePlayer {
  uid: number
  nickname: string
  seat: number
  absSeat: number
  gold: number
  ready: boolean
  online: boolean
  /** 托管中（全桌同步头像 tip） */
  trusted?: boolean
  isOwner?: boolean
  avatar: string
}

export function extractPlayers(data: Record<string, unknown> | null | undefined): unknown[] {
  if (!data || typeof data !== 'object') return []
  const keys = [
    'players',
    'publicMembers',
    'public_members',
    'members',
    'memberList',
  ] as const
  for (const key of keys) {
    const raw = data[key]
    if (!raw) continue
    const arr = Array.isArray(raw) ? raw : typeof raw === 'object' ? Object.values(raw) : []
    if (arr.length) return arr
  }
  return []
}

export function indexToSide(myIndex: number, index: number): SeatSide {
  if (index === myIndex) return 'self'
  // 出牌顺序 +1 = 下家，画在右侧；+2 = 上家，画在左侧（对齐旧 LandlordSeats）
  if (index === (myIndex + 1) % 3) return 'right'
  return 'left'
}

export function sideToIndex(myIndex: number, side: SeatSide): number {
  if (side === 'self') return myIndex
  if (side === 'right') return (myIndex + 1) % 3
  return (myIndex + 2) % 3
}

/** 相对座位 0 自己 / 1 右下家 / 2 左上家 */
export function relSeatToSide(relSeat: number): SeatSide {
  if (relSeat === 0) return 'self'
  if (relSeat === 1) return 'right'
  return 'left'
}

function playerNick(p: Record<string, unknown>): string {
  return String(
    p.nickname ||
      p.nickName ||
      p.realname ||
      p.realName ||
      p.nick ||
      p.name ||
      p.user_name ||
      p.username ||
      p.member_name ||
      '',
  ).trim()
}

function playerSeatIndex(p: Record<string, unknown>, fallbackIndex: number): number {
  const raw = p.index ?? p.seatNo ?? p.seat_no ?? p.seat ?? p.pos ?? fallbackIndex
  const n = Number(raw)
  return Number.isFinite(n) ? n : fallbackIndex
}

export function resolvePlayerId(p: Record<string, unknown>): number {
  const n = Number(
    p.player_id ?? p.playerId ?? p.uid ?? p.user_id ?? p.userId ?? p.id ?? p.member_id ?? p.memberId ?? 0,
  )
  return Number.isFinite(n) ? n : 0
}

/** 真实入座：有成员 id，或有昵称；空座位常只有 seat/id=0/1/2，不画头像 */
export function isOccupiedPlayer(p: unknown): boolean {
  if (!p || typeof p !== 'object') return false
  const rec = p as Record<string, unknown>
  const uid = resolvePlayerId(rec)
  const hasMemberKey =
    rec.player_id != null ||
    rec.playerId != null ||
    rec.uid != null ||
    rec.user_id != null ||
    rec.userId != null ||
    rec.member_id != null ||
    rec.memberId != null
  if (hasMemberKey) return uid > 0
  return !!playerNick(rec)
}

export function occupiedPlayers(data: Record<string, unknown> | null | undefined): Record<string, unknown>[] {
  return extractPlayers(data).filter(isOccupiedPlayer) as Record<string, unknown>[]
}

export function findSelfAbsIndex(
  players: unknown[],
  myUid: number,
): number | null {
  if (!myUid) return null
  const list = Array.isArray(players) ? players : []
  for (let i = 0; i < list.length; i++) {
    const p = list[i]
    if (!p || typeof p !== 'object') continue
    const rec = p as Record<string, unknown>
    if (resolvePlayerId(rec) === Number(myUid)) return playerSeatIndex(rec, i)
  }
  return null
}

export function toRelativePlayers(
  players: unknown[],
  myIndex: number,
  prevPlayers: RelativePlayer[] = [],
): RelativePlayer[] {
  const list = (Array.isArray(players) ? players : []).filter(isOccupiedPlayer)
  const prevById = Object.fromEntries((prevPlayers || []).map((p) => [p.uid, p]))
    const mapped = list.map((raw, i) => {
    const p = raw as Record<string, unknown>
    const abs = playerSeatIndex(p, i)
    const rel = ((abs - myIndex) % SEAT_COUNT + SEAT_COUNT) % SEAT_COUNT
    const uid = resolvePlayerId(p)
    const prev = prevById[uid]
    const hasReadyField = p.isReady != null || p.is_ready != null || p.ready != null
    const hasTrustField =
      p.trusted != null || p.is_auto != null || p.isAuto != null || p.hosted != null
    return {
      uid,
      nickname: playerNick(p) || (uid > 0 ? `玩家${abs + 1}` : ''),
      gold: Number(p.gold ?? p.points ?? prev?.gold ?? 0) || 0,
      seat: rel,
      absSeat: abs,
      // 未带 ready 字段时默认未准备，禁止沿用上一局/进房前的 ready 残留
      ready: hasReadyField ? !!(p.isReady ?? p.is_ready ?? p.ready) : false,
      online: !!(p.online ?? (p.isOffline ?? p.is_offline ?? p.offline ? false : true)),
      trusted: hasTrustField
        ? !!(p.trusted ?? p.is_auto ?? p.isAuto ?? p.hosted)
        : !!prev?.trusted,
      isOwner: !!(p.isOwner ?? p.is_owner ?? p.isMaster ?? p.is_master),
      avatar: resolveAvatarUrl(pickAvatarRaw(p, prev?.avatar), prev?.avatar || '', uid),
    } satisfies RelativePlayer
  })
  mapped.sort((a, b) => a.seat - b.seat)
  return mapped
}

export function applyReadyFlags(
  players: RelativePlayer[],
  data: Record<string, unknown>,
  myUid = 0,
): { players: RelativePlayer[]; readyCount: number; selfReady: boolean } {
  const readyIds = Array.isArray(data.readyIds)
    ? data.readyIds.map((id) => Number(id)).filter((id) => id > 0)
    : []
  const next = players.map((p) => ({ ...p }))
  // 有 readyIds / readyCount 才同步；一律以集合为准（不在集合内 = 未准备）
  if (readyIds.length > 0 || data.readyCount != null) {
    const readySet = new Set(readyIds)
    next.forEach((p) => {
      p.ready = readySet.has(Number(p.uid))
    })
  } else {
    next.forEach((p) => {
      p.ready = false
    })
  }
  const readyCount =
    Number(data.readyCount ?? next.filter((p) => p.ready).length) ||
    next.filter((p) => p.ready).length
  const selfReady = !!next.find(
    (p) => p.seat === 0 || (myUid > 0 && Number(p.uid) === Number(myUid)),
  )?.ready
  return { players: next, readyCount, selfReady }
}

function emptySeat(): SeatView {
  return {
    occupied: false,
    nickName: '',
    avatar: '',
    uid: 0,
    gold: 0,
    isReady: false,
    isOffline: false,
    isTrusted: false,
    isMaster: false,
    robState: null,
    cardsLeft: null,
    seatIndex: null,
  }
}

export function buildSeatsFromPlayers(
  players: unknown[],
  myIndex: number | null | undefined,
): Record<'left' | 'right', SeatView> {
  const seats = { left: emptySeat(), right: emptySeat() }
  if (myIndex == null) return seats

  const list = (Array.isArray(players) ? players : Object.values(players || {})).filter(isOccupiedPlayer)
  for (let i = 0; i < list.length; i++) {
    const p = list[i]
    if (!p || typeof p !== 'object') continue
    const rec = p as Record<string, unknown>
    const idx = playerSeatIndex(rec, i)
    const side = indexToSide(myIndex, idx)
    if (side === 'self') continue
    const nick = playerNick(rec)
    if (!nick) continue
    const uid = resolvePlayerId(rec)
    seats[side] = {
      occupied: true,
      nickName: nick,
      uid,
      avatar: resolveAvatarUrl(pickAvatarRaw(rec), '', uid),
      gold: Number(rec.gold ?? rec.points ?? 0) || 0,
      isReady: !!(rec.isReady ?? rec.is_ready ?? rec.ready),
      isOffline: !!(rec.isOffline ?? rec.is_offline ?? rec.offline),
      isTrusted: !!(rec.trusted ?? rec.is_auto ?? rec.isAuto ?? rec.hosted),
      isMaster: !!(rec.isMaster ?? rec.is_master ?? rec.isLandlord),
      robState: null,
      cardsLeft: (rec.cardsCount ?? rec.cards_count ?? null) as number | null,
      seatIndex: idx,
    }
  }
  return seats
}

export function syncSeatReadyFromPlayers(
  seats: Record<'left' | 'right', SeatView>,
  players: RelativePlayer[],
): void {
  for (const p of players) {
    const side = relSeatToSide(p.seat)
    if (side === 'self') continue
    if (seats[side].occupied) seats[side].isReady = p.ready
  }
}

/** 用相对玩家列表重建左右座，未入座保持空（不对空位填默认头像） */
export function seatsFromRelativePlayers(players: RelativePlayer[]): Record<'left' | 'right', SeatView> {
  const seats = { left: emptySeat(), right: emptySeat() }
  for (const p of players) {
    if (!p.uid || p.seat === 0) continue
    const side = relSeatToSide(p.seat)
    if (side === 'self') continue
    seats[side] = {
      occupied: true,
      nickName: p.nickname,
      uid: p.uid,
      avatar: p.avatar || '',
      gold: p.gold,
      isReady: p.ready,
      isOffline: !p.online,
      isTrusted: !!p.trusted,
      isMaster: !!p.isOwner,
      robState: null,
      cardsLeft: null,
      seatIndex: p.absSeat,
    }
  }
  return seats
}

export function waitStatusText(_occupied: number, _canStart: boolean, _selfReady: boolean, _readyCount = 0): string {
  // 等待阶段人数/准备文案不展示（座位 tip 已能表达）
  return ''
}

export function computeCanStart(
  data: Record<string, unknown> | null | undefined,
  opts: { forceReady?: boolean } = {},
): boolean {
  if (!data) return false
  const { forceReady = false } = opts
  const players = occupiedPlayers(data)
  const callback = String(data.callback ?? '').toLowerCase()
  const readyByCallback = forceReady || callback === CallBack.ROOM_READY
  const readyByFlag = !!(data.canStart ?? data.isFull)
  const readyByCount = players.length >= SEAT_COUNT
  return readyByCallback || readyByFlag || readyByCount
}

export function parseMyCards(
  data: Record<string, unknown> | null | undefined,
  opts?: { sorted?: boolean },
): ClientCard[] {
  resetCardUid()
  const codes = data?.myCards ?? []
  const cards = serverHandToClient(codes)
  return opts?.sorted === false ? cards : sortCards(cards)
}

export function parseBottomCards(remain: unknown): ClientCard[] {
  if (!Array.isArray(remain) || !remain.length) return []
  resetCardUid(100)
  return sortCards(serverHandToClient(remain))
}

export function parsePlayCards(codes: unknown[], playIndex: number): ClientCard[] {
  return (codes ?? []).map((p, i) => serverPointToClient(p, 5000 + playIndex * 20 + i))
}
