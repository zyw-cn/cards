import { reactive } from 'vue'
import { CARDS_PER_PLAYER, PHASE, type FlowerPhase } from '@/logic/flower/rules/room'
import { SELECT_POINTS } from '@/logic/flower/rules/room'
import {
  applyDismissPacket,
  clearDismissPacket,
  needMyDismissVote,
} from '@/logic/shared/dismiss'

export interface FlowerPlayer {
  id: number
  seat: number
  nickname: string
  avatar: string
  gold: number
  ready: boolean
  cards: string[]
  looked: boolean
  folded: boolean
  lost: boolean
  invested: number
  lastDelta: number
  /** 结算四列：打底 / 闷牌 / 跟牌 / 比牌，输赢由其相加 */
  anteAmt: number
  menAmt: number
  followAmt: number
  pkAmt: number
  bill: string
  pattern: string
  isMaster: boolean
  win: boolean
}

export interface FlowerMatchState {
  phase: FlowerPhase | string
  myIndex: number
  myUid: number
  seats: number
  df: number
  pot: number
  unit: number
  ante: number
  turnSeat: number
  dealerId: number
  dealerSeat: number
  selectPoints: number[]
  menMax: number
  lookMax: number
  players: FlowerPlayer[]
  myCards: string[]
  myPattern: string
  selfReady: boolean
  starting: boolean
  canStart: boolean
  statusText: string
  tip: string
  countdown: number
  /** 倒计时截止时间（毫秒）。显示值由它换算，避免多个计时器重复扣减 */
  countdownUntil: number
  round: number
  totalRound: number
  selfDelta: number
  /** 本局没有比牌，金币已退回 */
  noCompare: boolean
  roomOver: boolean
  dealing: boolean
  compareOpen: boolean
  resultOpen: boolean
  /** 比牌 VS 层（对齐 game FlowerPkOverlay） */
  pkShow: boolean
  pkDone: boolean
  pkLoserId: number
  pkLeft: { id: number; nickname: string; avatar: string }
  pkRight: { id: number; nickname: string; avatar: string }
  trusted: boolean
  dismissOpen: boolean
  dismissFromSeat: number
  dismissStates: number[]
  dismissLeft: number
}

function createInitial(): FlowerMatchState {
  return {
    phase: PHASE.WAIT,
    myIndex: 0,
    myUid: 0,
    seats: 6,
    df: 1,
    pot: 0,
    unit: 0,
    ante: 0,
    turnSeat: -1,
    dealerId: 0,
    dealerSeat: 0,
    selectPoints: [...SELECT_POINTS],
    menMax: 2,
    lookMax: 5,
    players: [],
    myCards: [],
    myPattern: '',
    selfReady: false,
    starting: false,
    canStart: false,
    statusText: '',
    tip: '',
    countdown: 0,
    countdownUntil: 0,
    round: 0,
    totalRound: 0,
    selfDelta: 0,
    noCompare: false,
    roomOver: false,
    dealing: false,
    compareOpen: false,
    resultOpen: false,
    pkShow: false,
    pkDone: false,
    pkLoserId: 0,
    pkLeft: { id: 0, nickname: '', avatar: '' },
    pkRight: { id: 0, nickname: '', avatar: '' },
    trusted: false,
    dismissOpen: false,
    dismissFromSeat: 0,
    dismissStates: [],
    dismissLeft: 0,
  }
}

export const flowerState: FlowerMatchState = reactive(createInitial())

/** 重开一截倒计时。秒数为 0 时停表。多个计时器都读同一个截止时间，不会越走越快。 */
export function setFlowerCountdown(seconds: number): void {
  const sec = Math.max(0, Math.floor(Number(seconds) || 0))
  flowerState.countdown = sec
  flowerState.countdownUntil = sec > 0 ? Date.now() + sec * 1000 : 0
}

export function resetFlowerState(): void {
  Object.assign(flowerState, createInitial())
}

export function flowerMe(): FlowerPlayer | undefined {
  return flowerState.players.find((p) => p.id === flowerState.myUid)
}

export function flashFlowerTip(msg: string, ms = 2000): void {
  if (!msg) return
  flowerState.tip = msg
  window.setTimeout(() => {
    if (flowerState.tip === msg) flowerState.tip = ''
  }, ms)
}

export function applyFlowerDismissVote(data: Record<string, unknown>): void {
  applyDismissPacket(flowerState, data)
}

export function clearFlowerDismiss(): void {
  clearDismissPacket(flowerState)
}

export function needMyFlowerDismissVote(): boolean {
  return needMyDismissVote(flowerState)
}

export function backs(n = CARDS_PER_PLAYER): string[] {
  return Array.from({ length: n }, () => 'back')
}

/** 对局内存。回调继续改 flowerState，不要改成 action。 */
export function useFlowerStore() {
  return { match: flowerState }
}
