import { reactive } from 'vue'
import {
  session,
  clearSession as clearAppSession,
  resolveToken as resolveAppToken,
  applyUserProfile as applySessionProfile,
  hydrateTokenFromCache,
  persistToken,
} from './session'
import { pickAvatarRaw, resolveAvatarUrl } from './avatarUrl'
import { normalizeRoomRule } from '@/logic/shared/roomRule'

/**
 * 对齐 cards：切屏只改 currentScreen，禁止 vue-router。
 * user / room 与 session 同一引用。
 */
function envString(key, fallback = '') {
  const value = (import.meta.env[key] || '').trim()
  return value || fallback
}

/** @typedef {'LOGIN' | 'LOBBY' | 'CREATE_ROOM' | 'GAME_TABLE' | 'SETTLE'} ScreenId */

export const gameState = reactive({
  /** @type {ScreenId} */
  currentScreen: 'LOGIN',
  get cdnUrl() {
    return envString('VITE_CDN_URL', '')
  },
  get apiBase() {
    return session.apiBase
  },
  get wsUrl() {
    return envString('VITE_WS_BASE_URL', 'ws://127.0.0.1:9052')
  },
  get user() {
    return session.user
  },
  get room() {
    return session.room
  },
  get pendingCreateGameType() {
    return session.pendingCreateGameType
  },
  set pendingCreateGameType(v) {
    session.pendingCreateGameType = v
  },
})

/** @param {ScreenId} screen */
export function goScreen(screen) {
  gameState.currentScreen = screen
}

export function goLogin() {
  goScreen('LOGIN')
}

export function goLobby() {
  goScreen('LOBBY')
}

export function goCreateRoom() {
  goScreen('CREATE_ROOM')
}

export function goGameTable() {
  goScreen('GAME_TABLE')
}

export function resolveToken() {
  return resolveAppToken()
}

export function clearSession() {
  clearAppSession()
}

export function applyUserProfile(profile = {}) {
  const nick =
    profile.nickname ?? profile.realname ?? profile.nick ?? profile.realName ?? profile.name
  applySessionProfile({
    ...profile,
    nickname: typeof nick === 'string' && nick.trim() ? nick.trim() : profile.nickname,
  })
  const avatar = pickAvatarRaw(profile)
  if (avatar) {
    session.user.avatar = resolveAvatarUrl(avatar, session.user.avatar)
  }
  if (typeof profile.token === 'string' && profile.token) {
    persistToken(profile.token)
  }
}

function playerUid(p) {
  return Number(p?.uid ?? p?.player_id ?? p?.playerId ?? p?.id ?? 0)
}

function playerNick(p) {
  return String(p?.nickname ?? p?.nickName ?? p?.realname ?? '').trim()
}

/** 进房后记住房主，后续不带 isOwner 的包不会把名字冲掉 */
function rememberRoomOwner(players, ownerId) {
  const oid = ownerId > 0 ? ownerId : Number(session.room.ownerId || 0)
  const list = Array.isArray(players) ? players : []
  for (const p of list) {
    const uid = playerUid(p)
    const flagged = !!(p?.isOwner ?? p?.is_owner)
    if (!flagged && !(oid > 0 && uid === oid)) continue
    if (uid > 0) session.room.ownerId = uid
    const name = playerNick(p)
    if (name) {
      session.room.ownerName = name
      return
    }
  }
  if (session.room.isMaster) {
    const mine = String(session.user.nickname || '').trim()
    if (mine) session.room.ownerName = mine
    if (!(Number(session.room.ownerId) > 0) && Number(session.user.uid) > 0) {
      session.room.ownerId = Number(session.user.uid)
    }
  }
}

/** 本房是否已开过局（中途退出须投票解散） */
export function roomHasStarted() {
  const played = Number(session.room.playedDone)
  if (Number.isFinite(played) && played > 0) return true
  const js = Number(session.room.js)
  return Number.isFinite(js) && js > 0
}

/** 房间规则里展示的房主昵称 */
export function roomOwnerName() {
  const saved = String(session.room.ownerName || '').trim()
  if (saved) return saved
  if (session.room.isMaster) {
    const mine = String(session.user.nickname || '').trim()
    if (mine) return mine
  }
  return ''
}

/** Join / 广播座位快照写入 room */
export function applyRoomSnapshot(payload = {}) {
  const rid = payload.roomId ?? payload.room_num ?? payload.room_id
  if (typeof rid === 'string' || typeof rid === 'number') {
    session.room.roomId = String(rid)
  }
  const ownerId = Number(payload.ownerId ?? payload.owner_id ?? 0)
  const myUid = Number(session.user.uid || payload.playerId || payload.player_id || 0)
  if (ownerId > 0) {
    session.room.ownerId = ownerId
    if (myUid > 0) session.room.isMaster = ownerId === myUid
  } else if (typeof payload.isMaster === 'boolean') {
    session.room.isMaster = payload.isMaster
  }
  if (payload.myIndex != null && Number.isFinite(Number(payload.myIndex))) {
    session.room.myIndex = Number(payload.myIndex)
  }
  if (Array.isArray(payload.players)) {
    session.room.players = payload.players
    const self = session.room.players.find(
      (p) => p.seat === 0 || (session.user.uid > 0 && Number(p.uid) === Number(session.user.uid)),
    )
    if (self) {
      if (!(ownerId > 0) && (self.isOwner != null || self.isMaster != null)) {
        session.room.isMaster = !!(self.isOwner ?? self.isMaster)
      }
      applyUserProfile({
        nickname: self.nickname,
        gold: Number.isFinite(Number(self.gold)) ? Number(self.gold) : undefined,
        avatar: self.avatar,
      })
    }
    rememberRoomOwner(payload.players, ownerId)
  } else {
    rememberRoomOwner([], ownerId)
  }
  if (Array.isArray(payload.handCards)) {
    session.room.handCards = payload.handCards
  }
  const zjs = Number(
    payload.zjs ?? payload.totalRounds ?? payload.total_play_count ?? payload.totalPlayCount ?? payload.totalCount,
  )
  if (Number.isFinite(zjs) && zjs > 0) session.room.zjs = zjs

  if (payload.rule != null || payload.ruleObj != null) {
    const norm = normalizeRoomRule(payload.rule, payload.ruleObj)
    session.room.df = norm.df
    session.room.payMode = norm.payMode
    session.room.payModeText = norm.payModeText
    session.room.payModeDesc = norm.payModeDesc
    session.room.payAmount = norm.payAmount
    session.room.askMode = norm.askMode
    session.room.askModeText = norm.askModeText
    session.room.bombMultiple = norm.bombMultiple
    if (norm.zjs) session.room.zjs = norm.zjs
    if (norm.seats) session.room.seats = norm.seats
  }

  const gt = Number(payload.gameType ?? payload.game_type)
  if (Number.isFinite(gt) && gt > 0) session.room.gameType = gt
}

let lastStartRoundId = 0

function readTotal(payload) {
  const total = Number(
    payload.totalCount ?? payload.totalPlayCount ?? payload.total_play_count ?? payload.zjs ?? payload.totalRounds,
  )
  if (Number.isFinite(total) && total > 0) session.room.zjs = total
}

/**
 * 局数：js 为当前展示局（进行中是第几局，局间是已打完几局）。
 * playedDone 是已经结算完的局数。
 * join 只在本人进房包（带 inGame）上改，避免别人重连把进行中的局数刷回已打完。
 */
export function syncRoundProgress(payload = {}, mode = 'join') {
  readTotal(payload)
  const hasCurrent = payload.currentRound != null && payload.currentRound !== ''
  const hasPlayed = payload.playedCount != null || payload.played_count != null
  const current = Number(payload.currentRound)
  const played = Number(payload.playedCount ?? payload.played_count)
  const cap = Number(session.room.zjs) || 0

  if (mode === 'start') {
    const rid = Number(payload.roundId ?? payload.round_id ?? 0)
    if (rid > 0 && rid === lastStartRoundId) return
    if (rid > 0) lastStartRoundId = rid
    if (hasCurrent && Number.isFinite(current) && current > 0) {
      session.room.js = cap > 0 ? Math.min(current, cap) : current
      session.room.playedDone = Math.max(0, session.room.js - 1)
      return
    }
    if (session.room.playedDone != null && Number.isFinite(Number(session.room.playedDone))) {
      const next = Number(session.room.playedDone) + 1
      session.room.js = cap > 0 ? Math.min(next, cap) : next
      return
    }
    if (!(Number(session.room.js) > 0)) session.room.js = 1
    return
  }

  if (mode === 'settle') {
    if (hasPlayed && Number.isFinite(played) && played >= 0) {
      session.room.playedDone = played
      const roomOver = payload.roomOver === true || (cap > 0 && played >= cap)
      session.room.js = roomOver ? (cap || played) : played
      return
    }
    const cur = Number(session.room.js) > 0 ? Number(session.room.js) : 1
    session.room.playedDone = cur
    session.room.js = cur
    return
  }

  if (mode === 'resume') {
    const done = hasPlayed && Number.isFinite(played) && played >= 0 ? played : 0
    session.room.playedDone = done
    const now = done + 1
    session.room.js = cap > 0 ? Math.min(now, cap) : now
    return
  }

  // 进房必须覆盖上一桌残留。没带 playedCount 时按 0 局，避免 6/6。
  const done = hasPlayed && Number.isFinite(played) && played >= 0 ? played : 0
  session.room.playedDone = done
  const inRound = payload.inGame === true
  // 还没打过、或这一局正在打：显示第 played+1 局。局间等待则显示已打完的局数，至少为 1。
  let now = inRound || done <= 0 ? done + 1 : done
  if (cap > 0 && now > cap) now = cap
  if (now < 1) now = 1
  session.room.js = now
}

function isAuthFailCode(code) {
  return code === 401 || (code >= 1001 && code <= 1005)
}

/** 启动时：有 token 进大厅，否则登录；刷新后靠 WS reconnect 再拉回牌桌 */
export async function tryAutoLogin() {
  const token = hydrateTokenFromCache()
  if (!token) {
    goLogin()
    return false
  }
  if (!gameState.apiBase && import.meta.env.DEV) {
    goLobby()
    return true
  }
  try {
    const { http } = await import('@/services/http')
    const res = await http.post('/api/member/index', {})
    if (res.code === 0 && res.data) {
      applyUserProfile(res.data)
      goLobby()
      return true
    }
    if (isAuthFailCode(res.code)) {
      clearSession()
      goLogin()
      return false
    }
    goLogin()
    return false
  } catch (err) {
    const status =
      err && typeof err === 'object' && 'status' in err ? Number(err.status) : 0
    if (status === 401 || isAuthFailCode(status)) {
      clearSession()
    }
    goLogin()
    return false
  }
}
