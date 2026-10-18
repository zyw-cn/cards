/**
 * 跑得快局末结算 → 共用 SettleResultView 模型（无地主角色）
 */
import { gameState } from '@/services/gameState'
import { landlordState, type SettleResultModel, type SettleRow } from '@/services/landlordState'
import { MAX_PLAYERS } from '@/logic/pdk/rules/room'
import { indexToSide, type SeatSide } from '@/logic/shared/roomSeats'

type RewardRow = {
  seatNo?: number
  delta?: number
  points?: number
  platformFee?: number
  roomFee?: number
  remain?: number
  cardDelta?: number
  bombDelta?: number
  isBomber?: boolean
  fangzouPayer?: boolean
  fangzouCovered?: boolean
}

let coinFlySeq = 1

/** 本局已打出的炸弹分，按座位累计。局末结算并进「剩牌 × 底分 + 炸弹」 */
const bombScoreBySeat = new Map<number, number>()

export function resetPdkBombScores(): void {
  bombScoreBySeat.clear()
}

function trimScore(n: number): string {
  if (!Number.isFinite(n)) return '0'
  return Math.abs(n).toFixed(2).replace(/0+$/, '').replace(/\.$/, '')
}

function pdkFangzouNote(settle: Record<string, unknown>, rows: SettleRow[]): string {
  const raw = settle.fangzou
  if (!raw || typeof raw !== 'object') return ''
  const fz = raw as { payerSeat?: number; covers?: Array<{ seatNo?: number; remain?: number; pay?: number }> }
  const payer = rows.find((row) => row.absSeat === Number(fz.payerSeat))
  const covers = Array.isArray(fz.covers) ? fz.covers : []
  if (!payer || !covers.length) return ''
  const unit = trimScore(Number(settle.unit) || 0)
  const parts = covers.map((cover) => {
    const who = rows.find((row) => row.absSeat === Number(cover.seatNo))
    const name = who?.nickname || '对手'
    const left = Number(cover.remain) || 0
    const pay = trimScore(Number(cover.pay) || 0)
    return `${name}剩${left}张，${left}×底分${unit}=${pay}，由${payer.nickname}支付`
  })
  return `放走包赔：${payer.nickname}出单未出最大，下家先出完。${parts.join('；')}。`
}

export function applyPdkSettleResult(settle: Record<string, unknown>): SettleResultModel | null {
  if (!settle) return null
  const myIndex = Number(landlordState.myIndex)
  const winnerAbs = Number(settle.winnerIndex ?? -1)
  const rewards = Array.isArray(settle.rewards) ? (settle.rewards as RewardRow[]) : []
  const byAbs = Object.fromEntries(rewards.map((r) => [Number(r.seatNo), r]))

  const rows: SettleRow[] = []
  for (let abs = 0; abs < MAX_PLAYERS; abs += 1) {
    const p = gameState.room.players.find((x) => Number(x.absSeat) === abs)
    const r = byAbs[abs]
    const cardDelta = r?.cardDelta != null ? Number(r.cardDelta) : Number(r?.delta ?? 0)
    const serverBomb = r?.bombDelta != null ? Number(r.bombDelta) : 0
    const localBomb = bombScoreBySeat.get(abs) || 0
    const bombDelta = serverBomb !== 0 ? serverBomb : localBomb
    const delta = cardDelta + bombDelta
    const isWinner = Number.isFinite(winnerAbs) && abs === winnerAbs
    if (p && r?.points != null) p.gold = Number(r.points)
    if (abs === myIndex && r?.points != null) {
      gameState.user.gold = Number(r.points)
    }
    rows.push({
      absSeat: abs,
      nickname: p?.nickname || (abs === myIndex ? gameState.user.nickname || '我' : `玩家${abs + 1}`),
      avatar: p?.avatar || (abs === myIndex ? gameState.user.avatar : '') || '',
      role: 'farmer',
      roleLabel: '',
      delta,
      platformFee: Number(r?.platformFee ?? r?.roomFee ?? 0) || 0,
      farmerDouble: 1,
      isWinner,
      isSelf: abs === myIndex,
      isBigWinner: false,
      remain: Number(r?.remain ?? 0) || 0,
      cardDelta,
      bombDelta,
      fangzouPayer: Boolean(r?.fangzouPayer),
      fangzouCovered: Boolean(r?.fangzouCovered),
    })
  }

  const winners = rows.filter((r) => r.isWinner)
  if (winners.length) {
    const big = winners.reduce((a, b) => (b.delta > a.delta ? b : a), winners[0])
    if (big) big.isBigWinner = true
  }

  const selfWin = Number.isFinite(winnerAbs) && winnerAbs === myIndex
  const model: SettleResultModel = {
    winSide: selfWin ? 'landlord' : 'farmer',
    selfWin,
    selfRole: selfWin ? 'landlord' : 'farmer',
    spring: Boolean(settle.shutout || settle.spring),
    springType: settle.shutout ? 'shutout' : String(settle.springType || ''),
    multiple: Number(settle.multiple) || 1,
    bidMultiple: 0,
    bombCount: Number(settle.bombCount ?? landlordState.bombCount) || 0,
    unit: Number(settle.unit) || 0,
    bombUnit: Number(settle.bombUnit) || 0,
    platformFee: (settle.platformFee as SettleResultModel['platformFee']) || null,
    roomOver: Boolean(settle.roomOver),
    rows,
    fangzouNote: pdkFangzouNote(settle, rows),
  }
  landlordState.result = model
  landlordState.showSettle = true
  resetPdkBombScores()
  return model
}

/** 局中炸弹即时分：更新金币并写短 tip */
export function applyPdkBombSettle(bombSettle: Record<string, unknown>): void {
  if (!bombSettle || !Array.isArray(bombSettle.rewards)) return
  const myIndex = Number(landlordState.myIndex)
  const rewards = bombSettle.rewards as RewardRow[]
  const mul = Number(bombSettle.bombMultiple) || 10
  rewards.forEach((r) => {
    const abs = Number(r.seatNo)
    bombScoreBySeat.set(abs, (bombScoreBySeat.get(abs) || 0) + (Number(r.delta) || 0))
    const p = gameState.room.players.find((x) => Number(x.absSeat) === abs)
    if (p && r.points != null) p.gold = Math.max(0, Number(r.points) || 0)
    if (abs === myIndex && r.points != null) {
      gameState.user.gold = Math.max(0, Number(r.points) || 0)
    }
  })
  const mine = rewards.find((r) => Number(r.seatNo) === myIndex)
  const myDelta = Number(mine?.delta) || 0
  const bomberAbs = Number(bombSettle.bomberSeat)
  landlordState.bombFlash =
    bomberAbs === myIndex
      ? `炸弹得分 ${myDelta > 0 ? '+' : ''}${myDelta || mul * 2}`
      : myDelta !== 0
        ? `炸弹结算 ${myDelta > 0 ? '+' : ''}${myDelta}`
        : `炸弹结算（${mul}分/人）`
  window.setTimeout(() => {
    if (landlordState.bombFlash) landlordState.bombFlash = ''
  }, 1800)
  const bc = Number(bombSettle.bombCount)
  if (Number.isFinite(bc)) landlordState.bombCount = bc
  else landlordState.bombCount += 1
  cueBombCoinFly(bomberAbs, rewards)
}

/** 出炸者收分：另外两家的金币飞向出炸头像 */
function cueBombCoinFly(bomberAbs: number, rewards: RewardRow[]): void {
  if (!Number.isFinite(bomberAbs) || bomberAbs < 0) return
  const myIndex = Number(landlordState.myIndex)
  const target = indexToSide(myIndex, bomberAbs)
  const payers = rewards.filter(
    (r) => Number(r.seatNo) !== bomberAbs && (r.isBomber === false || Number(r.delta) < 0),
  )
  const sources = [
    ...new Set(payers.map((r) => indexToSide(myIndex, Number(r.seatNo)))),
  ].filter((side) => side !== target)
  const all: SeatSide[] = ['self', 'left', 'right']
  landlordState.coinFly = {
    id: coinFlySeq++,
    target,
    sources: sources.length ? sources : all.filter((side) => side !== target),
  }
}
