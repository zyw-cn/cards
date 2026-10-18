import type { ActionQueue } from '@/core/actionQueue'
import { eventBus } from '@/core/eventBus'
import { evaluateNiu, niuSfxKey, typeLabel, typeMultiple } from '@/logic/niu/rules/hand'
import { CARDS_PER_PLAYER, PHASE, TIMEOUT } from '@/logic/niu/rules/room'
import { CallBack } from '@/logic/shared/CallBack'
import { GameCallback } from '@/logic/shared/GameCallback'
import { occupiedPlayers, resolvePlayerId } from '@/logic/shared/roomSeats'
import { playNiuHandType, playNiuSfx } from '@/logic/niu/niuAudio'
import { pickAvatarRaw, resolveAvatarUrl } from '@/services/avatarUrl'
import { gameState } from '@/services/gameState'
import {
  applyNiuDismissVote,
  backs,
  clearNiuDismiss,
  maskSelfHand,
  niuState,
  type NiuPlayer,
} from '@/services/niuState'

export const NiuEvents = Object.freeze({
  STATE: 'niu:state',
  START: 'niu:start',
  GRAB: 'niu:grab',
  SHOW: 'niu:show',
  SETTLE: 'niu:settle',
  DISSOLVE: 'niu:dissolve',
})

type Ctx = {
  queue: ActionQueue
  onDissolved?: (data: Record<string, unknown>) => void
}

function num(v: unknown, fallback = 0): number {
  const n = Number(v)
  return Number.isFinite(n) ? n : fallback
}

function seatOf(raw: Record<string, unknown>, fallback: number): number {
  return num(raw.seatNo ?? raw.seat_no ?? raw.seat ?? raw.index, fallback)
}

function nickOf(raw: Record<string, unknown>): string {
  return String(raw.nickname ?? raw.nickName ?? raw.name ?? '')
}

function parseRule(data: Record<string, unknown>): Record<string, unknown> {
  const obj = data.ruleObj
  if (obj && typeof obj === 'object') return obj as Record<string, unknown>
  try {
    const parsed = JSON.parse(String(data.rule || '{}'))
    return parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : {}
  } catch {
    return {}
  }
}

/** 模式 key：ming | free | tongbi | niusz | fixed（对齐 game Room.detectModeKey） */
function detectModeKey(name: string): string {
  const n = String(name || '')
  if (n.includes('通比')) return 'tongbi'
  if (n.includes('自由')) return 'free'
  if (n.includes('固定')) return 'fixed'
  if (n.includes('上庄')) return 'niusz'
  return 'ming'
}

function realCards(raw: unknown): string[] {
  if (!Array.isArray(raw)) return []
  return raw.map((c) => String(c || '')).filter((c) => c && c !== 'back')
}

function hasDealtCards(): boolean {
  return niuState.players.some((p) => Array.isArray(p.cards) && p.cards.length > 0)
}

/**
 * 斗牛 WS 回调：只改 niuState / gameState，不碰画面节点。
 * 字段对齐 hyperf Niu StartGame / Grab / Bet / Show / ResumeBuilder。
 */
export class NiuCallback extends GameCallback {
  declare protected ctx: Ctx & Record<string, unknown>
  private grabSeq = 0

  constructor(ctx: Ctx) {
    super(ctx as unknown as Record<string, unknown>)
    this.register(CallBack.JOIN_ROOM, this.onJoinRoom)
    this.register(CallBack.ROOM_READY, this.onJoinRoom)
    this.register(CallBack.WAIT_START, this.onWaitStart)
    this.register(CallBack.START_GAME, this.onStartGame)
    this.register(CallBack.GRAB, this.onGrab)
    this.register(CallBack.BET, this.onBet)
    this.register(CallBack.SHOW, this.onShow)
    this.register(CallBack.ROOM_DISSOLVE, this.onRoomDissolve)
    this.register(CallBack.APPLY_DISMISS, this.onApplyDismiss)
    this.register(CallBack.VOTE_DISMISS, this.onVoteDismiss)
    this.register(CallBack.CANCEL_DISMISS, this.onCancelDismiss)
    this.register(CallBack.TRUST, this.onTrust)
    this.register(CallBack.UNTRUST, this.onUntrust)
  }

  onRoomDissolve(data: Record<string, unknown>): void {
    clearNiuDismiss()
    this.ctx.onDissolved?.(data)
    eventBus.emit(NiuEvents.DISSOLVE, data)
  }

  onApplyDismiss(data: Record<string, unknown>): void {
    applyNiuDismissVote(data)
  }

  onVoteDismiss(data: Record<string, unknown>): void {
    applyNiuDismissVote(data)
    if (data.dissolve) this.onRoomDissolve(data)
  }

  onCancelDismiss(_data: Record<string, unknown>): void {
    clearNiuDismiss()
  }

  onTrust(data: Record<string, unknown>): void {
    if (num(data.seatNo ?? data.seat) === niuState.myIndex || data.trusted === true) {
      niuState.trusted = true
    }
  }

  onUntrust(data: Record<string, unknown>): void {
    if (num(data.seatNo ?? data.seat) === niuState.myIndex || data.trusted === false) {
      niuState.trusted = false
    }
  }

  onJoinRoom(data: Record<string, unknown>): void {
    this.applySnapshot(data)
    if (data.inGame && data.resume && typeof data.resume === 'object') {
      this.applyResume(data.resume as Record<string, unknown>)
      return
    }
    const cb = String(data.callback || '').toLowerCase()
    const phase = String(data.phase || '').toLowerCase()
    if (cb === 'startgame' || phase === PHASE.GRAB || phase === PHASE.BET || phase === PHASE.SHOW) {
      this.onStartGame({ ...data, _fromJoin: true })
      return
    }
    // 竞态：startgame 广播已到，迟到的 joinroom 不得打回准备
    const cur = String(niuState.phase || '').toLowerCase()
    if (
      (niuState.inRound && (cur === PHASE.GRAB || cur === PHASE.BET || cur === PHASE.SHOW)) ||
      niuState.currentGrabId > 0 ||
      (hasDealtCards() && cur !== PHASE.WAIT && cur !== PHASE.SETTLE)
    ) {
      eventBus.emit(NiuEvents.STATE, data)
      return
    }
    niuState.phase = PHASE.WAIT
    niuState.inRound = false
    niuState.canStart = niuState.players.length >= Math.max(2, niuState.seats)
    niuState.statusText = niuState.canStart
      ? '人数已满，请准备'
      : `等待玩家进入（${niuState.players.length}/${niuState.seats}），超时将自动解散`
    niuState.countdown = niuState.canStart ? 0 : TIMEOUT.WAIT_START
    eventBus.emit(NiuEvents.STATE, data)
  }

  onWaitStart(data: Record<string, unknown>): void {
    const phase = String(niuState.phase || '').toLowerCase()
    // 下一局准备：结算后允许 waitstart；叫分/下注/亮牌中禁止打回
    if (
      niuState.inRound &&
      (phase === PHASE.GRAB || phase === PHASE.BET || phase === PHASE.SHOW)
    ) {
      return
    }
    if (niuState.currentGrabId > 0 && phase === PHASE.GRAB) return
    this.clearNiuTips()
    this.applyReady(data)
    niuState.phase = PHASE.WAIT
    niuState.inRound = false
    niuState.currentGrabId = 0
    niuState.statusText = niuState.selfReady ? '已准备，等待其他玩家' : '有玩家已准备，请尽快准备'
    niuState.countdown = TIMEOUT.WAIT_START
    eventBus.emit(NiuEvents.STATE, data)
  }

  onStartGame(data: Record<string, unknown>): void {
    this.clearNiuTips()
    if (!niuState.myUid) niuState.myUid = num(gameState.user.uid)
    niuState.selfReady = false
    niuState.starting = false
    niuState.canStart = true
    niuState.inRound = true
    niuState.bankerId = num(data.bankerPlayerId ?? data.bankerPid)
    niuState.bankBeishu = Math.max(1, num(data.grabMultiple, 1))
    niuState.currentGrabId = num(data.currentPlayerId ?? data.currentGrabPid)
    if (data.bankerMode != null) {
      const bm = String(data.bankerMode)
      if (bm === 'free' || bm === 'rotate' || bm === 'winner') niuState.modeKey = bm
    }
    niuState.selfDelta = 0
    niuState.myCards = []
    niuState.rollCandidates = []
    niuState.rollHighlightId = 0
    niuState.rollWinnerId = 0
    niuState.resultOpen = false
    niuState.players.forEach((p) => {
      p.ready = false
      p.cards = []
      p.shown = false
      p.looked = false
      p.qz = -1
      p.bet = -1
      p.niuType = 0
      p.niuLabel = ''
      p.niuMultiple = 0
      p.lastDelta = 0
      p.platformFee = 0
    })
    if (Array.isArray(data.players) && data.players.length) {
      this.mergePlayers(data.players, { resetHand: true })
    }
    // 本人 myCards：未看牌亮前 2 张，其余 3 张牌背。他人只有张数，全部牌背。
    const mine = realCards(data.myCards)
    if (mine.length) niuState.myCards = mine
    const layHands = (): void => {
      niuState.players.forEach((p) => {
        const count = Math.max(CARDS_PER_PLAYER, p.cards.length || CARDS_PER_PLAYER)
        if (p.id === niuState.myUid && mine.length && !p.shown && !p.looked) {
          p.cards = maskSelfHand(mine, { looked: false, shown: false })
          return
        }
        if (!p.cards.length || p.cards.every((c) => !c || c === 'back')) {
          p.cards = backs(count)
        }
      })
    }
    const instant = !!(data.resync || data._fromJoin || data._fromResume)
    if (!instant) {
      niuState.dealing = true
      layHands()
      this.ctx.queue.enqueue({ type: 'deal', payload: { count: CARDS_PER_PLAYER } })
      window.setTimeout(() => {
        niuState.dealing = false
      }, 700)
      playNiuSfx('deal')
    } else {
      niuState.dealing = false
      layHands()
    }
    const phase = String(data.phase || PHASE.GRAB).toLowerCase()
    this.enterPhase(phase || PHASE.GRAB, data)
    eventBus.emit(NiuEvents.START, data)
  }

  onGrab(data: Record<string, unknown>): void {
    this.applyGrabs(data.grabs)
    this.applyLastGrab(data.lastGrab)
    const phase = String(data.phase || PHASE.GRAB)
    if (phase !== PHASE.SHOW && phase !== 'settle') {
      niuState.currentGrabId = num(
        data.currentPlayerId ?? data.currentGrabPid ?? data.current_player_id,
      )
      this.enterPhase(PHASE.GRAB, data)
      eventBus.emit(NiuEvents.GRAB, data)
      return
    }
    const bankerId = num(data.bankerPlayerId)
    const bankBeishu = Math.max(1, num(data.grabMultiple, 1))
    const candidates = Array.isArray(data.candidates) ? data.candidates : []
    const candidateIds = candidates
      .map((c) => num((c as Record<string, unknown>).playerId ?? (c as Record<string, unknown>).player_id))
      .filter((id) => id > 0)
    const multiples = (Array.isArray(data.grabs) ? data.grabs : []).map((g) =>
      num((g as Record<string, unknown>).multiple),
    )
    const maxMul = multiples.length ? Math.max(...multiples) : -1
    const tie = multiples.length > 1 && (maxMul <= 0 || multiples.filter((m) => m === maxMul).length > 1)
    const needRandom = !!data.needRandom || candidateIds.length > 1 || tie || !!data.isSystemBanker
    niuState.bankBeishu = bankBeishu
    niuState.countdown = 0
    if (needRandom && bankerId > 0) {
      const ids = candidateIds.length
        ? candidateIds
        : maxMul > 0
          ? (data.grabs as Record<string, unknown>[])
              .filter((g) => num(g.multiple) === maxMul)
              .map((g) => num(g.playerId))
              .filter((id) => id > 0)
          : niuState.players.map((p) => p.id)
      niuState.rollCandidates = ids
      niuState.rollWinnerId = bankerId
      niuState.rollMode = data.isSystemBanker ? 'system' : 'tie'
      niuState.bankerId = 0
      niuState.statusText = data.isSystemBanker ? '无人抢庄，正在随机定庄' : '叫分相同，正在随机定庄'
      const seq = ++this.grabSeq
      this.ctx.queue.enqueue({
        type: 'banker-roll',
        payload: { bankerId, bankBeishu, seq },
      })
      window.setTimeout(() => {
        if (seq !== this.grabSeq) return
        this.commitBanker(bankerId, bankBeishu, data)
      }, 2800)
      eventBus.emit(NiuEvents.GRAB, data)
      return
    }
    this.commitBanker(bankerId, bankBeishu, data)
    eventBus.emit(NiuEvents.GRAB, data)
  }

  onBet(data: Record<string, unknown>): void {
    this.applyBets(data.bets)
    const phase = String(data.phase || PHASE.BET)
    if (phase === PHASE.SHOW) {
      const mine = realCards(data.myCards)
      niuState.myCards = mine
      niuState.players.forEach((p) => {
        if (p.id === niuState.myUid && mine.length) {
          p.looked = false
          p.shown = false
          p.cards = maskSelfHand(mine, { looked: false, shown: false })
        } else {
          p.cards = backs(CARDS_PER_PLAYER)
          p.shown = false
        }
      })
      if (data.bankerPlayerId) niuState.bankerId = num(data.bankerPlayerId)
      if (data.grabMultiple != null) niuState.bankBeishu = Math.max(1, num(data.grabMultiple, 1))
      this.enterPhase(PHASE.SHOW, data)
    } else {
      this.enterPhase(PHASE.BET, data)
    }
    eventBus.emit(NiuEvents.STATE, data)
  }

  onShow(data: Record<string, unknown>): void {
    if (String(data.phase || '') === 'settle' || Array.isArray(data.hands)) {
      this.applySettle(data)
      return
    }
    this.applyPartialShow(data)
  }

  private commitBanker(bankerId: number, bankBeishu: number, data: Record<string, unknown>): void {
    niuState.rollCandidates = []
    niuState.rollHighlightId = 0
    niuState.rollWinnerId = 0
    niuState.currentGrabId = 0
    niuState.bankerId = bankerId
    niuState.bankBeishu = bankBeishu
    playNiuSfx('dingzhuang')
    this.enterPhase(PHASE.SHOW, data)
  }

  private applyPartialShow(data: Record<string, unknown>): void {
    const hand = (data.hand && typeof data.hand === 'object' ? data.hand : {}) as Record<string, unknown>
    const pid = num(hand.playerId ?? data.showPlayerId)
    const cards = realCards(hand.cards)
    const shownIds = Array.isArray(data.shownIds) ? data.shownIds.map((id) => num(id)) : []
    for (const id of shownIds) {
      const p = niuState.players.find((x) => x.id === id)
      if (p) p.shown = true
    }
    const player = niuState.players.find((p) => p.id === pid)
    if (player && cards.length) {
      player.cards = cards
      player.shown = true
      if (pid === niuState.myUid) {
        player.looked = true
        niuState.myCards = cards
      }
      player.niuType = num(hand.niuType)
      player.niuLabel = String(hand.niuLabel || typeLabel(player.niuType))
      player.niuMultiple = Math.max(
        1,
        num(hand.niuMultiple, typeMultiple(player.niuType)),
      )
    }
    niuState.phase = PHASE.SHOW
    if (pid === niuState.myUid) {
      niuState.myNiuLabel = String(hand.niuLabel || typeLabel(num(hand.niuType)))
      niuState.statusText = data.auto ? '超时自动亮牌，等待其他玩家' : '已亮牌，等待其他玩家'
      playNiuSfx('open')
      window.setTimeout(() => playNiuHandType(niuSfxKey(num(hand.niuType))), 350)
    } else {
      const name = player?.nickname || '有玩家'
      niuState.statusText = data.auto ? `${name}超时自动亮牌` : `${name}已亮牌`
    }
    // 全桌共用开牌倒计时：有人开牌不重计；全员已开则隐藏闹钟
    const allShown =
      niuState.players.length > 0 && niuState.players.every((p) => p.shown)
    if (allShown) {
      niuState.countdown = 0
    }
    eventBus.emit(NiuEvents.SHOW, data)
  }

  private applySettle(data: Record<string, unknown>): void {
    const hands = Array.isArray(data.hands) ? data.hands : []
    const rewards = Array.isArray(data.rewards) ? data.rewards : []
    const rewardById = new Map(rewards.map((r) => [num((r as Record<string, unknown>).playerId), r as Record<string, unknown>]))
    const bankerFromHands = hands.find((h) => (h as Record<string, unknown>).isBanker)
    niuState.bankerId = num(
      (bankerFromHands as Record<string, unknown> | undefined)?.playerId ?? data.bankerPlayerId ?? niuState.bankerId,
    )
    for (const raw of hands) {
      const h = raw as Record<string, unknown>
      const pid = resolvePlayerId(h)
      const p = niuState.players.find((x) => x.id === pid)
      if (!p) continue
      const cards = realCards(h.cards)
      if (cards.length) p.cards = cards
      p.shown = true
      if (p.id === niuState.myUid) {
        p.looked = true
        niuState.myCards = cards
      }
      p.lastDelta = num(h.delta)
      p.niuType = num(h.niuType)
      p.niuLabel = String(h.niuLabel || typeLabel(p.niuType))
      p.niuMultiple = Math.max(1, num(h.niuMultiple, typeMultiple(p.niuType)))
      const reward = rewardById.get(p.id)
      p.platformFee =
        num(reward?.platformFee ?? reward?.roomFee ?? h.platformFee ?? h.roomFee) || 0
      const points = num(reward?.points ?? h.points, NaN)
      if (Number.isFinite(points)) p.gold = points
    }
    const mine = hands.find((h) => resolvePlayerId(h as Record<string, unknown>) === niuState.myUid) as
      | Record<string, unknown>
      | undefined
    niuState.selfDelta = num(mine?.delta)
    niuState.myNiuLabel = String(mine?.niuLabel || niuState.myNiuLabel)
    if (mine) {
      const me = niuState.players.find((p) => p.id === niuState.myUid)
      if (me && Number.isFinite(me.gold)) gameState.user.gold = me.gold
    }
    niuState.round = num(data.playedCount, niuState.round)
    niuState.roomOver = !!data.roomOver
    niuState.phase = PHASE.SETTLE
    niuState.selfReady = false
    niuState.resultOpen = false
    niuState.countdown = data.roomOver ? 0 : TIMEOUT.WAIT_START
    niuState.statusText = data.roomOver ? '房间已结束' : '本局结算 · 请准备下一局'
    playNiuSfx('open')
    window.setTimeout(() => playNiuHandType(niuSfxKey(num(mine?.niuType))), 350)
    window.setTimeout(() => {
      playNiuSfx(niuState.selfDelta > 0 ? 'win' : niuState.selfDelta < 0 ? 'lose' : 'over')
      // 飘分后再弹结算框
      niuState.resultOpen = true
    }, 900)
    eventBus.emit(NiuEvents.SETTLE, data)
  }

  private applyResume(resume: Record<string, unknown>): void {
    if (!niuState.myUid) niuState.myUid = num(gameState.user.uid)
    niuState.seats = Math.min(10, Math.max(2, num(resume.maxPlayers, niuState.seats)))
    niuState.round = num(resume.playedCount, niuState.round)
    niuState.bankerId = num(resume.bankerPlayerId)
    niuState.bankBeishu = Math.max(1, num(resume.grabMultiple, 1))
    niuState.currentGrabId = num(resume.currentPlayerId)
    niuState.selfReady = false
    niuState.canStart = true
    niuState.inRound = true
    if (Array.isArray(resume.seats)) {
      for (const raw of resume.seats) {
        const s = raw as Record<string, unknown>
        const p = niuState.players.find((x) => x.id === num(s.playerId ?? s.player_id))
        if (!p) continue
        p.shown = !!s.shown
        const cards = realCards(s.cards)
        const count = num(s.cardsCount, CARDS_PER_PLAYER)
        p.cards = cards.length ? cards : backs(count || CARDS_PER_PLAYER)
      }
    }
    const myReal = realCards(resume.myCards)
    const me = niuState.players.find((p) => p.id === niuState.myUid)
    if (me) {
      if (myReal.length) {
        niuState.myCards = myReal
        me.looked = !!me.shown
        me.cards = maskSelfHand(myReal, { looked: me.looked, shown: me.shown })
      } else {
        const n = Array.isArray(resume.myCards) ? resume.myCards.length : 0
        me.cards = backs(n || CARDS_PER_PLAYER)
        niuState.myCards = []
        me.looked = false
      }
    }
    this.applyGrabs(resume.grabs)
    this.applyBets(resume.bets)
    niuState.dealing = false
    this.enterPhase(String(resume.phase || PHASE.GRAB), resume)
  }

  private enterPhase(phase: string, data: Record<string, unknown>): void {
    const p = phase.toLowerCase()
    if (p === PHASE.GRAB) {
      const wasGrab = niuState.phase === PHASE.GRAB
      niuState.phase = PHASE.GRAB
      niuState.inRound = true
      niuState.currentGrabId = num(
        data.currentPlayerId ?? data.currentGrabPid ?? data.current_player_id,
        niuState.currentGrabId,
      )
      if (data.bankerMode != null && String(data.bankerMode) === 'free') {
        niuState.modeKey = 'free'
        niuState.currentGrabId = 0
      }
      const free = niuState.modeKey === 'free'
      const current = niuState.players.find((x) => x.id === niuState.currentGrabId)
      niuState.statusText = free
        ? '请抢庄'
        : niuState.currentGrabId > 0 && niuState.currentGrabId === niuState.myUid
          ? '轮到您叫分'
          : `等待${current?.nickname || '其他玩家'}叫分`
      // 自由：共用一段倒计时，他人叫分不重计；轮流：换人重计 10s
      if (!wasGrab || !free) niuState.countdown = TIMEOUT.GRAB
      niuState.players.forEach((pl) => {
        if (!pl.cards.length) pl.cards = backs(CARDS_PER_PLAYER)
      })
      return
    }
    if (p === PHASE.BET) {
      niuState.phase = PHASE.BET
      niuState.inRound = true
      const me = niuState.players.find((x) => x.id === niuState.myUid)
      const isBanker = niuState.bankerId > 0 && niuState.bankerId === niuState.myUid
      niuState.statusText = isBanker
        ? '等待闲家下注'
        : me && me.bet >= 0
          ? '已下注，等待其他玩家'
          : '请选择下注倍数'
      niuState.countdown = TIMEOUT.BET
      return
    }
    if (p === PHASE.SHOW || p === 'fanpai') {
      const keepClock = niuState.phase === PHASE.SHOW && niuState.countdown > 0
      niuState.phase = PHASE.SHOW
      niuState.inRound = true
      niuState.currentGrabId = 0
      niuState.players.forEach((pl) => {
        if (!pl.cards.length) pl.cards = backs(CARDS_PER_PLAYER)
      })
      const me = niuState.players.find((x) => x.id === niuState.myUid)
      const banker = niuState.players.find((x) => x.id === niuState.bankerId)
      niuState.statusText = me?.shown
        ? '已亮牌，等待其他玩家'
        : me?.looked
          ? '请亮牌'
          : `庄家：${banker?.nickname || '已确定'}，请看牌或亮牌`
      // 进入亮牌阶段才起 10s 共用闹钟；已在亮牌中不重计
      if (!keepClock) niuState.countdown = TIMEOUT.SHOW
      return
    }
    if (p === PHASE.SETTLE) {
      niuState.phase = PHASE.SETTLE
      niuState.inRound = true
      return
    }
    niuState.phase = PHASE.WAIT
  }

  private applyGrabs(raw: unknown): void {
    if (!Array.isArray(raw)) return
    for (const item of raw) {
      const g = item as Record<string, unknown>
      const p = niuState.players.find((x) => x.id === num(g.playerId ?? g.player_id))
      if (p) p.qz = num(g.multiple, p.qz)
    }
  }

  /** 单次抢庄进度：补写刚叫分的人（防 grabs 漏项） */
  private applyLastGrab(raw: unknown): void {
    if (!raw || typeof raw !== 'object') return
    const g = raw as Record<string, unknown>
    const seat = num(g.index ?? g.seat ?? g.seat_no, -1)
    const mul = num(g.multiple, -1)
    if (mul < 0) return
    const pid = num(g.playerId ?? g.player_id)
    const p =
      (pid > 0 ? niuState.players.find((x) => x.id === pid) : undefined) ||
      (seat >= 0 ? niuState.players.find((x) => x.seat === seat) : undefined)
    if (p) p.qz = mul
  }

  private applyBets(raw: unknown): void {
    if (!Array.isArray(raw)) return
    for (const item of raw) {
      const b = item as Record<string, unknown>
      const p = niuState.players.find((x) => x.id === num(b.playerId ?? b.player_id))
      if (p) p.bet = num(b.multiple, p.bet)
    }
  }

  private applySnapshot(data: Record<string, unknown>): void {
    const rules = parseRule(data)
    niuState.myUid = num(gameState.user.uid)
    const myIndex = num(data.myIndex ?? data.mySeat ?? gameState.room.myIndex, niuState.myIndex)
    niuState.myIndex = myIndex
    niuState.seats = Math.min(10, Math.max(2, num(data.num ?? data.maxPlayers ?? rules.seats, niuState.seats)))
    niuState.df = num(rules.bottom_points ?? rules.df, niuState.df || 1) || 1
    niuState.modeName = String(rules.mode_name || niuState.modeName || '')
    niuState.modeKey = String(rules.mode_key || detectModeKey(niuState.modeName) || niuState.modeKey || 'free')
    niuState.round = num(data.playedCount ?? data.js, niuState.round)
    niuState.totalRound = num(data.totalPlayCount ?? data.totalCount ?? rules.zjs, niuState.totalRound)
    if (Array.isArray(data.players) || Array.isArray(data.publicMembers) || Array.isArray(data.members)) {
      this.mergePlayers(occupiedPlayers(data))
    }
    this.applyReady(data)
    niuState.canStart = niuState.players.length >= Math.max(2, niuState.seats)
    if (!niuState.canStart) {
      niuState.players.forEach((p) => {
        p.ready = false
      })
      niuState.selfReady = false
    }
  }

  private clearNiuTips(): void {
    niuState.myNiuLabel = ''
    niuState.myCards = []
    niuState.players.forEach((p) => {
      p.shown = false
      p.looked = false
      p.niuType = 0
      p.niuLabel = ''
      p.niuMultiple = 0
      p.lastDelta = 0
    })
  }

  private applyReady(data: Record<string, unknown>, assumeSelf = false): void {
    const rawIds = data.readyIds ?? data.ready_ids
    if (Array.isArray(rawIds)) {
      const ready = new Set(rawIds.map((id) => num(id)))
      niuState.players.forEach((p) => {
        p.ready = ready.has(p.id)
      })
    } else if (assumeSelf) {
      const me = niuState.players.find((p) => p.id === niuState.myUid)
      if (me) me.ready = true
    }
    niuState.selfReady = !!niuState.players.find((p) => p.id === niuState.myUid)?.ready
    if (niuState.selfReady) niuState.resultOpen = false
  }

  private mergePlayers(rawList: unknown[], opts?: { resetHand?: boolean }): void {
    const resetHand = !!opts?.resetHand
    const prev = new Map(niuState.players.map((p) => [p.id, p]))
    const next: NiuPlayer[] = []
    rawList.forEach((item, i) => {
      if (!item || typeof item !== 'object') return
      const raw = item as Record<string, unknown>
      const id = resolvePlayerId(raw)
      if (id <= 0) return
      const old = prev.get(id)
      const seat = seatOf(raw, old?.seat ?? i)
      next.push({
        id,
        seat,
        nickname: nickOf(raw) || old?.nickname || `玩家${seat + 1}`,
        avatar: resolveAvatarUrl(pickAvatarRaw(raw, old?.avatar), old?.avatar || '', id),
        gold: num(raw.gold ?? raw.points, old?.gold ?? 0),
        ready: resetHand
          ? false
          : raw.isReady != null || raw.ready != null
            ? !!(raw.isReady ?? raw.ready)
            : !!old?.ready,
        cards: resetHand ? [] : (old?.cards ?? []),
        shown: resetHand ? false : raw.shown != null ? !!raw.shown : !!old?.shown,
        looked: resetHand ? false : !!old?.looked,
        qz: resetHand ? -1 : (old?.qz ?? -1),
        bet: resetHand ? -1 : (old?.bet ?? -1),
        niuType: resetHand ? 0 : (old?.niuType ?? 0),
        niuLabel: resetHand ? '' : (old?.niuLabel ?? ''),
        niuMultiple: resetHand ? 0 : (old?.niuMultiple ?? 0),
        lastDelta: resetHand ? 0 : (old?.lastDelta ?? 0),
        platformFee: resetHand ? 0 : (old?.platformFee ?? 0),
        isMaster: !!(raw.isOwner ?? raw.is_owner ?? raw.isMaster ?? raw.is_master ?? old?.isMaster),
      })
    })
    next.sort((a, b) => a.seat - b.seat)
    niuState.players = next
    const me = next.find((p) => p.id === niuState.myUid)
    if (me) niuState.myIndex = me.seat
  }

  /** 开牌前本地预览（服务端结算仍以 Show 回包为准） */
  previewMine(): string {
    const me = niuState.players.find((p) => p.id === niuState.myUid)
    if (!me) return ''
    const cards = realCards(me.cards)
    if (cards.length < 5) return ''
    const ev = evaluateNiu(cards)
    niuState.myNiuLabel = ev.label
    me.niuType = ev.type
    me.niuLabel = ev.label
    me.niuMultiple = ev.multiple
    return ev.label
  }
}
