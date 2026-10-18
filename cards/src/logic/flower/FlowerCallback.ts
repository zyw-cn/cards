import type { ActionQueue } from '@/core/actionQueue'
import { eventBus } from '@/core/eventBus'
import { evaluateFlower } from '@/logic/flower/rules/hand'
import {
  callAmountFor,
  CARDS_PER_PLAYER,
  menMaxFromFollow,
  normAmount,
  PHASE,
  TIMEOUT,
} from '@/logic/flower/rules/room'
import { CallBack } from '@/logic/shared/CallBack'
import { GameCallback } from '@/logic/shared/GameCallback'
import { occupiedPlayers, resolvePlayerId } from '@/logic/shared/roomSeats'
import { playFlowerSfx } from '@/logic/flower/flowerAudio'
import { pickAvatarRaw, resolveAvatarUrl } from '@/services/avatarUrl'
import {
  applyFlowerDismissVote,
  backs,
  clearFlowerDismiss,
  flashFlowerTip,
  flowerState,
  setFlowerCountdown,
  type FlowerPlayer,
} from '@/services/flowerState'
import { gameState, syncRoundProgress } from '@/services/gameState'

export const FlowerEvents = Object.freeze({
  STATE: 'flower:state',
  START: 'flower:start',
  ACTION: 'flower:action',
  SETTLE: 'flower:settle',
  DISSOLVE: 'flower:dissolve',
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

function realCards(raw: unknown): string[] {
  if (!Array.isArray(raw)) return []
  return raw.map((c) => String(c || '')).filter((c) => c && c !== 'back')
}

/**
 * 炸金花 WS 回调：只改 flowerState。比牌结果以服务端为准。
 */
export class FlowerCallback extends GameCallback {
  declare protected ctx: Ctx & Record<string, unknown>
  private startedRound = 0

  constructor(ctx: Ctx) {
    super(ctx as unknown as Record<string, unknown>)
    this.register(CallBack.JOIN_ROOM, this.onJoinRoom)
    this.register(CallBack.ROOM_READY, this.onJoinRoom)
    this.register(CallBack.WAIT_START, this.onWaitStart)
    this.register(CallBack.START_GAME, this.onStartGame)
    this.register(CallBack.LOOK, this.onLook)
    this.register([CallBack.CALL, CallBack.RAISE, CallBack.FOLD, CallBack.COMPARE], this.onAction)
    this.register(CallBack.SETTLE, this.onSettle)
    this.register(CallBack.ROOM_DISSOLVE, this.onRoomDissolve)
    this.register(CallBack.APPLY_DISMISS, this.onApplyDismiss)
    this.register(CallBack.VOTE_DISMISS, this.onVoteDismiss)
    this.register(CallBack.CANCEL_DISMISS, this.onCancelDismiss)
    this.register(CallBack.TRUST, this.onTrust)
    this.register(CallBack.UNTRUST, this.onUntrust)
  }

  onRoomDissolve(data: Record<string, unknown>): void {
    clearFlowerDismiss()
    this.ctx.onDissolved?.(data)
    eventBus.emit(FlowerEvents.DISSOLVE, data)
  }

  onApplyDismiss(data: Record<string, unknown>): void {
    applyFlowerDismissVote(data)
  }

  onVoteDismiss(data: Record<string, unknown>): void {
    applyFlowerDismissVote(data)
    if (data.dissolve) this.onRoomDissolve(data)
  }

  onCancelDismiss(_data: Record<string, unknown>): void {
    clearFlowerDismiss()
  }

  onTrust(data: Record<string, unknown>): void {
    if (num(data.seatNo ?? data.seat) === flowerState.myIndex || data.trusted === true) {
      flowerState.trusted = true
    }
  }

  onUntrust(data: Record<string, unknown>): void {
    if (num(data.seatNo ?? data.seat) === flowerState.myIndex || data.trusted === false) {
      flowerState.trusted = false
    }
  }

  onJoinRoom(data: Record<string, unknown>): void {
    this.applySnapshot(data)
    if (data.inGame && data.resume && typeof data.resume === 'object') {
      syncRoundProgress(data.resume as Record<string, unknown>, 'resume')
      this.mirrorRound()
      this.applyResume(data.resume as Record<string, unknown>)
      return
    }
    const cb = String(data.callback || '').toLowerCase()
    if (cb === 'startgame' || data.phase === PHASE.BET) {
      syncRoundProgress({ ...data, inGame: true }, 'join')
      this.mirrorRound()
      this.onStartGame({ ...data, _fromJoin: true })
      return
    }
    syncRoundProgress(data, 'join')
    this.mirrorRound()
    flowerState.phase = PHASE.WAIT
    flowerState.canStart = flowerState.players.length >= Math.max(2, flowerState.seats)
    flowerState.statusText = this.seatFraction()
    setFlowerCountdown(0)
    eventBus.emit(FlowerEvents.STATE, data)
  }

  onWaitStart(data: Record<string, unknown>): void {
    flowerState.myPattern = ''
    flowerState.selfDelta = 0
    flowerState.players.forEach((p) => {
      p.pattern = ''
      p.lastDelta = 0
    })
    this.applyReady(data)
    flowerState.phase = PHASE.WAIT
    flowerState.statusText = this.seatFraction()
    setFlowerCountdown(0)
    eventBus.emit(FlowerEvents.STATE, data)
  }

  onStartGame(data: Record<string, unknown>): void {
    const roundId = num(data.roundId)
    const replay = !!(data._fromJoin || data.resync)
    if (roundId > 0 && roundId === this.startedRound && !replay) return
    if (roundId > 0) this.startedRound = roundId
    if (!replay) {
      syncRoundProgress(data, 'start')
      this.mirrorRound()
    }
    flowerState.selfReady = false
    flowerState.compareOpen = false
    flowerState.resultOpen = false
    flowerState.pkShow = false
    flowerState.selfDelta = 0
    flowerState.noCompare = false
    flowerState.myPattern = ''
    flowerState.myCards = []
    flowerState.players.forEach((p) => {
      p.ready = false
      p.looked = false
      p.folded = false
      p.lost = false
      p.win = false
      p.lastDelta = 0
      p.anteAmt = 0
      p.menAmt = 0
      p.followAmt = 0
      p.pkAmt = 0
      p.bill = ''
      p.pattern = ''
      p.cards = backs(CARDS_PER_PLAYER)
    })
    if (Array.isArray(data.players) && data.players.length) this.mergePlayers(data.players, true)
    this.applyTable(data)
    const instant = !!(data.resync || data._fromJoin)
    // 先停掉上一局结算留下的倒计时，避免和本局第一手叠在一起
    setFlowerCountdown(0)
    flowerState.dealing = !instant
    flowerState.phase = PHASE.BET
    if (!instant) {
      this.ctx.queue.enqueue({ type: 'deal', payload: { count: CARDS_PER_PLAYER } })
      const wait = this.dealWaitMs()
      window.setTimeout(() => {
        if (String(flowerState.phase).toLowerCase() !== PHASE.BET) return
        flowerState.dealing = false
        this.syncTurn()
      }, wait)
      playFlowerSfx('deal')
      playFlowerSfx('start')
    } else {
      flowerState.dealing = false
      this.syncTurn()
    }
    eventBus.emit(FlowerEvents.START, data)
  }

  onLook(data: Record<string, unknown>): void {
    this.applyTable(data)
    const lookSeat = num(data.lookIndex ?? data.lookSeat, -1)
    const looker = flowerState.players.find((p) => p.seat === lookSeat)
    if (looker) looker.looked = true
    const cards = realCards(data.myCards)
    if (cards.length) {
      flowerState.myCards = cards
      const me = flowerState.players.find((p) => p.id === flowerState.myUid)
      if (me) {
        me.looked = true
        me.cards = cards
      }
      try {
        flowerState.myPattern = evaluateFlower(cards).label
      } catch {
        flowerState.myPattern = '已看牌'
      }
    }
    playFlowerSfx('look')
    this.syncTurn()
    eventBus.emit(FlowerEvents.ACTION, data)
  }

  onAction(data: Record<string, unknown>): void {
    if (String(data.phase || data.callback || '').toLowerCase() === 'settle' || Array.isArray(data.hands)) {
      this.onSettle(data)
      return
    }
    this.applyTable(data)
    const last = (data.lastAction && typeof data.lastAction === 'object' ? data.lastAction : {}) as Record<string, unknown>
    this.applyActorPoints(last)
    const action = String(last.action || data.callback || '').toLowerCase()
    if (action === 'compare') {
      const loserSeat = num(last.loser, -1)
      const loser = flowerState.players.find((p) => p.seat === loserSeat)
      if (loser) {
        loser.folded = true
        loser.lost = true
      }
      const fromSeat = num(last.index ?? last.seat ?? last.fromSeat, -1)
      const toSeat = num(last.targetSeat ?? last.toSeat ?? last.target, -1)
      const from =
        flowerState.players.find((p) => p.seat === fromSeat) ||
        flowerState.players.find((p) => p.id === num(last.playerId))
      const to =
        flowerState.players.find((p) => p.seat === toSeat) ||
        flowerState.players.find((p) => p.id === num(last.targetPlayerId ?? last.targetId)) ||
        flowerState.players.find((p) => p.id !== from?.id && !p.lost && p.seat === loserSeat) ||
        flowerState.players.find((p) => p.id === (loser?.id === from?.id ? num(last.winnerId) : loser?.id))
      // 发起方 vs 被比方；若字段不全则用败者与存活对手兜底
      const left = from || flowerState.players.find((p) => p.id === flowerState.myUid)
      let right = to || undefined
      if (!right && loser && left) {
        right =
          loser.id === left.id
            ? flowerState.players.find((p) => p.id !== left.id && !p.folded)
            : loser
      }
      if (left && right) {
        flowerState.pkLeft = { id: left.id, nickname: left.nickname, avatar: left.avatar }
        flowerState.pkRight = { id: right.id, nickname: right.nickname, avatar: right.avatar }
        flowerState.pkLoserId = loser?.id ?? 0
        flowerState.pkDone = false
        flowerState.pkShow = true
        window.setTimeout(() => {
          flowerState.pkDone = true
        }, 600)
        window.setTimeout(() => {
          flowerState.pkShow = false
        }, 1600)
      }
      playFlowerSfx('compare')
    } else if (action === 'fold') {
      const foldSeat = num(last.index ?? last.seat, -1)
      const folder =
        flowerState.players.find((p) => p.seat === foldSeat) ||
        flowerState.players.find((p) => p.id === num(last.playerId))
      if (folder) folder.folded = true
      if (last.auto) {
        flashFlowerTip(folder?.id === flowerState.myUid ? '超时未操作，已自动弃牌' : `${folder?.nickname || '玩家'} 超时弃牌`)
      }
      playFlowerSfx('fold')
    } else if (action === 'call' || action === 'raise') {
      const amt = num(last.amount)
      const key = amt > 0 ? `bet${amt}` : ''
      if (key) playFlowerSfx(key)
      else playFlowerSfx(flowerMeLooked() ? 'look' : 'men')
    }
    flowerState.compareOpen = false
    this.syncTurn()
    eventBus.emit(FlowerEvents.ACTION, data)
  }

  onSettle(data: Record<string, unknown>): void {
    flowerState.phase = PHASE.SETTLE
    flowerState.compareOpen = false
    flowerState.pkShow = false
    flowerState.resultOpen = false
    setFlowerCountdown(0)
    flowerState.selfReady = false
    syncRoundProgress(data, 'settle')
    this.mirrorRound()
    flowerState.roomOver = !!data.roomOver
    flowerState.noCompare = !!data.noCompare
    if (data.pot != null) flowerState.pot = num(data.pot)
    if (data.winnerPid) flowerState.dealerId = num(data.winnerPid)
    const hands = Array.isArray(data.hands) ? data.hands : []
    const rewards = Array.isArray(data.rewards) ? data.rewards : []
    const rewardMap = new Map(rewards.map((r) => [num((r as Record<string, unknown>).playerId), r as Record<string, unknown>]))
    flowerState.players.forEach((p) => {
      p.ready = false
    })
    for (const raw of hands) {
      const h = raw as Record<string, unknown>
      const pid = num(h.playerId ?? h.player_id)
      const p = flowerState.players.find((x) => x.id === pid) || flowerState.players.find((x) => x.seat === num(h.seat ?? h.seatNo))
      if (!p) continue
      const cards = realCards(h.cards)
      if (cards.length) p.cards = cards
      p.lastDelta = num(h.delta)
      p.anteAmt = num(h.ante)
      p.menAmt = num(h.men)
      p.followAmt = num(h.follow)
      p.pkAmt = num(h.pk)
      p.bill = String(h.bill || '')
      p.folded = !!h.folded
      p.looked = !!h.looked
      p.win = !!h.win
      p.lost = !h.win && !!h.folded
      p.pattern = String(h.label || '')
      p.invested = num(h.invested, p.invested)
      const reward = rewardMap.get(p.id)
      const points = num(reward?.points ?? h.points, NaN)
      if (Number.isFinite(points)) p.gold = points
      if (p.id === flowerState.myUid) {
        flowerState.selfDelta = p.lastDelta
        flowerState.myPattern = p.pattern || flowerState.myPattern
        if (cards.length) flowerState.myCards = cards
        gameState.user.gold = p.gold
      }
    }
    const delta = flowerState.selfDelta
    flowerState.statusText = flowerState.roomOver
      ? '房间已结束'
      : flowerState.noCompare
        ? '本局未比牌，金币已退回'
        : delta > 0
          ? `本局赢 ${delta}（底池归您）`
          : `本局结算 ${delta}`
    playFlowerSfx(delta > 0 ? 'win' : delta < 0 ? 'lose' : 'over')
    window.setTimeout(() => {
      if (String(flowerState.phase).toLowerCase() !== PHASE.SETTLE || flowerState.selfReady) return
      flowerState.resultOpen = true
    }, 900)
    eventBus.emit(FlowerEvents.SETTLE, data)
  }

  private applyResume(resume: Record<string, unknown>): void {
    this.applyTable(resume)
    const cards = realCards(resume.myCards)
    if (cards.length) {
      flowerState.myCards = cards
      const me = flowerState.players.find((p) => p.id === flowerState.myUid)
      if (me) {
        me.looked = true
        me.cards = cards
      }
      flowerState.myPattern = evaluateFlower(cards).label
    }
    flowerState.dealing = false
    flowerState.phase = PHASE.BET
    this.syncTurn()
  }

  private applyTable(data: Record<string, unknown>): void {
    if (data.pot != null) flowerState.pot = num(data.pot)
    if (data.unit != null) flowerState.unit = normAmount(data.unit)
    if (data.ante != null) {
      flowerState.ante = num(data.ante)
      flowerState.df = flowerState.ante || flowerState.df
    }
    if (data.turnSeat != null) flowerState.turnSeat = num(data.turnSeat)
    if (Array.isArray(data.selectPoints) && data.selectPoints.length) {
      flowerState.selectPoints = data.selectPoints.map((p) => normAmount(p)).filter((p) => p > 0)
    }
    if (data.lookMax != null || data.maxFollow != null) {
      const look = normAmount(data.lookMax ?? data.maxFollow)
      if (look > 0) flowerState.lookMax = look
    }
    if (data.menMax != null) {
      const men = normAmount(data.menMax)
      if (men > 0) flowerState.menMax = men
    } else if (flowerState.lookMax) {
      flowerState.menMax = menMaxFromFollow(flowerState.lookMax, flowerState.selectPoints)
    }
    if (data.dealerPlayerId != null) flowerState.dealerId = num(data.dealerPlayerId)
    if (data.dealerSeat != null) flowerState.dealerSeat = num(data.dealerSeat)
    if (!flowerState.dealerId && flowerState.dealerSeat >= 0) {
      const dealer = flowerState.players.find((p) => p.seat === flowerState.dealerSeat)
      if (dealer) flowerState.dealerId = dealer.id
    }
    this.applySeatFlags(data.seats)
  }

  private applySeatFlags(raw: unknown): void {
    if (!Array.isArray(raw)) return
    for (const item of raw) {
      const s = item as Record<string, unknown>
      const pid = num(s.playerId ?? s.player_id)
      const p = flowerState.players.find((x) => x.id === pid) || flowerState.players.find((x) => x.seat === num(s.seatNo ?? s.seat))
      if (!p) continue
      if (s.looked != null) p.looked = !!s.looked
      if (s.folded != null) {
        p.folded = !!s.folded
        if (p.folded) p.lost = false
      }
      if (s.invested != null) p.invested = num(s.invested)
    }
  }

  private applyActorPoints(last: Record<string, unknown>): void {
    const amount = num(last.amount)
    const points = last.points != null ? num(last.points, NaN) : NaN
    let actor = last.playerId ? flowerState.players.find((p) => p.id === num(last.playerId)) : undefined
    if (!actor && last.index != null) actor = flowerState.players.find((p) => p.seat === num(last.index))
    if (!actor) return
    if (Number.isFinite(points)) actor.gold = points
    else if (amount > 0) actor.gold = Math.max(0, num(actor.gold) - amount)
    if (actor.id === flowerState.myUid) gameState.user.gold = actor.gold
  }

  /** 把全局局数写回本桌，顶部显示第几局/总局数 */
  private mirrorRound(): void {
    const js = Number(gameState.room.js)
    const zjs = Number(gameState.room.zjs)
    if (js > 0) flowerState.round = js
    if (zjs > 0) flowerState.totalRound = zjs
    else if (flowerState.totalRound > 0) gameState.room.zjs = flowerState.totalRound
  }

  /** 发牌飞牌结束再开表，避免第一手把发牌时间算进去 */
  private dealWaitMs(): number {
    const n = Math.max(1, flowerState.players.length) * CARDS_PER_PLAYER
    return Math.max(700, Math.round(n * 55 + 400))
  }

  /** 在座人数/座位数，如 2/6 */
  private seatFraction(): string {
    const total = Math.max(2, flowerState.seats || 2)
    return `${flowerState.players.length}/${total}`
  }

  private syncTurn(): void {
    if (flowerState.phase !== PHASE.BET || flowerState.dealing) return
    const me = flowerState.players.find((p) => p.id === flowerState.myUid)
    const mine = me && me.seat === flowerState.turnSeat && !me.folded
    const call = callAmountFor(!!me?.looked, flowerState.unit, flowerState.selectPoints)
    if (mine) {
      flowerState.statusText = `轮到您操作 · ${me?.looked ? '看跟' : '闷跟'} ${call}（${TIMEOUT.TURN}秒）`
    } else {
      const turn = flowerState.players.find((p) => p.seat === flowerState.turnSeat)
      flowerState.statusText = `轮到 ${turn?.nickname || '其他玩家'} 操作（${TIMEOUT.TURN}秒）`
    }
    setFlowerCountdown(TIMEOUT.TURN)
  }

  private applySnapshot(data: Record<string, unknown>): void {
    const rules = parseRule(data)
    flowerState.myUid = num(gameState.user.uid)
    flowerState.myIndex = num(data.myIndex ?? data.mySeat ?? gameState.room.myIndex, flowerState.myIndex)
    flowerState.seats = Math.min(10, Math.max(2, num(data.num ?? data.maxPlayers ?? rules.seats, flowerState.seats)))
    flowerState.df = num(rules.bottom_points ?? rules.df ?? rules.ante, flowerState.df || 1) || 1
    flowerState.round = num(data.playedCount ?? data.js, flowerState.round)
    flowerState.totalRound = num(data.totalPlayCount ?? data.totalCount ?? rules.zjs, flowerState.totalRound)
    const look = normAmount(rules.look_max ?? rules.max_follow ?? rules.maxFollow)
    if (look > 0) flowerState.lookMax = look
    const men = normAmount(rules.men_max)
    flowerState.menMax = men > 0 ? men : menMaxFromFollow(flowerState.lookMax, flowerState.selectPoints)
    if (Array.isArray(data.players) || Array.isArray(data.publicMembers) || Array.isArray(data.members)) {
      this.mergePlayers(occupiedPlayers(data), false)
    }
    this.applyReady(data)
    flowerState.canStart = flowerState.players.length >= Math.max(2, flowerState.seats)
    if (!flowerState.canStart) {
      flowerState.players.forEach((p) => {
        p.ready = false
      })
      flowerState.selfReady = false
    }
  }

  private applyReady(data: Record<string, unknown>, assumeSelf = false): void {
    const rawIds = data.readyIds ?? data.ready_ids
    if (Array.isArray(rawIds)) {
      const ready = new Set(rawIds.map((id) => num(id)))
      flowerState.players.forEach((p) => {
        p.ready = ready.has(p.id)
      })
    } else if (assumeSelf) {
      const me = flowerState.players.find((p) => p.id === flowerState.myUid)
      if (me) me.ready = true
    }
    flowerState.selfReady = !!flowerState.players.find((p) => p.id === flowerState.myUid)?.ready
  }

  private mergePlayers(rawList: unknown[], keepCards: boolean): void {
    const prev = new Map(flowerState.players.map((p) => [p.id, p]))
    const next: FlowerPlayer[] = []
    rawList.forEach((item, i) => {
      if (!item || typeof item !== 'object') return
      const raw = item as Record<string, unknown>
      const id = resolvePlayerId(raw)
      if (id <= 0) return
      const old = prev.get(id)
      const seat = seatOf(raw, old?.seat ?? i)
      const count = num(raw.cardsCount, CARDS_PER_PLAYER)
      next.push({
        id,
        seat,
        nickname: nickOf(raw) || old?.nickname || `玩家${seat + 1}`,
        avatar: resolveAvatarUrl(pickAvatarRaw(raw, old?.avatar), old?.avatar || '', id),
        gold: num(raw.gold ?? raw.points, old?.gold ?? 0),
        ready: raw.isReady != null || raw.ready != null ? !!(raw.isReady ?? raw.ready) : !!old?.ready,
        cards: keepCards ? backs(count || CARDS_PER_PLAYER) : (old?.cards ?? []),
        looked: raw.looked != null ? !!raw.looked : !!old?.looked,
        folded: raw.folded != null ? !!raw.folded : !!old?.folded,
        lost: old?.lost ?? false,
        invested: num(raw.invested, old?.invested ?? 0),
        lastDelta: old?.lastDelta ?? 0,
        anteAmt: old?.anteAmt ?? 0,
        menAmt: old?.menAmt ?? 0,
        followAmt: old?.followAmt ?? 0,
        pkAmt: old?.pkAmt ?? 0,
        bill: old?.bill ?? '',
        pattern: old?.pattern ?? '',
        isMaster: !!(raw.isOwner ?? raw.is_owner ?? raw.isMaster ?? raw.is_master ?? old?.isMaster),
        win: old?.win ?? false,
      })
    })
    next.sort((a, b) => a.seat - b.seat)
    flowerState.players = next
    const me = next.find((p) => p.id === flowerState.myUid)
    if (me) flowerState.myIndex = me.seat
  }
}

function flowerMeLooked(): boolean {
  return !!flowerState.players.find((p) => p.id === flowerState.myUid)?.looked
}
