import { reactive } from 'vue'
import { KING_TILE } from '@/logic/hzmj/rules/constants'
import { emptyTileLists, emptyUpLists } from '@/logic/hzmj/protocol'
import { PHASE, type HzmjPhase } from '@/logic/hzmj/rules/room'
import { sortTiles, tileLabel } from '@/logic/hzmj/tile'
import {
  applyDismissPacket,
  clearDismissPacket,
  needMyDismissVote,
} from '@/logic/shared/dismiss'

export interface HzmjSeatView {
  occupied: boolean
  nickname: string
  avatar: string
  uid: number
  gold: number
  isReady: boolean
  isOffline: boolean
  /** 托管中：头像 tip + 倒计时用短表 */
  isTrusted: boolean
  isMaster: boolean
  seatIndex: number | null
  tileCount: number
  sex: number
}

function emptySeat(): HzmjSeatView {
  return {
    occupied: false,
    nickname: '',
    avatar: '',
    uid: 0,
    gold: 0,
    isReady: false,
    isOffline: false,
    isTrusted: false,
    isMaster: false,
    seatIndex: null,
    tileCount: 0,
    sex: 0,
  }
}

export interface HzmjPendingDiscard {
  tile: number
  fromSeat: number
}

export interface HzmjMatchState {
  phase: HzmjPhase | string
  myIndex: number
  myIndexLocked: boolean
  canStart: boolean
  selfReady: boolean
  starting: boolean
  statusText: string
  playerNum: number
  /** 自己手牌（牌码） */
  hand: number[]
  /** 各座位手牌张数占位 / 局末摊牌真实牌 */
  hands: number[][]
  /** 各座位弃牌 */
  discards: number[][]
  /** 各座位副露 */
  upTiles: number[][][]
  /** 桌面可操作 SHOW_OP 列表（本家 myWaitOps） */
  notifyOps: number[]
  /** 当前出牌座位 curSeat */
  turnIndex: number | null
  dealerIndex: number
  kingTiles: number[]
  pendingDiscard: HzmjPendingDiscard | null
  selectedTileIdx: number | null
  trusted: boolean
  countdown: number
  leftTiles: number
  round: number
  totalRound: number
  luckyNum: number
  luckyTiles: number[]
  luckyHit: number
  /** 底分 */
  df: number
  scoreDeltas: Record<number, number>
  totalScores: Record<number, number>
  finalTile: number
  winOp: string
  winSeat: number
  /** 抢杠胡被抢座位；非抢杠为 -1 */
  settleFromSeat: number
  /** win | draw_end | null */
  settleResult: string | null
  settleReason: string
  roomOver: boolean
  showResult: boolean
  diceList: number[]
  /** 抢庄：seat -> multiple；-1 未抢 */
  grabsBySeat: Record<number, number>
  myGrabMultiple: number
  /** 并列随机定庄时当前闪动的座位；-1 表示不闪 */
  rollHighlightSeat: number
  bankBeishu: number
  settle: Record<string, unknown> | null
  tip: string
  /** 开局发牌动画中：桌上手牌须先全部清空 */
  dealing: boolean
  dismissOpen: boolean
  dismissFromSeat: number
  dismissStates: number[]
  dismissLeft: number
  seats: HzmjSeatView[]
}

function createInitial(): HzmjMatchState {
  const n = 4
  return {
    phase: PHASE.WAIT_READY,
    myIndex: 0,
    myIndexLocked: false,
    canStart: false,
    selfReady: false,
    starting: false,
    statusText: '等待其他玩家加入',
    playerNum: n,
    hand: [],
    hands: emptyTileLists(n),
    discards: emptyTileLists(n),
    upTiles: emptyUpLists(n),
    notifyOps: [],
    turnIndex: null,
    dealerIndex: 0,
    kingTiles: [KING_TILE],
    pendingDiscard: null,
    selectedTileIdx: null,
    trusted: false,
    countdown: 0,
    leftTiles: 0,
    round: 0,
    totalRound: 8,
    luckyNum: 2,
    luckyTiles: [],
    luckyHit: 0,
    df: 1,
    scoreDeltas: {},
    totalScores: {},
    finalTile: 0,
    winOp: '',
    winSeat: -1,
    settleFromSeat: -1,
    settleResult: null,
    settleReason: '',
    roomOver: false,
    showResult: false,
    diceList: [],
    grabsBySeat: {},
    myGrabMultiple: -1,
    rollHighlightSeat: -1,
    bankBeishu: 1,
    settle: null,
    tip: '',
    dealing: false,
    dismissOpen: false,
    dismissFromSeat: 0,
    dismissStates: [],
    dismissLeft: 0,
    seats: [emptySeat(), emptySeat(), emptySeat(), emptySeat()],
  }
}

export const hzmjState: HzmjMatchState = reactive(createInitial())

export function resetHzmjState(): void {
  Object.assign(hzmjState, createInitial())
}

export function setMyHand(tiles: number[]): void {
  const sorted = sortTiles(tiles.map(Number).filter((n) => Number.isFinite(n)))
  hzmjState.hand = sorted
  const me = hzmjState.myIndex
  if (me >= 0 && me < hzmjState.hands.length) {
    hzmjState.hands[me] = sorted
  }
  if (hzmjState.seats[me]) hzmjState.seats[me].tileCount = sorted.length
}

export function formatHand(tiles: number[]): string {
  return tiles.map((t) => tileLabel(t)).join(' ')
}

export function flashHzmjTip(msg: string, ms = 2000): void {
  if (!msg) return
  hzmjState.tip = msg
  window.setTimeout(() => {
    if (hzmjState.tip === msg) hzmjState.tip = ''
  }, ms)
}

export function applyHzmjDismissVote(data: Record<string, unknown>): void {
  applyDismissPacket(hzmjState, data)
}

export function clearHzmjDismiss(): void {
  clearDismissPacket(hzmjState)
}

export function needMyHzmjDismissVote(): boolean {
  return needMyDismissVote(hzmjState)
}

/** 结算确认：局间准备 or 整房结果 */
export function confirmHzmjSettlement(): void {
  const s = hzmjState
  s.settleResult = null
  s.winSeat = -1
  s.luckyTiles = []
  s.settle = null
  s.hand = []
  s.notifyOps = []
  s.pendingDiscard = null
  s.selectedTileIdx = null
  if (s.roomOver) {
    s.showResult = true
    s.canStart = false
    s.phase = PHASE.SETTLE
  } else {
    s.showResult = false
    s.phase = PHASE.WAIT_READY
    s.canStart = true
    s.selfReady = false
    s.statusText = '请点击开始游戏'
  }
}

/** 点「开始游戏」立刻清掉上一局弃牌/副露（不等发牌包） */
export function clearPreviousRoundDesk(): void {
  const s = hzmjState
  const n = Math.min(4, Math.max(2, Number(s.playerNum) || 4))
  s.discards = emptyTileLists(n)
  s.upTiles = emptyUpLists(n)
  s.hands = emptyTileLists(n)
  s.hand = []
  s.notifyOps = []
  s.pendingDiscard = null
  s.selectedTileIdx = null
  s.finalTile = 0
  s.luckyTiles = []
  s.luckyHit = 0
  s.winSeat = -1
  s.settleFromSeat = -1
  s.winOp = ''
  s.grabsBySeat = {}
  s.myGrabMultiple = -1
  s.rollHighlightSeat = -1
  for (const seat of s.seats) {
    if (seat) seat.tileCount = 0
  }
}

export function closeHzmjResult(): void {
  hzmjState.showResult = false
}
