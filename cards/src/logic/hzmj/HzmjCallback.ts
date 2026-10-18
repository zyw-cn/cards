import { GameCallback } from '@/logic/shared/GameCallback'
import { CallBack } from '@/logic/shared/CallBack'
import {
  applyReadyFlags,
  computeCanStart,
  findSelfAbsIndex,
  occupiedPlayers,
  toRelativePlayers,
  waitStatusText,
  type RelativePlayer,
} from '@/logic/shared/roomSeats'
import { PHASE, TIMEOUT, mapServerPhase } from '@/logic/hzmj/rules/room'
import { KING_TILE, SHOW_OP } from '@/logic/hzmj/rules/constants'
import { sortTiles } from '@/logic/hzmj/tile'
import {
  mapDiscardBySeat,
  mapHandsBySeat,
  mapUpBySeat,
  normalizeScores,
  parsePendingDiscard,
  toIntList,
  emptyTileLists,
  emptyUpLists,
} from '@/logic/hzmj/protocol'
import { playHzmjOp, playHzmjSfx, playHzmjDiscard } from '@/logic/hzmj/hzmjAudio'
import { armHzmjCountdown, armHzmjDismissLeft, hzmjCountdownStamp, hzmjPlayCountdownSec, syncHzmjPlayCountdown } from '@/logic/hzmj/playCountdown'
import { applyRoomSnapshot as applyGlobalRoom, gameState } from '@/services/gameState'
import {
  applyHzmjDismissVote,
  clearHzmjDismiss,
  clearPreviousRoundDesk,
  flashHzmjTip,
  hzmjState,
  setMyHand,
} from '@/services/hzmjState'
import type { ActionQueue } from '@/core/actionQueue'
import { eventBus } from '@/core/eventBus'

/** 骰子只取一对用来播旧骰子图。服务端可能是平铺或按座位成对。 */
function readSeat(v: unknown): number | null {
  if (v == null || v === '') return null
  const n = Number(v)
  if (!Number.isFinite(n) || n < 0) return null
  return Math.floor(n)
}

/** 全桌同步托管：头像旁 tip；本人另写 trusted 并停/开倒计时 */
function applyHzmjTrustSeat(data: Record<string, unknown>, trusted: boolean): void {
  const s = hzmjState
  const seat = Number(data.seatNo ?? data.seat_no ?? data.seat ?? data.hostedIndex)
  const forMe =
    (Number.isFinite(seat) && seat === s.myIndex) ||
    (!Number.isFinite(seat) && data.trusted === trusted)
  if (Number.isFinite(seat) && seat >= 0 && seat < s.seats.length && s.seats[seat]) {
    s.seats[seat].isTrusted = trusted
  }
  if (!forMe) return
  s.trusted = trusted
  // 开/关托管都按当前局面重开表：托管中显示 2s，不再藏表（否则像瞬间出牌）
  syncHzmjPlayCountdown({
    stamp: `${trusted ? 'trust' : 'untrust'}:${s.turnIndex}:${Date.now()}`,
    force: true,
  })
}

/**
 * 最高叫分并列（或全员不抢）时，在这些座位里随机定庄。
 * 赢家必须是服务端已经抽中的座位，动画只负责展示，不再按抢庄先后取第一个。
 */
function bankerRollPlan(
  data: Record<string, unknown>,
): { seats: number[]; winner: number; system: boolean } | null {
  const winner = readSeat(data.dealerSeat ?? data.bankerSeat ?? data.dealerIndex)
  if (winner == null) return null

  let seats: number[] = []
  if (Array.isArray(data.candidates)) {
    seats = data.candidates
      .map((c) => {
        if (!c || typeof c !== 'object') return null
        const r = c as Record<string, unknown>
        return readSeat(r.seat ?? r.seatNo ?? r.index)
      })
      .filter((n): n is number => n != null)
  }
  const grabs = Array.isArray(data.grabs) ? data.grabs : []
  if (seats.length < 2 && grabs.length >= 2) {
    const rows: { seat: number; multiple: number }[] = []
    for (const raw of grabs) {
      if (!raw || typeof raw !== 'object') continue
      const r = raw as Record<string, unknown>
      const seat = readSeat(r.seat ?? r.seatNo ?? r.index)
      const multiple = Number(r.multiple ?? r.mul)
      if (seat == null || !Number.isFinite(multiple)) continue
      rows.push({ seat, multiple })
    }
    if (rows.length >= 2) {
      const max = Math.max(...rows.map((r) => r.multiple))
      const pool = max <= 0 ? rows : rows.filter((r) => r.multiple === max)
      seats = pool.map((r) => r.seat)
    }
  }
  const unique = [...new Set(seats)]
  if (unique.length < 2) return null
  if (!unique.includes(winner)) unique.push(winner)
  const allPass =
    grabs.length > 0 &&
    grabs.every((g) => {
      if (!g || typeof g !== 'object') return false
      return Number((g as Record<string, unknown>).multiple ?? 0) <= 0
    })
  return { seats: unique, winner, system: !!data.isSystemBanker || allPass }
}

function readDicePair(raw: unknown, dealerSeat: number): number[] {
  if (!Array.isArray(raw) || !raw.length) return []
  if (Array.isArray(raw[0])) {
    const dealer = raw[dealerSeat]
    const pair = Array.isArray(dealer) ? dealer : raw.find((p) => Array.isArray(p))
    if (!Array.isArray(pair)) return []
    return pair.map(Number).filter((n) => n > 0).slice(0, 2)
  }
  return raw.map(Number).filter((n) => n > 0).slice(0, 2)
}

export const HzmjEvents = Object.freeze({
  STATE: 'hzmj:state',
  START: 'hzmj:start',
  OP: 'hzmj:op',
  SETTLE: 'hzmj:settle',
  DISSOLVE: 'hzmj:dissolve',
})

type Ctx = {
  queue: ActionQueue
  onDissolved?: (data: Record<string, unknown>) => void
}

/**
 * 红中麻将 WS 回调：只改 hzmjState / gameState，不碰 Pixi。
 * 字段对齐 hyperf TurnHandler / ResumeBuilder / StartGameAction。
 */
export class HzmjCallback extends GameCallback {
  declare protected ctx: Ctx & Record<string, unknown>
  private rollSeq = 0
  private rollTimer: ReturnType<typeof setInterval> | null = null
  private rollDoneTimer: ReturnType<typeof setTimeout> | null = null

  constructor(ctx: Ctx) {
    super(ctx as unknown as Record<string, unknown>)
    this.register(CallBack.JOIN_ROOM, this.onJoinRoom)
    this.register(CallBack.ROOM_READY, this.onRoomReady)
    this.register(CallBack.WAIT_START, this.onWaitStart)
    this.register(CallBack.GRAB, this.onGrab)
    this.register(CallBack.START_GAME, this.onStartGame)
    this.register(CallBack.OP, this.onOp)
    this.register(CallBack.ROOM_DISSOLVE, this.onRoomDissolve)
    this.register(CallBack.APPLY_DISMISS, this.onApplyDismiss)
    this.register(CallBack.VOTE_DISMISS, this.onVoteDismiss)
    this.register(CallBack.CANCEL_DISMISS, this.onCancelDismiss)
    this.register(CallBack.TRUST, this.onTrust)
    this.register(CallBack.UNTRUST, this.onUntrust)
  }

  onRoomDissolve(data: Record<string, unknown>): void {
    clearHzmjDismiss()
    this.ctx.onDissolved?.(data)
    eventBus.emit(HzmjEvents.DISSOLVE, data)
  }

  onApplyDismiss(data: Record<string, unknown>): void {
    applyHzmjDismissVote(data)
    armHzmjDismissLeft(hzmjState.dismissLeft)
  }

  onVoteDismiss(data: Record<string, unknown>): void {
    applyHzmjDismissVote(data)
    armHzmjDismissLeft(hzmjState.dismissLeft)
    if (data.dissolve) this.onRoomDissolve(data)
  }

  onCancelDismiss(data: Record<string, unknown>): void {
    const vote = Number(data.vote)
    // 有人不同意：先在其头像旁落下提示，房间不解散
    if (vote === 2) {
      applyHzmjDismissVote(data)
      armHzmjDismissLeft(0)
      window.setTimeout(() => clearHzmjDismiss(), 1600)
    } else {
      clearHzmjDismiss()
      armHzmjDismissLeft(0)
    }
    eventBus.emit(HzmjEvents.DISSOLVE, { ...data, cancelled: true })
  }

  onTrust(data: Record<string, unknown>): void {
    applyHzmjTrustSeat(data, true)
  }

  onUntrust(data: Record<string, unknown>): void {
    applyHzmjTrustSeat(data, false)
  }

  onJoinRoom(data: Record<string, unknown>): void {
    this.applyRoomSnapshot(data, { forceReady: false })
    this.applyJoinResume(data)
  }

  onRoomReady(data: Record<string, unknown>): void {
    this.applyRoomSnapshot(data, { forceReady: true })
    this.applyJoinResume(data)
  }

  /** Join / RoomReady：对局中附带 resume → 恢复手牌/弃牌/副露（断线重连） */
  private applyJoinResume(data: Record<string, unknown>): void {
    if (data.resumeError) {
      flashHzmjTip(`牌局恢复失败: ${String(data.resumeError)}`)
    }
    if (!data.inGame || !data.resume || typeof data.resume !== 'object') return
    const resume = data.resume as Record<string, unknown>
    const phase = String(resume.phase || '').toLowerCase()
    if (phase === 'grab' || phase === 'grabbanker') {
      this.onGrab({ ...resume, callback: 'grab', _fromResume: true })
      return
    }
    this.onStartGame({
      ...resume,
      callback: 'startgame',
      _fromResume: true,
      myTiles: resume.myTiles || resume.myCards || [],
      myWaitOps: resume.myWaitOps || [],
      discards: resume.discards ?? {},
      ups: resume.ups ?? {},
      trusted: resume.trusted ?? data.trusted,
    })
  }

  onWaitStart(data: Record<string, unknown>): void {
    this.applyReadyOnly(data)
  }

  onGrab(data: Record<string, unknown>): void {
    const s = hzmjState
    const pn = Math.min(4, Math.max(2, resolveHzmjPlayerNum(data, s.playerNum)))
    s.playerNum = pn
    // 仅「刚进抢庄」清桌；叫分进度推送也走本回调，不能清 grabs
    const enteringGrab = String(s.phase).toLowerCase() !== 'grab'
    if (!data._fromResume && enteringGrab) {
      clearPreviousRoundDesk()
      this.stopBankerRoll()
      s.dealerIndex = -1
      s.rollHighlightSeat = -1
    }
    s.phase = PHASE.GRAB
    s.canStart = false
    s.selfReady = true
    s.starting = false
    s.dealing = false
    s.diceList = []
    s.notifyOps = []
    s.hand = []
    // 已叫过分的人不要被别人的进度推送打回「请选择」
    if (s.myGrabMultiple < 0) {
      s.statusText = '请选择抢庄倍数'
    }
    s.countdown = Number(data.countdown ?? TIMEOUT.GRAB) || TIMEOUT.GRAB
    armHzmjCountdown(s.countdown)
    this.applyGrabs(data.grabs)
    if (data.lastGrab && typeof data.lastGrab === 'object') {
      this.applyGrabs([data.lastGrab])
    }
    if (data.seat != null && data.multiple != null) {
      this.applyGrabs([
        {
          index: data.seat,
          seat: data.seat,
          seatNo: data.seat,
          multiple: data.multiple,
        },
      ])
    }
    this.applyRoomSnapshot(data)
    // 定庄并发牌包会带手牌
    if (Array.isArray(data.myTiles) || Array.isArray(data.handTiles)) {
      this.onStartGame({ ...data, callback: 'startgame' })
    }
  }

  onStartGame(data: Record<string, unknown>): void {
    const s = hzmjState
    const fromResume = !!data._fromResume
    if (
      !fromResume &&
      !data._bankerCommitted &&
      String(s.phase).toLowerCase() !== 'play' &&
      String(s.phase).toLowerCase() !== 'playing' &&
      this.beginBankerRoll(data)
    ) {
      return
    }
    this.stopBankerRoll()
    s.rollHighlightSeat = -1
    // 正式开局：先进入 dealing；断线重连跳过发牌动画，直接恢复弃牌/手牌/副露
    s.dealing = !fromResume
    const pn = Math.min(4, Math.max(2, resolveHzmjPlayerNum(data, s.playerNum)))
    s.playerNum = pn
    s.phase = PHASE.PLAY
    // 重连时用 resume.mySeat 校正本人座位
    if (fromResume && data.mySeat != null && Number.isFinite(Number(data.mySeat))) {
      s.myIndex = Number(data.mySeat)
      s.myIndexLocked = true
      gameState.room.myIndex = s.myIndex
    }
    s.canStart = false
    s.selfReady = false
    s.starting = false
    s.settle = null
    s.settleResult = null
    s.settleReason = ''
    s.showResult = false
    s.roomOver = false
    s.luckyTiles = []
    s.luckyHit = 0
    s.scoreDeltas = {}
    s.finalTile = 0
    s.winOp = ''
    s.winSeat = -1
    s.settleFromSeat = -1
    s.statusText = ''
    s.selectedTileIdx = null
    s.grabsBySeat = {}
    s.myGrabMultiple = -1
    s.bankBeishu = Number(data.bankBeishu ?? 1) || 1
    s.round = Number(data.playedCount ?? data.curRound ?? s.round) || s.round + 1
    if (data.totalCount != null) s.totalRound = Number(data.totalCount) || s.totalRound

    const dealerSeat = readSeat(data.dealerSeat ?? data.bankerSeat ?? data.dealerIndex)
    s.dealerIndex = dealerSeat == null ? 0 : dealerSeat
    s.turnIndex = Number(
      data.curSeat ?? data.currentIndex ?? data.curPlayerSit ?? s.dealerIndex,
    )
    s.leftTiles = Number(data.leftTileNum ?? data.leftTiles ?? data.remainCount ?? 0) || 0
    const kings = toIntList(data.kingTiles)
    s.kingTiles = kings.length ? kings : [KING_TILE]
    s.luckyNum = Number(data.luckyNum ?? s.luckyNum) || 2
    const df = Number(data.df)
    if (Number.isFinite(df) && df > 0) s.df = df
    s.trusted = !!(data.trusted)
    // 抢庄模式：不再播放骰子
    s.diceList = []
    this.applyGrabs(data.grabs)
    s.discards = mapDiscardBySeat(data.discards, pn)
    s.upTiles = mapUpBySeat(data.ups, pn)
    s.pendingDiscard = parsePendingDiscard(data.pendingDiscard)
    s.notifyOps = toIntList(data.myWaitOps ?? data.notifyOpList ?? data.notifyOps)

    const tiles = toIntList(data.myTiles ?? data.handTiles ?? data.tiles)
    if (tiles.length) setMyHand(tiles)
    else s.hand = []

    s.hands = emptyTileLists(pn)
    s.hands[s.myIndex] = [...s.hand]
    this.syncSeatTileCounts(data)
    for (let i = 0; i < pn; i++) {
      if (i === s.myIndex) continue
      const cnt = s.seats[i]?.tileCount || 0
      s.hands[i] = Array.from({ length: Math.max(0, cnt) }, () => 0)
    }

    this.applyRoomSnapshot(data)
    s.phase = PHASE.PLAY
    syncHzmjPlayCountdown({
      stamp: `start:${s.turnIndex}:${s.hand.length}`,
      force: true,
    })
    // 与后端发牌后 arm 超时同一时刻开表；发牌动画中不自动出牌（dealing）

    if (Array.isArray(data.applyCloseStateList) && data.applyCloseStateList.length) {
      applyHzmjDismissVote(data)
      armHzmjDismissLeft(hzmjState.dismissLeft)
    }

    if (fromResume) {
      s.dealing = false
      eventBus.emit(HzmjEvents.STATE, data)
      return
    }

    // 发牌音效在 animateDeal 分组播放，此处不抢先；START 先清桌面手牌
    eventBus.emit(HzmjEvents.START, data)
    this.ctx.queue.enqueue({ type: 'hzmj_start', payload: data })
  }

  onOp(data: Record<string, unknown>): void {
    const s = hzmjState
    const n = s.playerNum
    const me = s.myIndex

    if (data.discards) s.discards = mapDiscardBySeat(data.discards, n)
    if (data.ups) s.upTiles = mapUpBySeat(data.ups, n)
    if (data.curSeat != null || data.currentIndex != null) {
      s.turnIndex = Number(data.curSeat ?? data.currentIndex)
    }
    if (data.dealerSeat != null) s.dealerIndex = Number(data.dealerSeat)
    if (data.leftTileNum != null || data.leftTiles != null) {
      s.leftTiles = Number(data.leftTileNum ?? data.leftTiles) || 0
    }
    if (data.kingTiles) s.kingTiles = toIntList(data.kingTiles)

    if (data.totalScores) {
      s.totalScores = normalizeScores(data.totalScores)
    } else if (data.scores) {
      const deltas = normalizeScores(data.scores)
      const totals = { ...s.totalScores }
      for (const [seat, d] of Object.entries(deltas)) {
        const k = Number(seat)
        totals[k] = (Number(totals[k]) || 0) + (Number(d) || 0)
      }
      s.totalScores = totals
    }

    s.pendingDiscard = parsePendingDiscard(data.pendingDiscard)

    if (Array.isArray(data.myTiles)) {
      setMyHand(toIntList(data.myTiles))
      s.selectedTileIdx = null
    } else if (Array.isArray(data.handTiles)) {
      setMyHand(toIntList(data.handTiles))
      s.selectedTileIdx = null
    }

    const action = String(data.action || data.opName || '').toLowerCase()
    if (action === 'discard') s.selectedTileIdx = null

    if (Array.isArray(data.myWaitOps)) {
      s.notifyOps = toIntList(data.myWaitOps)
    } else if (Array.isArray(data.notifyOpList) || Array.isArray(data.notifyOps)) {
      s.notifyOps = toIntList(data.notifyOpList ?? data.notifyOps)
    } else if (!s.pendingDiscard && Number(s.turnIndex) !== me) {
      s.notifyOps = []
    } else if (data.clearOps) {
      s.notifyOps = []
    }

    this.syncSeatTileCounts(data)
    if (Array.isArray(data.seats) && !data.showHands) {
      for (const row of data.seats as Record<string, unknown>[]) {
        const seat = Number(row.seatNo ?? row.seat_no)
        if (!Number.isFinite(seat) || seat === me) continue
        const cnt = Number(row.tileCount ?? row.cardsCount ?? 0) || 0
        s.hands[seat] = Array.from({ length: cnt }, () => 0)
      }
    }
    if (data.showHands) {
      const shown = mapHandsBySeat(data.showHands, n)
      for (let i = 0; i < n; i++) {
        if (shown[i]?.length) s.hands[i] = sortTiles(shown[i])
      }
      if (shown[me]?.length) setMyHand(shown[me])
    }

    this.syncTrustFromOp(data)
    playHzmjOpFromPush(data)

    if (data.phase != null) {
      const mapped = mapServerPhase(data.phase)
      if (mapped === PHASE.PLAY && !data.result) s.phase = PHASE.PLAY
    }

    const result = String(data.result || '')
    if (result === 'win' || action === 'win' || data.action === 'win') {
      this.applyWinSettle(data)
      return
    }
    if (result === 'draw_end') {
      this.applyDrawSettle(data)
      return
    }

    // 倒计时：换手 / 新问询才重开；同包重复到达（同 stamp）不重置
    if (
      action === 'pong' ||
      action === 'kong' ||
      action === 'peng' ||
      action === 'gang' ||
      action === 'discard' ||
      action === 'pass' ||
      (s.countdown <= 0 && Number(s.turnIndex) === me && !s.pendingDiscard)
    ) {
      // 碰杠后必须重开满表；弃牌/过牌用 stamp 去重，避免 RPC+广播连跳
      const force =
        action === 'pong' || action === 'kong' || action === 'peng' || action === 'gang'
      syncHzmjPlayCountdown({
        stamp: hzmjCountdownStamp(data),
        force,
      })
    }

    eventBus.emit(HzmjEvents.OP, data)
    this.ctx.queue.enqueue({ type: 'hzmj_op', payload: data })
  }

  private applyWinSettle(data: Record<string, unknown>): void {
    const s = hzmjState
    const settle = (data.settle && typeof data.settle === 'object'
      ? data.settle
      : {}) as Record<string, unknown>
    const winSeat = Number(data.winSeat ?? data.seat ?? data.winnerSeat ?? s.myIndex)
    const name = s.seats[winSeat]?.nickname || `座位${winSeat + 1}`

    s.phase = PHASE.SETTLE
    s.notifyOps = []
    s.pendingDiscard = null
    s.countdown = 0
    armHzmjCountdown(0)
    s.settle = settle
    s.settleResult = 'win'
    s.settleReason = String(settle.reason || `${name} 胡牌`)
    s.dealerIndex = winSeat
    s.winSeat = winSeat
    s.luckyTiles = toIntList(data.luckyTiles ?? settle.luckyTiles)
    s.luckyHit = Number(data.luckyHit ?? settle.luckyHit ?? 0) || 0
    const settleLucky = Number(settle.luckyNum ?? data.luckyNum)
    if (Number.isFinite(settleLucky) && settleLucky > 0) s.luckyNum = settleLucky
    s.scoreDeltas = normalizeScores(settle.scores ?? data.scores)
    s.roomOver = !!(settle.roomOver ?? data.roomOver)
    s.totalScores = normalizeScores(settle.totalScores ?? data.totalScores ?? s.totalScores)
    s.finalTile = Number(data.finalTile ?? settle.finalTile ?? data.tile ?? 0) || 0
    s.winOp = String(data.winOp ?? settle.winOp ?? '')
    const fromSeat = Number(data.fromSeat ?? settle.fromSeat)
    s.settleFromSeat = Number.isFinite(fromSeat) ? fromSeat : -1
    const settleDf = Number(settle.df ?? data.df)
    if (Number.isFinite(settleDf) && settleDf > 0) s.df = settleDf
    const settleBank = Number(settle.bankBeishu ?? data.bankBeishu ?? data.grabMultiple)
    if (Number.isFinite(settleBank) && settleBank > 0) s.bankBeishu = settleBank
    s.showResult = false
    s.canStart = false
    s.selfReady = false
    s.statusText = ''
    for (const seat of s.seats) seat.isReady = false

    playHzmjSfx('win')
    eventBus.emit(HzmjEvents.SETTLE, data)
    this.ctx.queue.enqueue({ type: 'hzmj_settle', payload: data })
  }

  private applyDrawSettle(data: Record<string, unknown>): void {
    const s = hzmjState
    const settle = (data.settle && typeof data.settle === 'object'
      ? data.settle
      : {}) as Record<string, unknown>

    s.phase = PHASE.SETTLE
    s.notifyOps = []
    s.pendingDiscard = null
    s.countdown = 0
    armHzmjCountdown(0)
    s.settle = settle
    s.settleResult = 'draw_end'
    s.settleReason = String(settle.reason || '流局')
    s.luckyTiles = []
    s.luckyHit = 0
    s.scoreDeltas = {}
    s.roomOver = !!(settle.roomOver ?? data.roomOver)
    s.totalScores = normalizeScores(settle.totalScores ?? data.totalScores ?? s.totalScores)
    s.finalTile = 0
    s.winOp = ''
    s.winSeat = -1
    s.settleFromSeat = -1
    s.showResult = false
    s.canStart = false
    s.selfReady = false
    s.statusText = ''
    for (const seat of s.seats) seat.isReady = false

    playHzmjSfx('win')
    eventBus.emit(HzmjEvents.SETTLE, data)
    this.ctx.queue.enqueue({ type: 'hzmj_settle', payload: data })
  }

  /** 对齐斗地主：仅显式 trust/untrust / autoHosted 改状态，代打出牌不误清托管 */
  private syncTrustFromOp(data: Record<string, unknown>): void {
    const seat = Number(data.seat ?? data.seatNo ?? data.seat_no ?? data.hostedIndex)
    if (data.trusted === false) {
      applyHzmjTrustSeat({ ...data, seatNo: seat }, false)
      return
    }
    if (data.autoHosted || data.hosted || data.trusted === true) {
      applyHzmjTrustSeat({ ...data, seatNo: seat }, true)
    }
  }

  applyRoomSnapshot(data: Record<string, unknown>, opts: { forceReady?: boolean } = {}): void {
    if (!data) return
    const s = hzmjState
    const players = occupiedPlayers(data)
    this.lockMyIndex(data, players)

    if (players.length) {
      const mapped = toRelativePlayers(
        players,
        s.myIndex,
        gameState.room.players as RelativePlayer[],
      )
      applyGlobalRoom({ ...data, myIndex: s.myIndex, players: mapped })
      this.syncSeatsFromPlayers(mapped)
    } else {
      applyGlobalRoom({ ...data, myIndex: s.myIndex })
    }

    const waiting = isWait(s.phase)
    if (waiting) {
      s.phase = PHASE.WAIT_READY
      const mapped = gameState.room.players
      s.canStart = computeCanStart(data, opts) || mapped.length >= Math.min(2, s.playerNum)
      mapped.forEach((p) => {
        p.ready = false
      })
      s.selfReady = false
      this.syncSeatsFromPlayers(mapped as RelativePlayer[])
    }

    const readyIds = Array.isArray(data.readyIds) ? data.readyIds : []
    if (waiting && readyIds.length > 0) {
      this.applyReadyOnly(data)
      return
    }
    if (waiting) {
      s.statusText = waitStatusText(
        gameState.room.players.length,
        s.canStart,
        s.selfReady,
        0,
      )
    }

    if (Array.isArray(data.applyCloseStateList) && data.applyCloseStateList.length) {
      applyHzmjDismissVote(data)
      armHzmjDismissLeft(hzmjState.dismissLeft)
    }

    const pn = resolveHzmjPlayerNum(data, s.playerNum)
    if (pn !== s.playerNum) {
      s.playerNum = pn
      if (s.discards.length !== pn) {
        s.discards = emptyTileLists(pn)
        s.upTiles = emptyUpLists(pn)
        s.hands = emptyTileLists(pn)
      }
    }
    const zjs = Number(data.zjs ?? data.total_play_count ?? data.totalCount)
    if (Number.isFinite(zjs) && zjs > 0) s.totalRound = zjs
    const lucky = Number(data.lucky_num ?? data.luckyNum)
    if (Number.isFinite(lucky) && lucky > 0) s.luckyNum = lucky
    if (data.trusted != null) s.trusted = !!data.trusted

    // 对局中 resume 走 startgame；若 join 带 resume 快照字段则补一次
    if (
      !waiting &&
      (Array.isArray(data.myTiles) || data.discards || data.curSeat != null)
    ) {
      /* 已由 onStartGame / onOp 处理 */
    }

    eventBus.emit(HzmjEvents.STATE, { phase: s.phase })
  }

  applyReadyOnly(data: Record<string, unknown>): void {
    const s = hzmjState
    if (!isWait(s.phase)) s.phase = PHASE.WAIT_READY
    const myUid = Number(gameState.user.uid || 0)
    const result = applyReadyFlags(gameState.room.players as RelativePlayer[], data, myUid)
    gameState.room.players = result.players
    s.selfReady = result.selfReady
    this.syncSeatsFromPlayers(result.players)
    s.statusText = waitStatusText(
      result.players.length,
      s.canStart,
      s.selfReady,
      result.readyCount,
    )
    eventBus.emit(HzmjEvents.STATE, { phase: s.phase })
  }

  private syncSeatsFromPlayers(players: RelativePlayer[]): void {
    const s = hzmjState
    const seats = [0, 1, 2, 3].map((i) => {
      const prevTrusted = !!s.seats[i]?.isTrusted
      const p = players.find((x) => Number(x.absSeat) === i)
      if (!p || !(p.uid > 0)) {
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
          seatIndex: i,
          tileCount: s.seats[i]?.tileCount || 0,
          sex: 0,
        }
      }
      return {
        occupied: true,
        nickname: p.nickname || '',
        avatar: p.avatar || '',
        uid: Number(p.uid) || 0,
        gold: Number(p.gold ?? (p as { points?: number }).points) || 0,
        isReady: !!p.ready,
        isOffline: false,
        isTrusted: prevTrusted || (i === s.myIndex && s.trusted),
        isMaster: !!p.isOwner,
        seatIndex: i,
        tileCount: i === s.myIndex ? s.hand.length : s.seats[i]?.tileCount || 0,
        sex: 0,
      }
    })
    s.seats = seats
  }

  private syncSeatTileCounts(data: Record<string, unknown>): void {
    const s = hzmjState
    const list = Array.isArray(data.seats)
      ? (data.seats as Record<string, unknown>[])
      : Array.isArray(data.players)
        ? (data.players as Record<string, unknown>[])
        : []
    list.forEach((row) => {
      const abs = Number(row.seatNo ?? row.seat_no ?? row.seat)
      if (!Number.isFinite(abs) || abs < 0 || abs >= s.seats.length) return
      const count = Number(row.tileCount ?? row.cardsCount ?? row.handCount)
      if (!Number.isFinite(count)) return
      const seat = s.seats[abs]
      if (seat) seat.tileCount = count
    })
    if (s.seats[s.myIndex]) s.seats[s.myIndex].tileCount = s.hand.length
  }

  /** 并列最高分 / 全员不抢：先在候选人头像上转一圈，再按服务端抽中的座位发牌。 */
  private beginBankerRoll(data: Record<string, unknown>): boolean {
    const plan = bankerRollPlan(data)
    if (!plan) return false
    const s = hzmjState
    this.stopBankerRoll()
    const seq = ++this.rollSeq
    s.phase = PHASE.GRAB
    s.dealerIndex = -1
    s.dealing = false
    s.rollHighlightSeat = plan.seats[0]
    s.statusText = plan.system ? '无人抢庄，正在随机定庄' : '叫分相同，正在随机定庄'
    this.applyGrabs(data.grabs)
    let i = 0
    const steps = Math.max(plan.seats.length * 6, 16)
    this.rollTimer = window.setInterval(() => {
      if (seq !== this.rollSeq) return
      s.rollHighlightSeat = plan.seats[i % plan.seats.length]
      i += 1
      if (i < steps) return
      if (this.rollTimer) {
        window.clearInterval(this.rollTimer)
        this.rollTimer = null
      }
      s.rollHighlightSeat = plan.winner
      this.rollDoneTimer = window.setTimeout(() => {
        if (seq !== this.rollSeq) return
        this.onStartGame({ ...data, _bankerCommitted: true })
      }, 450)
    }, 90)
    return true
  }

  private stopBankerRoll(): void {
    this.rollSeq += 1
    if (this.rollTimer) {
      window.clearInterval(this.rollTimer)
      this.rollTimer = null
    }
    if (this.rollDoneTimer) {
      window.clearTimeout(this.rollDoneTimer)
      this.rollDoneTimer = null
    }
  }

  private applyGrabs(raw: unknown): void {
    const s = hzmjState
    if (!Array.isArray(raw) || !raw.length) return
    const map: Record<number, number> = { ...s.grabsBySeat }
    for (const row of raw) {
      if (!row || typeof row !== 'object') continue
      const r = row as Record<string, unknown>
      const seat = Number(r.index ?? r.seat ?? r.seat_no ?? r.seatNo)
      if (!Number.isFinite(seat) || seat < 0) continue
      const mulRaw = r.multiple ?? r.mul
      if (mulRaw == null) continue
      const mul = Number(mulRaw)
      if (!Number.isFinite(mul) || mul < 0) continue
      map[seat] = mul
      const pid = Number(r.playerId ?? r.player_id ?? 0)
      if (pid > 0 && pid === Number(gameState.user.uid)) {
        s.myGrabMultiple = mul
      }
    }
    s.grabsBySeat = map
    if (s.myGrabMultiple >= 0 && s.phase === PHASE.GRAB) {
      s.statusText = '等待其他玩家抢庄'
    }
  }

  private lockMyIndex(data: Record<string, unknown>, players: unknown[]): void {
    const s = hzmjState
    if (s.myIndexLocked) return
    if (gameState.room.myIndex != null) {
      s.myIndex = Number(gameState.room.myIndex)
      s.myIndexLocked = true
      return
    }
    if (data.myIndex != null && Number.isFinite(Number(data.myIndex))) {
      s.myIndex = Number(data.myIndex)
      s.myIndexLocked = true
      gameState.room.myIndex = s.myIndex
      return
    }
    if (data.mySeat != null && Number.isFinite(Number(data.mySeat))) {
      s.myIndex = Number(data.mySeat)
      s.myIndexLocked = true
      gameState.room.myIndex = s.myIndex
      return
    }
    const found = findSelfAbsIndex(players, Number(gameState.user.uid || 0))
    if (found != null) {
      s.myIndex = found
      s.myIndexLocked = true
      gameState.room.myIndex = s.myIndex
    }
  }
}

function isWait(phase: string): boolean {
  const p = String(phase || '').toLowerCase()
  return !p || p === PHASE.WAIT_READY || p === 'wait' || p === 'waitready'
}

/** 解析人数：勿 Number(seats数组)；3 人桌必须是 3，否则对家会错画到顶/边 */
function resolveHzmjPlayerNum(data: Record<string, unknown>, fallback = 4): number {
  const candidates: unknown[] = [
    data.playerNum,
    data.maxPlayers,
    data.num,
    data.player_num,
  ]
  // seats 若是数字才当人数；数组是座位快照
  if (typeof data.seats === 'number') candidates.push(data.seats)
  if (Array.isArray(data.players) && data.players.length >= 2) {
    candidates.push(data.players.length)
  }
  for (const raw of candidates) {
    const n = Number(raw)
    if (Number.isFinite(n) && n >= 2 && n <= 4) return Math.floor(n)
  }
  const fb = Number(fallback)
  return Number.isFinite(fb) && fb >= 2 && fb <= 4 ? Math.floor(fb) : 4
}

function playHzmjOpFromPush(data: Record<string, unknown>): void {
  const opName = String(data.action ?? data.opName ?? data.op ?? '').toLowerCase()
  const tile = Number(data.tile ?? data.discardTile ?? 0)
  const sex = Number(data.sex) || 0
  if (opName === 'discard' || opName === 'play' || Number(data.opId) === 24) {
    if (tile > 0) playHzmjDiscard(tile, sex)
    else playHzmjSfx('tileout')
    return
  }
  const map: Record<string, string> = {
    pong: 'pong',
    peng: 'pong',
    kong: 'kong',
    gang: 'kong',
    chow: 'chow',
    chi: 'chow',
    win: 'win',
    hu: 'win',
    draw_win: 'draw_win',
    kong_win: 'win',
    ting: 'ting',
    buhua: 'buhua',
    pass: '',
  }
  const key = map[opName]
  if (key) playHzmjOp(key, sex)
}

/** SHOW_OP → 按钮文案 */
export function showOpLabel(op: number): string {
  switch (Number(op)) {
    case SHOW_OP.PASS:
      return '过'
    case SHOW_OP.CHOW:
      return '吃'
    case SHOW_OP.PONG:
      return '碰'
    case SHOW_OP.KONG:
      return '杠'
    case SHOW_OP.WIN:
      return '胡'
    default:
      return `操作${op}`
  }
}

export { sortTiles }
