import { gameState } from '@/services/gameState'
import { landlordState, type SettleResultModel, type SettleRow } from '@/services/landlordState'
import { MAX_PLAYERS } from '@/logic/landlord/rules/room'
import { stopCountdownDisplay } from '@/logic/landlord/playCountdown'
import { eventBus, LandlordEvents } from '@/core/eventBus'

type RewardRow = {
  seatNo?: number
  delta?: number
  points?: number
  isLandlord?: boolean
  farmerDouble?: number
  platformFee?: number
  roomFee?: number
}

/**
 * 从 settle 包生成结算页模型（对齐旧 LandlordRoom.applySettleResult）
 */
export function applySettleResult(settle: Record<string, unknown>): SettleResultModel | null {
  if (!settle) return null
  const myIndex = Number(landlordState.myIndex)
  const lordAbs = landlordState.landlordIndex
  const selfIsLord = lordAbs != null && Number(lordAbs) === myIndex

  const winnerAbs = Number(settle.winnerIndex ?? -1)
  const landlordWins =
    settle.landlordWin === true ||
    settle.winSide === 'landlord' ||
    (Number.isFinite(winnerAbs) && lordAbs != null && winnerAbs === Number(lordAbs))

  const winSide: 'landlord' | 'farmer' = landlordWins ? 'landlord' : 'farmer'
  const selfWin = landlordWins ? selfIsLord : !selfIsLord

  const rewards = Array.isArray(settle.rewards) ? (settle.rewards as RewardRow[]) : []
  const byAbs = Object.fromEntries(rewards.map((r) => [Number(r.seatNo), r]))
  const farmerDoublesMap = (settle.farmerDoubles || landlordState.farmerDoubles || {}) as Record<
    string,
    number
  >

  const rows: SettleRow[] = []
  for (let abs = 0; abs < MAX_PLAYERS; abs += 1) {
    const p = gameState.room.players.find((x) => Number(x.absSeat) === abs)
    const r = byAbs[abs]
    const delta = Number(r?.delta ?? 0)
    const isLord = r?.isLandlord === true || (lordAbs != null && abs === Number(lordAbs))
    const onWinSide = landlordWins ? isLord : !isLord
    const seatKey = String(r?.seatNo ?? abs)
    const choice = Number(farmerDoublesMap[seatKey] ?? farmerDoublesMap[abs] ?? 0)
    let fd = Number(r?.farmerDouble ?? 0)
    if (fd <= 0) fd = choice >= 2 ? 2 : 1

    if (p && r?.points != null) p.gold = Number(r.points)
    if (abs === myIndex && r?.points != null) {
      gameState.user.gold = Number(r.points)
    }

    rows.push({
      absSeat: abs,
      nickname: p?.nickname || (abs === myIndex ? gameState.user.nickname || '我' : `玩家${abs + 1}`),
      avatar: p?.avatar || (abs === myIndex ? gameState.user.avatar : '') || '',
      role: isLord ? 'landlord' : 'farmer',
      roleLabel: isLord ? '地主' : '农民',
      delta,
      platformFee: Number(r?.platformFee ?? r?.roomFee ?? 0) || 0,
      farmerDouble: fd > 1 ? fd : 1,
      isWinner: onWinSide,
      isSelf: abs === myIndex,
      isBigWinner: false,
    })
  }

  const winners = rows.filter((r) => r.isWinner)
  if (winners.length) {
    const big = winners.reduce((a, b) => (b.delta > a.delta ? b : a), winners[0])
    if (big) big.isBigWinner = true
  }

  const multiple = Number(settle.multiple) || landlordState.multiple || 1
  const model: SettleResultModel = {
    winSide,
    selfWin,
    selfRole: selfIsLord ? 'landlord' : 'farmer',
    spring: !!settle.spring,
    springType: String(settle.springType || (settle.spring ? 'spring' : 'none')),
    multiple,
    bidMultiple: Number(settle.bidMultiple) || multiple,
    bombCount: Number(settle.bombCount) || landlordState.bombCount || 0,
    platformFee: (settle.platformFee || settle.roomFee || null) as SettleResultModel['platformFee'],
    roomOver: !!settle.roomOver,
    rows,
  }

  landlordState.multiple = multiple
  landlordState.settle = settle
  landlordState.result = model
  // showSettle 由牌桌队列在特效播完后打开
  return model
}

/** 再来一局 / 开始游戏：清结算，回到等待准备（不离房） */
export function prepareNextRoundLocally(): void {
  const s = landlordState
  s.showSettle = false
  s.result = null
  s.settle = null
  s.phase = 'waitready'
  s.hand = []
  s.bottom = []
  s.selectedCodes = []
  s.outBySide = { self: [], left: [], right: [] }
  s.passBadgeBySide = { self: false, left: false, right: false }
  s.actionBadgeBySide = { self: '', left: '', right: '' }
  s.lastPlayed = []
  s.lastPlayedBy = ''
  s.lastPlayedByAbs = -1
  s.bidTurn = null
  s.maxBid = 0
  s.hasCaller = false
  s.multiple = 1
  s.landlordIndex = null
  s.turnIndex = null
  s.trusted = false
  if (s.seats.left.occupied) s.seats.left.isTrusted = false
  if (s.seats.right.occupied) s.seats.right.isTrusted = false
  s.bombCount = 0
  s.farmerDoubles = {}
  s.doubleOpsLocked = false
  stopCountdownDisplay()
  s.tipReveal = ''
  s.centerTip = ''
  s.handTip = ''
  s.dealing = false
  s.dealPlayed = false
  s.selfReady = false
  s.starting = false
  s.mustSpade3 = false
  s.bombFlash = ''
  s.canStart = gameState.room.players.length >= MAX_PLAYERS
  s.readyCount = 0
  s.statusText = ''
  gameState.room.players.forEach((p) => {
    p.ready = false
  })
  if (s.seats.left.occupied) {
    s.seats.left.cardsLeft = null
    s.seats.left.isReady = false
    s.seats.left.isOffline = false
    s.seats.left.isTrusted = false
  }
  if (s.seats.right.occupied) {
    s.seats.right.cardsLeft = null
    s.seats.right.isReady = false
    s.seats.right.isOffline = false
    s.seats.right.isTrusted = false
  }
  eventBus.emit(LandlordEvents.PHASE, s.phase)
}

/** 点「开始游戏」立刻清掉上一局桌面出牌（不等发牌包） */
export function clearPreviousRoundDesk(): void {
  const s = landlordState
  s.outBySide = { self: [], left: [], right: [] }
  s.passBadgeBySide = { self: false, left: false, right: false }
  s.lastPlayed = []
  s.lastPlayedBy = ''
  s.lastPlayedByAbs = -1
  s.bottom = []
  s.actionBadgeBySide = { self: '', left: '', right: '' }
  eventBus.emit(LandlordEvents.PHASE, s.phase)
}
