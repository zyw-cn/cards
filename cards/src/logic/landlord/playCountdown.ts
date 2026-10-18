import { watch, type WatchStopHandle } from 'vue'
import { canFinishHand, findHint, getCardType } from '@/logic/table/cardRules'
import { currentTableFeatures } from '@/logic/table/playMode'
import { PHASE } from '@/logic/landlord/rules/room'
import { landlordState } from '@/services/landlordState'

function isPlayPhase(phase: string): boolean {
  const p = String(phase || '').toLowerCase()
  return p === PHASE.PLAY || p === 'play'
}

/** 需要跟牌：有上手且上手不是自己（用绝对座位，避免左右映射偏差） */
export function mustFollowNow(): boolean {
  const s = landlordState
  if (!s.lastPlayed.length) return false
  const abs = Number(s.lastPlayedByAbs)
  if (!Number.isFinite(abs) || abs < 0) {
    return s.lastPlayedBy !== '' && s.lastPlayedBy !== 'self'
  }
  return abs !== Number(s.myIndex)
}

export function mustLeadNow(): boolean {
  return !mustFollowNow()
}

/** 跟牌且手牌管不住上家（仅本人视角可判定） */
export function selfCannotBeatNow(): boolean {
  const s = landlordState
  if (!isPlayPhase(String(s.phase))) return false
  if (s.turnIndex == null || Number(s.turnIndex) !== Number(s.myIndex)) return false
  if (!mustFollowNow()) return false
  return findHint(s.hand, s.lastPlayed).length === 0
}

/**
 * 出牌倒计时秒数：本人管不住 → 5s；看别人永远 20s（不暴露自己能否管住）。
 */
export function playCountdownSeconds(): number {
  const s = landlordState
  const t = currentTableFeatures().timeout
  if (s.turnIndex == null || Number(s.turnIndex) < 0) return 0
  const myTurn = Number(s.turnIndex) === Number(s.myIndex)
  if (myTurn && s.trusted) return 0
  if (myTurn && selfCannotBeatNow()) return t.PLAY_CANNOT_BEAT
  return t.PLAY
}

// —— 闹钟：按截止时间对齐，避免 -=1 叠两个 interval 被扣光；换手换 stamp 才重开 ——

let deadlineAt = 0
let turnStamp = ''
let expireFired = false
/** 每次真正开表 +1。超时回调让出后若已换手开了新表，就不再代出 */
let clockGen = 0
/** 最近一次出牌/过牌动作。同一包（RPC+广播）不重开闹钟，新动作即使座位没变也重开 */
let lastActionKey = ''
let countdownTimer: ReturnType<typeof setInterval> | null = null
let expireHandler: (() => void) | null = null
let stopTurnWatch: WatchStopHandle | null = null
/** 换手递增，避免同一座位连着两轮 stamp 撞车不重开 */
let playTurnEpoch = 0
let lastTurnIndexForEpoch = Number.NaN

function nowMs(): number {
  return Date.now()
}

function playTurnStamp(): string {
  return `play:${Number(landlordState.turnIndex)}:${playTurnEpoch}`
}

function bumpEpochIfTurnChanged(): void {
  const turn = Number(landlordState.turnIndex)
  if (!Number.isFinite(turn) || turn < 0) return
  if (turn !== lastTurnIndexForEpoch) {
    playTurnEpoch += 1
    lastTurnIndexForEpoch = turn
  }
}

function remainingSec(): number {
  if (deadlineAt <= 0) return 0
  return Math.max(0, Math.ceil((deadlineAt - nowMs()) / 1000))
}

function ensureTicker(): void {
  if (countdownTimer != null) return
  countdownTimer = setInterval(() => {
    if (deadlineAt <= 0) return
    const left = remainingSec()
    if (landlordState.countdown !== left) landlordState.countdown = left
    if (left > 0 || expireFired) return
    expireFired = true
    deadlineAt = 0
    try {
      expireHandler?.()
    } catch (err) {
      console.warn('[countdown] expire', err)
    }
  }, 200)
}

/** 会话层注册：倒计时归零时按当前阶段自动过 / 不加倍 / 出牌 */
export function setCountdownExpireHandler(fn: (() => void) | null): void {
  expireHandler = fn
}

export function clearCountdownClock(): void {
  if (countdownTimer != null) {
    clearInterval(countdownTimer)
    countdownTimer = null
  }
  deadlineAt = 0
  turnStamp = ''
  expireFired = false
  lastActionKey = ''
}

/** 停表并显示 0（结算 / 清阶段用） */
export function stopCountdownDisplay(): void {
  clearCountdownClock()
  landlordState.countdown = 0
}

/**
 * 开启一轮倒计时（叫抢 / 加倍 / 出牌共用）。
 * 用截止时间，不按 tick 累减，重复开表也不会把秒数扣成两倍速。
 */
export function currentCountdownGen(): number {
  return clockGen
}

export function startCountdownClock(sec: number): void {
  const n = Math.max(0, Math.floor(Number(sec) || 0))
  clockGen += 1
  expireFired = false
  if (n <= 0) {
    deadlineAt = 0
    turnStamp = ''
    landlordState.countdown = 0
    return
  }
  deadlineAt = nowMs() + n * 1000
  landlordState.countdown = n
  ensureTicker()
}

/**
 * 叫抢轮次没有在走的闹钟时补开。
 * 发牌动画若没走到开表，按钮会停在 0，到点也无法默认不叫。
 */
export function ensureBidCountdown(): void {
  const feat = currentTableFeatures()
  if (!feat.hasBid) return
  const phase = String(landlordState.phase)
  const p = phase.toLowerCase()
  if (p !== PHASE.BID && p !== PHASE.GRAB && p !== 'bid' && p !== 'grab') return
  if (landlordState.dealing) return
  const turn = Number(landlordState.bidTurn)
  if (!Number.isFinite(turn) || turn < 0) return
  const key = `phase:${phase}:${turn}`
  if (turnStamp === key && (deadlineAt > nowMs() || expireFired)) return
  restartCountdown(feat.timeout.BID || 10)
}

/** 叫抢 / 加倍：写入秒数并开表 */
export function restartCountdown(sec: number): void {
  turnStamp = `phase:${String(landlordState.phase)}:${Number(landlordState.bidTurn)}`
  startCountdownClock(sec)
}

/** 同一出牌轮次已在走：不打断；换手 / 发牌结束才重开 */
export function syncPlayCountdown(opts?: { duringDeal?: boolean }): void {
  const s = landlordState
  if (!isPlayPhase(String(s.phase))) return
  // 发牌动画期间也记下截止时间，结束后只露出剩余秒，避免服务端 20 秒先到
  if (s.dealing && !opts?.duringDeal) return
  bumpEpochIfTurnChanged()
  const sec = playCountdownSeconds()
  const key = playTurnStamp()
  if (sec <= 0) {
    stopCountdownDisplay()
    return
  }
  if (key === turnStamp && deadlineAt > nowMs()) return
  turnStamp = key
  startCountdownClock(sec)
}

/** 出牌/过牌包身份：同一 RPC+广播不重开，新的过牌或出牌即使座位回到自己也重开 */
export function playClockActionKey(data: Record<string, unknown>): string {
  const cards = Array.isArray(data.cards) ? data.cards.length : 0
  return [
    data.currentIndex ?? '',
    data.playIndex ?? '',
    data.passIndex ?? '',
    data.action ?? '',
    cards,
  ].join(':')
}

/**
 * 换手重开。
 * actionKey 来自出牌/过牌包：新动作即使又轮到同一座位也整表重开；同一包重复到达不重置。
 */
export function syncPlayCountdownForce(actionKey?: string): void {
  const s = landlordState
  if (!isPlayPhase(String(s.phase))) return
  if (s.dealing) return
  if (actionKey && actionKey !== lastActionKey) {
    lastActionKey = actionKey
    playTurnEpoch += 1
    lastTurnIndexForEpoch = Number(s.turnIndex)
    turnStamp = ''
  }
  bumpEpochIfTurnChanged()
  const sec = playCountdownSeconds()
  const key = playTurnStamp()
  if (sec <= 0) {
    stopCountdownDisplay()
    return
  }
  if (key === turnStamp) {
    // 这一轮已经走完或正在走：不要再开一表，避免同一人闹钟在满秒和剩余秒之间跳
    if (expireFired || deadlineAt <= nowMs()) return
    const left = remainingSec()
    if (sec < left) startCountdownClock(sec)
    return
  }
  turnStamp = key
  startCountdownClock(sec)
}

/** 管不上：把偏长的闹钟压到短闹钟（已 ≤ 短闹钟则不动） */
export function shortenPlayCountdownIfNeeded(): void {
  const s = landlordState
  if (!isPlayPhase(String(s.phase))) return
  if (Number(s.turnIndex) !== Number(s.myIndex) || s.trusted) return
  if (!selfCannotBeatNow()) return
  const short = currentTableFeatures().timeout.PLAY_CANNOT_BEAT
  if (s.countdown > 0 && s.countdown <= short) return
  turnStamp = playTurnStamp()
  startCountdownClock(short)
}

/** 进桌后监听换手 / 发牌结束：看别人永远从 PLAY 秒重开 */
export function bindPlayCountdownWatch(): void {
  stopTurnWatch?.()
  stopTurnWatch = watch(
    () =>
      [
        String(landlordState.phase),
        Number(landlordState.turnIndex ?? -1),
        landlordState.dealing,
        Number(landlordState.bidTurn ?? -1),
      ] as const,
    ([phase, turn, dealing], prev) => {
      if (!isPlayPhase(phase)) {
        ensureBidCountdown()
        return
      }
      if (dealing) {
        if (!isPlayPhase(phase) || !Number.isFinite(turn) || turn < 0) {
          stopCountdownDisplay()
          return
        }
        queueMicrotask(() => {
          if (!landlordState.dealing) return
          if (!isPlayPhase(String(landlordState.phase))) return
          if (Number(landlordState.turnIndex) !== turn) return
          syncPlayCountdown({ duringDeal: true })
        })
        return
      }
      if (!isPlayPhase(phase)) return
      if (!Number.isFinite(turn) || turn < 0) return
      const prevDealing = prev ? prev[2] : undefined
      const turnChanged = !prev || Number(prev[1]) !== turn || String(prev[0]) !== phase
      const dealJustEnded = !!prevDealing && !dealing
      if (!turnChanged && !dealJustEnded) return
      queueMicrotask(() => {
        if (landlordState.dealing) return
        if (!isPlayPhase(String(landlordState.phase))) return
        if (Number(landlordState.turnIndex) !== turn) return
        // 同一轮已经在走就不要清 stamp，否则数字会被打回满秒
        syncPlayCountdownForce()
      })
    },
    { immediate: true },
  )
}

export function unbindPlayCountdownWatch(): void {
  stopTurnWatch?.()
  stopTurnWatch = null
}

/** 剩一手可合法出完（整手牌型能领出或压过上家） */
export function canAutoFinishNow(): boolean {
  const s = landlordState
  if (!s.hand.length) return false
  const ok = mustFollowNow()
    ? canFinishHand(s.hand, s.lastPlayed)
    : canFinishHand(s.hand, [])
  if (!ok) return false
  // 只有整手牌是一手合法牌型才自动出。不是最后一手不走这里。
  // 手里不止一张、且下家只剩 1 张时不自动出。末手四带不自动出。
  if (currentTableFeatures().kind === 'pdk') {
    if (s.hand.length > 1 && Number(s.seats.right?.cardsLeft) === 1) return false
    const t = getCardType(s.hand, { isLastHand: true }) as { type?: string } | null
    if (t?.type === 'four2' || t?.type === 'four2pair') return false
  }
  return true
}
