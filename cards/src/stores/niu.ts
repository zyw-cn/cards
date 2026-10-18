import { reactive } from 'vue'
import { CARDS_PER_PLAYER, PHASE, SELF_MING_FACE, type NiuPhase } from '@/logic/niu/rules/room'
import {
  applyDismissPacket,
  clearDismissPacket,
  needMyDismissVote,
} from '@/logic/shared/dismiss'

export interface NiuPlayer {
  id: number
  seat: number
  nickname: string
  avatar: string
  gold: number
  ready: boolean
  /** 桌面展示用（自己可能是 2 明 3 暗） */
  cards: string[]
  shown: boolean
  /** 自己是否已点「看牌」（仅本人有意义） */
  looked: boolean
  /** 抢庄倍数，-1 未叫 */
  qz: number
  /** 闲家下注倍数，-1 未下 */
  bet: number
  niuType: number
  niuLabel: string
  /** 牌型赔付倍数（开牌/结算回包 niuMultiple） */
  niuMultiple: number
  lastDelta: number
  platformFee: number
  isMaster: boolean
}

function emptyPlayer(id: number, seat: number): NiuPlayer {
  return {
    id,
    seat,
    nickname: '',
    avatar: '',
    gold: 0,
    ready: false,
    cards: [],
    shown: false,
    looked: false,
    qz: -1,
    bet: -1,
    niuType: 0,
    niuLabel: '',
    niuMultiple: 0,
    lastDelta: 0,
    platformFee: 0,
    isMaster: false,
  }
}

export interface NiuMatchState {
  phase: NiuPhase | string
  myIndex: number
  myUid: number
  seats: number
  df: number
  modeName: string
  /** ming | free | tongbi | niusz … 对齐 game room.modeKey */
  modeKey: string
  bankerId: number
  bankBeishu: number
  currentGrabId: number
  players: NiuPlayer[]
  selfReady: boolean
  starting: boolean
  canStart: boolean
  /** 本局已开（startgame 后为 true；防止迟到 joinroom/waitstart 打回准备） */
  inRound: boolean
  statusText: string
  tip: string
  countdown: number
  round: number
  totalRound: number
  selfDelta: number
  myNiuLabel: string
  /** 本人完整手牌（发牌后下发，看牌/亮牌前桌面可能只亮前几张） */
  myCards: string[]
  roomOver: boolean
  dealing: boolean
  rollCandidates: number[]
  rollHighlightId: number
  /** 定庄动画目标庄家（动画结束前 bankerId 仍为 0） */
  rollWinnerId: number
  rollMode: 'tie' | 'system'
  resultOpen: boolean
  trusted: boolean
  dismissOpen: boolean
  dismissFromSeat: number
  dismissStates: number[]
  dismissLeft: number
}

function createInitial(): NiuMatchState {
  return {
    phase: PHASE.WAIT,
    myIndex: 0,
    myUid: 0,
    seats: 6,
    df: 1,
    modeName: '',
    modeKey: 'free',
    bankerId: 0,
    bankBeishu: 1,
    currentGrabId: 0,
    players: [],
    selfReady: false,
    starting: false,
    canStart: false,
    inRound: false,
    statusText: '等待其他玩家加入',
    tip: '',
    countdown: 0,
    round: 0,
    totalRound: 0,
    selfDelta: 0,
    myNiuLabel: '',
    myCards: [],
    roomOver: false,
    dealing: false,
    rollCandidates: [],
    rollHighlightId: 0,
    rollWinnerId: 0,
    rollMode: 'tie',
    resultOpen: false,
    trusted: false,
    dismissOpen: false,
    dismissFromSeat: 0,
    dismissStates: [],
    dismissLeft: 0,
  }
}

export const niuState: NiuMatchState = reactive(createInitial())

export function resetNiuState(): void {
  Object.assign(niuState, createInitial())
}

export function niuMe(): NiuPlayer | undefined {
  const uid = Number(niuState.myUid)
  if (!uid) return undefined
  return niuState.players.find((p) => Number(p.id) === uid)
}

export function flashNiuTip(msg: string, ms = 2000): void {
  if (!msg) return
  niuState.tip = msg
  window.setTimeout(() => {
    if (niuState.tip === msg) niuState.tip = ''
  }, ms)
}

export function applyNiuDismissVote(data: Record<string, unknown>): void {
  applyDismissPacket(niuState, data)
}

export function clearNiuDismiss(): void {
  clearDismissPacket(niuState)
}

export function needMyNiuDismissVote(): boolean {
  return needMyDismissVote(niuState)
}

export function backs(n = CARDS_PER_PLAYER): string[] {
  return Array.from({ length: n }, () => 'back')
}

/** 本人桌面牌：未看/未亮时前 SELF_MING_FACE 张正面，其余背面 */
export function maskSelfHand(
  real: string[],
  opts: { looked?: boolean; shown?: boolean; mingFace?: number } = {},
): string[] {
  if (!real.length) return []
  if (opts.shown || opts.looked) return real.slice()
  const n = Math.max(0, Math.min(real.length, opts.mingFace ?? SELF_MING_FACE))
  return real.map((c, i) => (i < n ? c : 'back'))
}

export { emptyPlayer }

/** 对局内存。回调继续改 niuState，不要改成 action。 */
export function useNiuStore() {
  return { match: niuState }
}
