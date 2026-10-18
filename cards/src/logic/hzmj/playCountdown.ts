/**
 * 红中麻将桌心倒计时：截止时间对齐；换手/新问询才重开。
 * 超时代打只走服务端；本人托管时藏表（对齐斗地主）。
 */
import { FAKE_COUNTDOWN } from '@/logic/hzmj/rules/constants'
import { hzmjState } from '@/services/hzmjState'

type ClockBag = {
  countdownDeadlineAt: number
  dismissDeadlineAt: number
  countdownTimer: ReturnType<typeof setInterval> | null
  tickActive: boolean
  /** 当前这一轮闹钟身份；相同则不重开，避免 RPC+广播 / 重复推送把秒数刷乱 */
  turnStamp: string
  expireFired: boolean
  expireHandler: (() => void) | null
}

const GKEY = '__hzmjPlayCountdown'
function bag(): ClockBag {
  const g = globalThis as unknown as Record<string, ClockBag>
  if (!g[GKEY]) {
    g[GKEY] = {
      countdownDeadlineAt: 0,
      dismissDeadlineAt: 0,
      countdownTimer: null,
      tickActive: false,
      turnStamp: '',
      expireFired: false,
      expireHandler: null,
    }
  } else {
    // HMR / 旧 bag 补字段
    if (g[GKEY].expireHandler === undefined) g[GKEY].expireHandler = null
    if (g[GKEY].expireFired === undefined) g[GKEY].expireFired = false
  }
  return g[GKEY]
}

function nowMs(): number {
  return Date.now()
}

export function stopHzmjCountdownTick(): void {
  const b = bag()
  if (b.countdownTimer) {
    clearInterval(b.countdownTimer)
    b.countdownTimer = null
  }
  b.tickActive = false
}

export function startHzmjCountdownTick(): void {
  stopHzmjCountdownTick()
  const b = bag()
  b.tickActive = true
  b.expireFired = false
  b.countdownTimer = setInterval(() => {
    if (!b.tickActive) return
    if (b.dismissDeadlineAt > 0) {
      const left = Math.max(0, Math.ceil((b.dismissDeadlineAt - nowMs()) / 1000))
      if (hzmjState.dismissLeft !== left) hzmjState.dismissLeft = left
      if (left <= 0) b.dismissDeadlineAt = 0
    }
    if (b.countdownDeadlineAt <= 0) {
      if (hzmjState.countdown !== 0) hzmjState.countdown = 0
      return
    }
    const left = Math.max(0, Math.ceil((b.countdownDeadlineAt - nowMs()) / 1000))
    if (hzmjState.countdown !== left) hzmjState.countdown = left
    if (left > 0 || b.expireFired) return
    b.expireFired = true
    b.countdownDeadlineAt = 0
    try {
      b.expireHandler?.()
    } catch (err) {
      console.warn('[hzmj countdown] expire', err)
    }
  }, 250)
}

export function clearHzmjCountdownClock(): void {
  stopHzmjCountdownTick()
  const b = bag()
  b.countdownDeadlineAt = 0
  b.dismissDeadlineAt = 0
  b.turnStamp = ''
  b.expireFired = false
  hzmjState.countdown = 0
}

/** 会话层注册：倒计时归零时代打（服务端也会超时；双边幂等） */
export function setHzmjCountdownExpireHandler(fn: (() => void) | null): void {
  bag().expireHandler = fn
}

/** 强制开表（抢庄 / 发牌首轮） */
export function armHzmjCountdown(sec: number, stamp?: string): void {
  const b = bag()
  const n = Math.max(0, Math.floor(Number(sec) || 0))
  if (stamp) b.turnStamp = stamp
  b.expireFired = false
  if (n <= 0) {
    b.countdownDeadlineAt = 0
    hzmjState.countdown = 0
    return
  }
  b.countdownDeadlineAt = nowMs() + n * 1000
  hzmjState.countdown = n
}

/**
 * 出牌阶段同步闹钟：同一轮（同 stamp）不重开，避免重复包把 15 秒刷成连跳。
 * force=true 时无视 stamp（碰杠后必须出牌等）。
 */
export function syncHzmjPlayCountdown(opts: {
  stamp: string
  sec?: number
  force?: boolean
}): void {
  const b = bag()
  const sec = opts.sec != null ? opts.sec : hzmjPlayCountdownSec()
  if (!opts.force && opts.stamp && opts.stamp === b.turnStamp && b.countdownDeadlineAt > nowMs()) {
    return
  }
  b.turnStamp = opts.stamp
  armHzmjCountdown(sec)
}

export function armHzmjDismissLeft(sec: number): void {
  const b = bag()
  const n = Math.max(0, Math.floor(Number(sec) || 0))
  if (n <= 0) {
    b.dismissDeadlineAt = 0
    hzmjState.dismissLeft = 0
    return
  }
  b.dismissDeadlineAt = nowMs() + n * 1000
  hzmjState.dismissLeft = n
}

/** 与服务端 TRUSTED_PLAY_TIMEOUT 一致：托管代打前可见倒计时，避免藏表后「突然出牌」 */
export const TRUSTED_PLAY_COUNTDOWN = 2

function anyTrustedSeat(): boolean {
  return (hzmjState.seats || []).some((seat) => !!seat?.isTrusted)
}

/**
 * 桌心倒计时秒数。
 * 当前操作者（出牌位 / 问询）已托管时，全桌都显示短倒计时，与服务端代打间隔对齐。
 * 切勿对托管开 0 秒藏表——旁观者仍是 15s 而服务端已代打，会感觉「迅速打出有问题」。
 */
export function hzmjPlayCountdownSec(): number {
  const s = hzmjState
  const claiming = (s.notifyOps || []).length > 0
  const turn = Number(s.turnIndex)
  const turnTrusted = Number.isFinite(turn) && turn >= 0 && !!s.seats[turn]?.isTrusted

  if (s.pendingDiscard || claiming) {
    if (s.trusted || anyTrustedSeat()) return TRUSTED_PLAY_COUNTDOWN
  }
  if (!s.pendingDiscard && turnTrusted) return TRUSTED_PLAY_COUNTDOWN

  return FAKE_COUNTDOWN
}

/** 由 op 推送生成闹钟身份：换手 / 新问询才变 */
export function hzmjCountdownStamp(data: Record<string, unknown>): string {
  const s = hzmjState
  const action = String(data.action || data.opName || '').toLowerCase()
  const turn = Number(data.curSeat ?? data.currentIndex ?? s.turnIndex)
  const pending = data.pendingDiscard != null ? 1 : s.pendingDiscard ? 1 : 0
  const waitKey = Array.isArray(data.myWaitOps)
    ? (data.myWaitOps as unknown[]).join(',')
    : (s.notifyOps || []).join(',')
  // discard/pass/pong 本身带入 stamp，避免同 turn 连续过牌不刷新
  return [action, turn, pending, waitKey, data.tile ?? '', data.seat ?? ''].join(':')
}
