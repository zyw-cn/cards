import { reactive } from 'vue'
import type { ClientCard } from '@/logic/shared/serverCard'
import type { SeatSide, SeatView } from '@/logic/shared/roomSeats'
import { indexToSide } from '@/logic/shared/roomSeats'
import { PHASE, type LandlordPhase } from '@/logic/landlord/rules/room'
import {
  applyDismissPacket,
  clearDismissPacket,
  needMyDismissVote as needMyDismissVoteShared,
} from '@/logic/shared/dismiss'

export type ActionBadge =
  | ''
  | 'call'
  | 'rob'
  | 'noBid'
  | 'noRob'
  | 'double'
  | 'noDouble'
  | 'pass'

export interface SettleRow {
  absSeat: number
  nickname: string
  avatar: string
  role: 'landlord' | 'farmer'
  roleLabel: string
  delta: number
  platformFee: number
  farmerDouble: number
  isWinner: boolean
  isSelf: boolean
  isBigWinner: boolean
  /** 跑得快剩余张数 */
  remain?: number
  /** 跑得快剩牌分（已含关牌） */
  cardDelta?: number
  /** 跑得快本局炸弹分 */
  bombDelta?: number
  /** 放走包赔：这一家替别人付了剩牌分 */
  fangzouPayer?: boolean
  /** 放走包赔：这一家的剩牌分由别人支付 */
  fangzouCovered?: boolean
}

export interface SettleResultModel {
  winSide: 'landlord' | 'farmer'
  selfWin: boolean
  selfRole: 'landlord' | 'farmer'
  spring: boolean
  springType: string
  multiple: number
  bidMultiple: number
  bombCount: number
  /** 跑得快底分 */
  unit?: number
  /** 跑得快单边炸弹分 */
  bombUnit?: number
  platformFee: {
    charged?: boolean
    payMode?: number
    payAmount?: number
  } | null
  roomOver: boolean
  rows: SettleRow[]
  /** 跑得快放走包赔说明 */
  fangzouNote?: string
}

export interface LandlordSeatMap {
  left: SeatView
  right: SeatView
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

export interface LandlordMatchState {
  phase: LandlordPhase | string
  myIndex: number
  /** Join 后锁定，避免他人入座广播的 seatNo 覆盖自己 */
  myIndexLocked: boolean
  canStart: boolean
  /** 自己已点「开始游戏」 */
  selfReady: boolean
  starting: boolean
  statusText: string
  readyCount: number
  hand: ClientCard[]
  bottom: ClientCard[]
  seats: LandlordSeatMap
  outBySide: Record<SeatSide, ClientCard[]>
  /** 出牌区「不出」等 */
  passBadgeBySide: Record<SeatSide, boolean>
  /** 叫抢/加倍角标 */
  actionBadgeBySide: Record<SeatSide, ActionBadge>
  lastPlayed: ClientCard[]
  lastPlayedBy: SeatSide | ''
  /** 上手出牌绝对座位；-1 表示需领出 / 无上手 */
  lastPlayedByAbs: number
  bidTurn: number | null
  maxBid: number
  /** 是否已有人叫过地主（进入抢地主阶段） */
  hasCaller: boolean
  multiple: number
  landlordIndex: number | null
  turnIndex: number | null
  selectedCodes: string[]
  trusted: boolean
  bombCount: number
  settle: Record<string, unknown> | null
  /** 结算页展示数据 */
  result: SettleResultModel | null
  /** 是否弹出结算页 */
  showSettle: boolean
  /** seatAbs → 0|2 */
  farmerDoubles: Record<string, number>
  doubleOpsLocked: boolean
  /** 倒计时秒 */
  countdown: number
  /**
   * tip 收束阶段：bid=叫抢已结束等 1s 再清；double=加倍已齐等 1s 再清。
   * 期间禁止清/覆盖对应 tip，全员继续互见。
   */
  tipReveal: '' | 'bid' | 'double'
  /**
   * 叫抢/加倍 tip 展示中：为 true 时隐藏操作按钮，避免盖住 tip。
   * tip 展示一段时间后自动置 false。
   */
  opsTipHold: boolean
  /** 中央 tip 资源 key（tableTips） */
  centerTip: string
  /** 手牌区 tip：出牌不合规 / 打不过上家等 */
  handTip: string
  /** 发牌飞牌进行中：隐藏 Vue 手牌与叫抢按钮 */
  dealing: boolean
  /** 本局发牌动画已入队，重复 startgame 不再播第二遍 */
  dealPlayed: boolean
  /** 对局中解散投票 */
  dismissOpen: boolean
  dismissFromSeat: number
  /** 座位绝对序号 → 0未选 1同意 2拒绝 */
  dismissStates: number[]
  dismissLeft: number
  /** 跑得快：须出含红桃3（首出提示） */
  mustSpade3: boolean
  /** 持红桃3先出的绝对座位，开局广播给每一台客户端 */
  spade3FromAbs: number | null
  /** 每次开局 +1，用来重新弹出先出提示 */
  spade3TipId: number
  /** 发牌结束后在先出头像旁显示「持红桃3者先出」 */
  spade3Hold: boolean
  /** 跑得快：局中炸弹飘分文案 */
  bombFlash: string
  /** 跑得快：炸弹收分，金币从另外两家飞向出炸者 */
  coinFly: { id: number; target: SeatSide; sources: SeatSide[] } | null
  /** 快捷聊天气泡（按相对座位） */
  chatBySide: Record<SeatSide, string>
}

function createInitial(): LandlordMatchState {
  return {
    phase: PHASE.WAIT_READY,
    myIndex: 0,
    myIndexLocked: false,
    canStart: false,
    selfReady: false,
    starting: false,
    statusText: '',
    readyCount: 0,
    hand: [],
    bottom: [],
    seats: { left: emptySeat(), right: emptySeat() },
    outBySide: { self: [], left: [], right: [] },
    passBadgeBySide: { self: false, left: false, right: false },
    actionBadgeBySide: { self: '', left: '', right: '' },
    lastPlayed: [],
    lastPlayedBy: '',
    lastPlayedByAbs: -1,
    bidTurn: null,
    maxBid: 0,
    hasCaller: false,
    multiple: 1,
    landlordIndex: null,
    turnIndex: null,
    selectedCodes: [],
    trusted: false,
    bombCount: 0,
    settle: null,
    result: null,
    showSettle: false,
    farmerDoubles: {},
    doubleOpsLocked: false,
    countdown: 0,
    tipReveal: '',
    opsTipHold: false,
    centerTip: '',
    handTip: '',
    dealing: false,
    dealPlayed: false,
    dismissOpen: false,
    dismissFromSeat: 0,
    dismissStates: [],
    dismissLeft: 0,
    mustSpade3: false,
    spade3FromAbs: null,
    spade3TipId: 0,
    spade3Hold: false,
    bombFlash: '',
    coinFly: null,
    chatBySide: { self: '', left: '', right: '' },
  }
}

/** 斗地主对局唯一数据源（Pixi / Vue 只读映射） */
export const landlordState: LandlordMatchState = reactive(createInitial())

export function resetLandlordState(): void {
  clearChatBubbles()
  Object.assign(landlordState, createInitial())
}

export function toggleSelectCard(code: string): void {
  const i = landlordState.selectedCodes.indexOf(code)
  if (i >= 0) landlordState.selectedCodes.splice(i, 1)
  else landlordState.selectedCodes.push(code)
}

export function clearSelected(): void {
  landlordState.selectedCodes = []
}

export function selectedCards(): ClientCard[] {
  const set = new Set(landlordState.selectedCodes.map((c) => c.toUpperCase()))
  return landlordState.hand.filter((c) => set.has(String(c.code || '').toUpperCase()))
}

export function setFarmerDouble(seatAbs: number, score: number): void {
  const s = score > 0 ? 2 : 0
  landlordState.farmerDoubles = {
    ...landlordState.farmerDoubles,
    [String(seatAbs)]: s,
    [seatAbs]: s,
  }
}

export function needMyDouble(): boolean {
  const my = landlordState.myIndex
  const map = landlordState.farmerDoubles
  return map[String(my)] == null && map[my] == null
}

export function flashHandTip(key: string, ms = 1800): void {
  if (!key) return
  landlordState.handTip = key
  window.setTimeout(() => {
    if (landlordState.handTip === key) landlordState.handTip = ''
  }, ms)
}

const chatTimers: Partial<Record<SeatSide, ReturnType<typeof setTimeout>>> = {}

/** 展示座位聊天气泡（absSeat 为服务端绝对座位） */
export function showChatBubble(absSeat: number, text: unknown, ms = 3200): void {
  const msg = String(text || '').trim()
  if (!msg) return
  if (!Number.isFinite(absSeat)) return
  const side = indexToSide(landlordState.myIndex, absSeat)
  const prev = chatTimers[side]
  if (prev) {
    clearTimeout(prev)
    delete chatTimers[side]
  }
  landlordState.chatBySide = { ...landlordState.chatBySide, [side]: msg }
  chatTimers[side] = setTimeout(() => {
    if (landlordState.chatBySide[side] === msg) {
      landlordState.chatBySide = { ...landlordState.chatBySide, [side]: '' }
    }
    delete chatTimers[side]
  }, ms)
}

export function clearChatBubbles(): void {
  ;(['self', 'left', 'right'] as const).forEach((side) => {
    const t = chatTimers[side]
    if (t) clearTimeout(t)
    delete chatTimers[side]
  })
  landlordState.chatBySide = { self: '', left: '', right: '' }
}

/** 收到申请解散推送且自己还未投票（发起人不当成待投票） */
export function needMyDismissVote(): boolean {
  return needMyDismissVoteShared(landlordState)
}

/** 对局中解散投票：0 未选 / 1 同意 / 2 拒绝 */
export function applyDismissVote(data: Record<string, unknown>): void {
  applyDismissPacket(landlordState, data)
}

export function clearDismissVote(): void {
  clearDismissPacket(landlordState)
}

/** 对局内存。回调继续改 landlordState，不要改成 action。 */
export function useLandlordStore() {
  return { match: landlordState }
}
