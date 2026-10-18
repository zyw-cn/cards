/**
 * 全玩法统一的解散文案与状态逻辑（各房间仅 tip 落点可不同）
 */
import { tipImgs } from '@/logic/shared/tipImgs'

export const DISMISS_TITLE = '解散房间'
export const DISMISS_OWNER_ONLY = '只有房主可以解散房间'
export const DISMISS_VOTE_AGREE = '同意'
export const DISMISS_VOTE_REJECT = '不同意'

/** 全玩法统一的解散说明（规则弹窗 / 大厅玩法） */
export const DISMISS_RULE_NOTE = Object.freeze({
  title: '解散说明',
  lines: [
    '未开局：仅房主可解散房间。',
    '等人开局或局间等待：超过 3 分钟未开始，将自动解散房间并退费（未开局退整张房卡；已打过局按剩余局数折算金币返还房主）。',
    '进入牌局后：返回大厅不解散，将自动托管（约 2 秒后代打）并保留座位。',
    '进入牌局后：不允许单方面解散，须全员同意方可解散。',
  ],
})

/** 单行摘要，用于房间规则 tip */
export const DISMISS_RULE_TIP =
  '未开局仅房主可解散；等人/局间超 3 分钟未开始将自动解散并退费；进入牌局后返回大厅将自动托管（约 2 秒后代打），不允许单方面解散，须全员同意方可解散。'
export function dismissConfirmText(idle: boolean): string {
  return idle
    ? '确定解散房间吗？未开局将立即解散。'
    : '确定申请解散吗？需全员同意后才会解散房间。'
}

export function dismissWaitText(isApplicant: boolean, left: number): string {
  const sec = Math.max(0, Math.floor(Number(left) || 0))
  return isApplicant
    ? `已申请，等待其余玩家同意（${sec}s）`
    : `已选择，等待其他玩家（${sec}s）`
}

export type DismissSlice = {
  dismissOpen: boolean
  dismissFromSeat: number
  dismissStates: number[]
  dismissLeft: number
  myIndex: number
}

export function needMyDismissVote(s: DismissSlice): boolean {
  if (!s.dismissOpen) return false
  if (Number(s.dismissFromSeat) === Number(s.myIndex)) return false
  return Number(s.dismissStates[s.myIndex] ?? 0) === 0
}

export function isDismissApplicant(s: DismissSlice): boolean {
  return !!(s.dismissOpen && Number(s.dismissFromSeat) === Number(s.myIndex))
}

/** 座位解散 tip：发起=申请解散；已投=同意/拒绝；未投不显示 */
export function dismissTipSrc(
  s: Pick<DismissSlice, 'dismissOpen' | 'dismissFromSeat' | 'dismissStates'>,
  seat: number,
): string {
  if (!s.dismissOpen) return ''
  const seatN = Number(seat)
  if (!Number.isFinite(seatN) || seatN < 0) return ''
  if (Number(s.dismissFromSeat) === seatN) return tipImgs.applyDismiss
  const st = Number(s.dismissStates[seatN] ?? 0)
  if (st === 1) return tipImgs.agreeDismiss
  if (st === 2) return tipImgs.rejectDismiss
  return ''
}

export function showDismissEntry(opts: {
  dismissOpen: boolean
  idle: boolean
  isOwner: boolean
  roomOver?: boolean
}): boolean {
  if (opts.roomOver) return false
  if (opts.dismissOpen) return false
  if (opts.idle) return opts.isOwner
  return true
}

export function applyDismissPacket(s: DismissSlice, data: Record<string, unknown>): void {
  const from = Number(data.applyCloseFromSeat ?? data.applyCloseFrom ?? 0)
  const list = Array.isArray(data.applyCloseStateList)
    ? data.applyCloseStateList.map((n) => Number(n) || 0)
    : []
  s.dismissFromSeat = Number.isFinite(from) ? from : 0
  s.dismissStates = list
  s.dismissOpen = true
  const left = Number(data.applyCloseLeftTime)
  if (Number.isFinite(left) && left > 0) {
    s.dismissLeft = Math.floor(left)
  } else if (s.dismissLeft <= 0) {
    s.dismissLeft = 90
  }
}

export function clearDismissPacket(s: DismissSlice): void {
  s.dismissOpen = false
  s.dismissFromSeat = 0
  s.dismissStates = []
  s.dismissLeft = 0
}

/** 本地乐观投票；失败时回滚 */
export function optimisticDismissVote(s: DismissSlice, vote: 1 | 2): (() => void) | null {
  if (!s.dismissOpen) return null
  const idx = Number(s.myIndex)
  if (Number(s.dismissFromSeat) === idx) return null
  if (Number(s.dismissStates[idx] ?? 0) !== 0) return null
  const prev = [...s.dismissStates]
  const next = [...s.dismissStates]
  while (next.length <= idx) next.push(0)
  next[idx] = vote
  s.dismissStates = next
  return () => {
    s.dismissStates = prev
  }
}
