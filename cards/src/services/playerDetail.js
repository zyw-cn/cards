import { reactive } from 'vue'
import { http } from './http'
import { formatGold, session } from './session'
import { seatAvatarSrc } from './seatAvatar'

export const playerDetail = reactive({
  open: false,
  nickname: '',
  uid: 0,
  goldText: '0',
  cardsText: '—',
  avatar: '',
})

function fillFromSession() {
  const uid = Number(session.user.uid) || 0
  playerDetail.nickname = session.user.nickname || '玩家'
  playerDetail.uid = uid
  playerDetail.goldText = formatGold(Number(session.user.gold) || 0)
  const cards = Number(session.user.cards)
  playerDetail.cardsText = Number.isFinite(cards) ? String(Math.floor(cards)) : '0'
  playerDetail.avatar = seatAvatarSrc(session.user.avatar, uid)
}

/**
 * @param {{ uid?: number, nickname?: string, gold?: number, avatar?: string }} player
 */
export async function showPlayerDetail(player = {}) {
  const uid = Number(player.uid) || 0
  const self = uid > 0 && uid === Number(session.user.uid)
  playerDetail.open = true
  playerDetail.uid = uid
  playerDetail.nickname = player.nickname || (self ? session.user.nickname : '') || '玩家'
  playerDetail.goldText = formatGold(Number(player.gold) || 0)
  playerDetail.avatar = seatAvatarSrc(player.avatar || '', uid)
  playerDetail.cardsText = self ? String(Math.floor(Number(session.user.cards) || 0)) : '—'
  if (self) fillFromSession()

  if (uid <= 0) return
  try {
    const res = await http.post('/api/member/brief', { id: uid })
    if (!playerDetail.open || playerDetail.uid !== uid) return
    if (res.code !== 0 || !res.data) return
    const data = res.data
    playerDetail.nickname = data.nickname || playerDetail.nickname
    playerDetail.uid = Number(data.id) || uid
    playerDetail.goldText = formatGold(Number(data.gold ?? data.points) || 0)
    const cards = Number(data.cards ?? data.room_cards)
    if (Number.isFinite(cards)) playerDetail.cardsText = String(Math.floor(cards))
    if (data.avatar || data.avatar_url) {
      playerDetail.avatar = seatAvatarSrc(data.avatar_url || data.avatar, playerDetail.uid)
    }
    if (self) {
      session.user.nickname = playerDetail.nickname
      session.user.gold = Number(data.gold ?? data.points) || session.user.gold
      if (Number.isFinite(cards)) session.user.cards = Math.floor(cards)
    }
  } catch {
    /* 旧进程没有 brief 时，自己用本地资料，他人房卡保持 — */
  }
}

export function closePlayerDetail() {
  playerDetail.open = false
}
