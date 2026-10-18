import { reactive } from 'vue'
import { findHallGame } from '@/config/gameTypes'
import { gameState, goGameTable, goLobby } from '@/services/gameState'
import { joinRoomWs } from '@/services/roomApi'

export interface ReconnectRoomInfo {
  roomId: string
  gameType: number
}

const PENDING_KEY = 'qipai_reconnect_room'

function readStoredPending(): ReconnectRoomInfo | null {
  try {
    const raw = sessionStorage.getItem(PENDING_KEY)
    if (!raw) return null
    const row = JSON.parse(raw) as ReconnectRoomInfo
    if (!isValidRoomId(row?.roomId)) return null
    return {
      roomId: String(row.roomId),
      gameType: Number(row.gameType) || 0,
    }
  } catch {
    return null
  }
}

function writeStoredPending(info: ReconnectRoomInfo | null): void {
  try {
    if (!info?.roomId) sessionStorage.removeItem(PENDING_KEY)
    else sessionStorage.setItem(PENDING_KEY, JSON.stringify(info))
  } catch {
    /* ignore */
  }
}

/**
 * 仍在房间（服务端 reconnect / 本地房号）→ 自动进桌。
 * 仅「真正离开」(Leave) 后 suppress。
 */
export const roomResume = reactive({
  pending: null as ReconnectRoomInfo | null,
  /** 主动离开房间后为 true；服务端 reconnect 会清掉 */
  suppressAutoResume: false,
  entering: false,
  /** 牌局中点返回：托管后回大厅，卸桌时不要发 Leave */
  keepSeat: false,
})

function setPending(info: ReconnectRoomInfo | null): void {
  roomResume.pending = info
  writeStoredPending(info)
}

export function setSuppressAutoResume(on: boolean): void {
  roomResume.suppressAutoResume = !!on
  if (on) setPending(null)
}

/** 主动创房/加房前调用：允许之后断线再自动进房 */
export function allowAutoResume(): void {
  roomResume.suppressAutoResume = false
}

/** 主动返回大厅：已离开座位，不要再按 reconnect 塞回 */
export function leaveToHallIntent(): void {
  roomResume.keepSeat = false
  setSuppressAutoResume(true)
  gameState.room.roomId = ''
  goLobby()
}

/**
 * 进桌失败回大厅：保留房号到 pending（刷新仍可进），但短暂 suppress 防死循环。
 */
export function bounceToHallKeepResume(): void {
  roomResume.keepSeat = false
  const id = String(gameState.room.roomId || roomResume.pending?.roomId || '').trim()
  const gt = Number(gameState.room.gameType || roomResume.pending?.gameType || 0)
  if (isValidRoomId(id)) {
    setPending({ roomId: id, gameType: Number.isFinite(gt) ? gt : 0 })
  }
  // 先进大厅停一下，避免 enter 失败 ↔ 自动进房打转
  roomResume.suppressAutoResume = true
  goLobby()
  window.setTimeout(() => {
    allowAutoResume()
    void tryEnterPendingRoom()
  }, 1200)
}

/** 牌局中确认返回：先托管，座位留在房里；进大厅后仍会自动回桌 */
export function returnToHallKeepingSeat(): void {
  roomResume.keepSeat = true
  allowAutoResume()
  const id = String(gameState.room.roomId || '').trim()
  if (isValidRoomId(id)) {
    setPending({
      roomId: id,
      gameType: Number(gameState.room.gameType) || 0,
    })
  }
  goLobby()
}

export function consumeKeepSeat(): boolean {
  const keep = roomResume.keepSeat
  roomResume.keepSeat = false
  return keep
}

const ROOM_GONE =
  /还未加入任何房间|不在房间内|房间已结束|房间已解散|房间不存在|房间当前状态不可加入|原房间已关闭/

const ALREADY_IN_ROOM = /已在(?:其他)?房间/

export function isRoomGoneMessage(message: unknown): boolean {
  return ROOM_GONE.test(String(message ?? ''))
}

/** 从「您已在房间 123456 中…」里抠房号 */
export function parseAlreadyInRoomId(message: unknown): string {
  const msg = String(message ?? '')
  if (!ALREADY_IN_ROOM.test(msg)) return ''
  const m = msg.match(/房间\s*(\d{4,8})/)
  return m?.[1] ? String(m[1]) : ''
}

function isValidRoomId(roomId: unknown): boolean {
  const id = String(roomId ?? '').trim()
  return !!id && id !== '------' && /^\d{4,8}$/.test(id)
}

/**
 * 创房/加房因「还在别的房」失败时：清 suppress，直接进原房间。
 */
export async function recoverAlreadyInRoom(err: unknown): Promise<boolean> {
  const msg = err instanceof Error ? err.message : String(err ?? '')
  const roomId = parseAlreadyInRoomId(msg)
  if (!roomId) return false
  // 允许在 tryEnterPendingRoom 的 catch 里嵌套调用
  const held = roomResume.entering
  if (!held) roomResume.entering = true
  try {
    allowAutoResume()
    setPending({ roomId, gameType: Number(gameState.room.gameType) || 0 })
    await joinRoomWs(roomId)
    setPending(null)
    allowAutoResume()
    goGameTable()
    return true
  } catch (e) {
    console.warn('[roomResume] recover already-in-room', e)
    return false
  } finally {
    if (!held) roomResume.entering = false
  }
}

/**
 * 牌桌组件被卸掉时是否该发离开。
 * 进房后一律不自动离座；返回大厅须用户确认，并走托管留座。
 */
export function shouldLeaveSeatOnUnmount(): boolean {
  return false
}

/** 房间已经关掉：不要停在空牌桌上 */
export function exitEndedRoom(): void {
  if (gameState.currentScreen !== 'GAME_TABLE' && gameState.currentScreen !== 'SETTLE') return
  leaveToHallIntent()
}

/** 鉴权 NOTICE：服务端认定仍在房 → 必须自动进，覆盖 suppress */
export function captureReconnectFromAuth(data: Record<string, unknown>): void {
  const rc = data.reconnect
  if (!rc || typeof rc !== 'object') return
  const row = rc as Record<string, unknown>
  const roomId = String(row.roomId ?? row.room_num ?? row.room_id ?? '').trim()
  if (!isValidRoomId(roomId)) return
  const gameType = Number(row.gameType ?? row.game_type ?? 0)
  roomResume.suppressAutoResume = false
  setPending({
    roomId,
    gameType: Number.isFinite(gameType) && gameType > 0 ? gameType : 0,
  })
}

async function enterRoomId(roomId: string, gameType = 0): Promise<boolean> {
  if (gameType > 0) {
    gameState.room.gameType = gameType
    const g = findHallGame(gameType)
    if (g) gameState.room.playName = g.name
  }
  await joinRoomWs(roomId)
  setPending(null)
  allowAutoResume()
  goGameTable()
  return true
}

function resolvePending(): ReconnectRoomInfo | null {
  if (roomResume.pending?.roomId && isValidRoomId(roomResume.pending.roomId)) {
    return roomResume.pending
  }
  const stored = readStoredPending()
  if (stored) {
    roomResume.pending = stored
    return stored
  }
  return null
}

/** 大厅/登录：有 pending 或本地房号则进桌 */
export async function tryEnterPendingRoom(): Promise<boolean> {
  const screen = gameState.currentScreen
  if (screen !== 'LOBBY' && screen !== 'LOGIN') return false
  if (roomResume.entering) return false

  // 真正离开后 suppress：不要用本地残留房号再拉回
  if (roomResume.suppressAutoResume) return false

  const pending = resolvePending()
  if (pending?.roomId) {
    roomResume.entering = true
    try {
      return await enterRoomId(pending.roomId, pending.gameType)
    } catch (err) {
      console.warn('[roomResume] auto enter pending', err)
      const msg = err instanceof Error ? err.message : String(err ?? '')
      if (isRoomGoneMessage(msg)) {
        setPending(null)
        gameState.room.roomId = ''
      } else {
        // 失败保留 pending，大厅可再试
        setPending(pending)
      }
      // 「已在房间 X」→ 直接进 X
      if (await recoverAlreadyInRoom(err)) return true
      return false
    } finally {
      roomResume.entering = false
    }
  }

  // 本地还记着房号（托管回大厅）→ 直接 Join
  const localId = String(gameState.room.roomId || '').trim()
  if (!isValidRoomId(localId)) return false

  roomResume.entering = true
  try {
    return await enterRoomId(localId, Number(gameState.room.gameType) || 0)
  } catch (err) {
    console.warn('[roomResume] auto enter local', err)
    const msg = err instanceof Error ? err.message : String(err ?? '')
    if (isRoomGoneMessage(msg)) gameState.room.roomId = ''
    if (await recoverAlreadyInRoom(err)) return true
    return false
  } finally {
    roomResume.entering = false
  }
}
