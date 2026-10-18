import { http } from './http'
import { applyRoomSnapshot, applyUserProfile, gameState } from './gameState'
import { wsService } from './gameSocket'
import {
  computeCanStart,
  occupiedPlayers,
  toRelativePlayers,
} from '@/logic/shared/roomSeats'
import { parseAlreadyInRoomId } from '@/services/roomResume'

/**
 * HTTP 创建房间（对齐 cards /api/room/create）
 * 若已在别的进行中房间，后端会直接返回原房号（already_in），前端照常 Join 回桌。
 * @param {{ gameType: number, num?: number, rule: Record<string, unknown>|string }} payload
 */
export async function createRoomHttp(payload) {
  const body = {
    game_type: payload.gameType,
  }
  if (payload.num != null) body.num = payload.num
  body.rule = typeof payload.rule === 'string' ? payload.rule : JSON.stringify(payload.rule)

  const res = await http.post('/api/room/create', body)
  if (res.code !== 0 || !res.data) {
    const msg = res.msg || '创建房间失败'
    const other = parseAlreadyInRoomId(msg)
    if (other) {
      return { roomId: other, rule: '', alreadyIn: true, gameType: 0 }
    }
    throw new Error(msg)
  }
  const roomId = String(res.data.room_num ?? res.data.roomId ?? res.data.room_id ?? '')
  if (!roomId) throw new Error('创建房间成功但未返回房间号')
  const gt = Number(res.data.game_type ?? res.data.gameType ?? 0)
  return {
    roomId,
    rule: res.data.rule,
    alreadyIn: !!res.data.already_in,
    gameType: Number.isFinite(gt) && gt > 0 ? gt : 0,
  }
}

/** 最近一次 Join 回包，供进桌会话回放 */
let lastJoinPayload = null

export function consumeLastJoinPayload() {
  const p = lastJoinPayload
  lastJoinPayload = null
  return p
}

/** 有效房间号：非空数字串（大厅输入一般为 6 位） */
export function hasValidRoomId(roomId = gameState.room.roomId) {
  const id = String(roomId ?? '').trim()
  return !!id && id !== '------' && /^\d{4,8}$/.test(id)
}

/**
 * WS Room.Join，等待 RPC；成功后写入 gameState.room
 * 若报「已在房间 X」则自动改 Join X（重新进原房）
 */
export async function joinRoomWs(roomId, _depth = 0) {
  const id = String(roomId ?? '').trim()
  if (!id || id === '------' || !/^\d{4,8}$/.test(id)) {
    throw new Error('请输入正确的房间号')
  }
  await wsService.connect()
  if (!wsService.ready) {
    throw new Error('实时服务未连接，请检查 VITE_WS_BASE_URL / 登录态')
  }
  const res = await wsService.request('Room.Join', {
    roomId: id,
    uid: String(gameState.user.uid || wsService.userId || ''),
    nickname: gameState.user.nickname || '玩家',
  })
  if (res.code !== 0) {
    const msg = String(res.message || '加入房间失败')
    const other = parseAlreadyInRoomId(msg)
    // 人在别的房：改加入原房（限一次，防环）
    if (other && other !== id && _depth < 1) {
      return joinRoomWs(other, _depth + 1)
    }
    throw new Error(msg)
  }
  const data = res.data || {}
  const sid = String(data.roomId ?? data.room_num ?? id)
  if (!sid || sid === '------') {
    throw new Error('加入成功但未返回房间号')
  }
  gameState.room.roomId = sid
  const gt = Number(data.gameType ?? data.game_type)
  if (Number.isFinite(gt) && gt > 0) gameState.room.gameType = gt
  const myIndex = Number(data.myIndex ?? data.seatNo ?? data.seat_no)
  if (Number.isFinite(myIndex)) gameState.room.myIndex = myIndex

  // Join 回包带本人资料：进房 HUD 立刻显示昵称/头像/金币
  const selfMember = data.member && typeof data.member === 'object' ? data.member : null
  if (selfMember) {
    applyUserProfile({
      uid: data.playerId ?? data.player_id,
      nickname: selfMember.nickname ?? selfMember.realname ?? selfMember.nick,
      avatar: selfMember.avatar_url ?? selfMember.avatar,
      gold: selfMember.gold ?? selfMember.points,
    })
  }

  const mapped = toRelativePlayers(
    occupiedPlayers(data),
    Number.isFinite(myIndex) ? myIndex : gameState.room.myIndex ?? 0,
    gameState.room.players || [],
  )
  applyRoomSnapshot({
    ...data,
    myIndex: Number.isFinite(myIndex) ? myIndex : undefined,
    players: mapped,
  })
  lastJoinPayload = {
    ...data,
    callback: String(data.callback || 'joinroom'),
  }
  return data
}

export async function ensureWsReady() {
  await wsService.connect()
  if (!wsService.ready && gameState.wsUrl && Boolean(gameState.user.token)) {
    throw new Error('实时服务未连接')
  }
}

export function roomCanStart(data) {
  return computeCanStart(data, { forceReady: false })
}
