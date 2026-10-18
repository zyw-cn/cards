import { reactive } from 'vue'

const TOKEN_KEY = 'qipai_token'
const REFRESH_TOKEN_KEY = 'qipai_refresh_token'

function envString(key, fallback = '') {
  const value = (import.meta.env[key] || '').trim()
  return value || fallback
}

function readStorage(key) {
  try {
    return localStorage.getItem(key) || ''
  } catch {
    return ''
  }
}

function writeStorage(key, value) {
  try {
    if (value) localStorage.setItem(key, value)
    else localStorage.removeItem(key)
  } catch {
    /* ignore */
  }
}

export const session = reactive({
  /** 开发默认空串：走 Vite 代理 /api → 9051 */
  apiBase: envString('VITE_API_BASE_URL', '').replace(/\/+$/, ''),
  loginSubView: 'FORM', // FORM | REGISTER | FORGET_PASSWORD
  /** 大厅点「创建房间」时可预选玩法；进创房页后清零 */
  pendingCreateGameType: 0,
  user: {
    uid: 0,
    nickname: '',
    gold: 0,
    /** 房卡（暂无服务端字段时本地展示） */
    cards: 0,
    level: 0,
    avatar: '',
    token: '',
    refreshToken: '',
  },
  room: {
    roomId: '',
    gameType: 0,
    playName: '',
    isMaster: false,
    ownerId: 0,
    ownerName: '',
    myIndex: null,
    players: [],
    handCards: [],
    js: 0,
    playedDone: null,
    zjs: 6,
    df: 1,
    payMode: 1,
    payModeText: '',
    payModeDesc: '',
    payAmount: 1,
    askMode: 2,
    askModeText: '',
    bombMultiple: 10,
    seats: 3,
  },
})

export function resolveToken() {
  if (session.user.token) return session.user.token
  return readStorage(TOKEN_KEY)
}

export function resolveRefreshToken() {
  if (session.user.refreshToken) return session.user.refreshToken
  return readStorage(REFRESH_TOKEN_KEY)
}

export function persistToken(token) {
  session.user.token = token || ''
  writeStorage(TOKEN_KEY, session.user.token)
}

export function persistRefreshToken(token) {
  session.user.refreshToken = token || ''
  writeStorage(REFRESH_TOKEN_KEY, session.user.refreshToken)
}

/** 登录 / 刷新后统一写入 access + refresh（refresh 缺省则保留旧值） */
export function persistAuthTokens({ accessToken, refreshToken } = {}) {
  if (accessToken != null) persistToken(String(accessToken || ''))
  if (refreshToken != null && String(refreshToken)) {
    persistRefreshToken(String(refreshToken))
  }
}

export function hydrateTokenFromCache() {
  const token = resolveToken()
  if (token && !session.user.token) session.user.token = token
  const refresh = resolveRefreshToken()
  if (refresh && !session.user.refreshToken) session.user.refreshToken = refresh
  return token
}

export function applyUserProfile(profile = {}) {
  const uid = Number(profile.uid ?? profile.id ?? profile.member_id ?? 0)
  if (Number.isFinite(uid) && uid > 0) session.user.uid = uid

  const nick =
    profile.nickname ?? profile.realname ?? profile.nick ?? profile.realName ?? profile.name
  if (typeof nick === 'string' && nick.trim()) {
    session.user.nickname = nick.trim()
  } else if (!session.user.nickname && typeof profile.email === 'string' && profile.email.includes('@')) {
    session.user.nickname = profile.email.split('@')[0]
  }

  const goldRaw = profile.points ?? profile.gold
  const gold = Number(goldRaw)
  if (Number.isFinite(gold)) session.user.gold = gold

  const cards = Number(profile.cards ?? profile.roomCards ?? profile.room_cards ?? profile.gears)
  if (Number.isFinite(cards) && cards >= 0) session.user.cards = cards

  const level = Number(profile.level)
  if (Number.isFinite(level) && level >= 0) session.user.level = level

  if (typeof profile.avatar === 'string' && profile.avatar) {
    session.user.avatar = profile.avatar
  }
  if (typeof profile.avatar_url === 'string' && profile.avatar_url) {
    session.user.avatar = profile.avatar_url
  }

  const access = profile.access_token ?? profile.accessToken ?? profile.token
  const refresh = profile.refresh_token ?? profile.refreshToken
  if (typeof access === 'string' && access) persistToken(access)
  if (typeof refresh === 'string' && refresh) persistRefreshToken(refresh)
}

export function clearSession() {
  persistToken('')
  persistRefreshToken('')
  session.user.uid = 0
  session.user.nickname = ''
  session.user.gold = 0
  session.user.cards = 0
  session.user.level = 0
  session.user.avatar = ''
  session.loginSubView = 'FORM'
  session.pendingCreateGameType = 0
  session.room.roomId = ''
  session.room.gameType = 0
  session.room.playName = ''
  session.room.isMaster = false
  session.room.ownerId = 0
  session.room.ownerName = ''
  session.room.myIndex = null
  session.room.players = []
  session.room.handCards = []
  session.room.js = 0
  session.room.playedDone = null
  session.room.zjs = 6
}

export function applyCreatedRoom({ roomId, gameType, playName }) {
  session.room.roomId = String(roomId || '')
  session.room.gameType = Number(gameType) || 0
  session.room.playName = playName || ''
  session.room.isMaster = true
  session.room.ownerId = Number(session.user.uid) || 0
  session.room.ownerName = String(session.user.nickname || '').trim()
  session.room.myIndex = null
  session.room.players = []
}

export function formatGold(n) {
  if (!Number.isFinite(n)) return '0'
  if (n >= 10000) {
    const w = n / 10000
    return `${w >= 100 ? w.toFixed(0) : w.toFixed(1)}万`
  }
  return String(Math.floor(n))
}
