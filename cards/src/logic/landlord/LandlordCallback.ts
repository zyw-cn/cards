import { GameCallback } from '@/logic/shared/GameCallback'
import { CallBack } from '@/logic/shared/CallBack'
import {
  applyReadyFlags,
  computeCanStart,
  extractPlayers,
  findSelfAbsIndex,
  indexToSide,
  occupiedPlayers,
  parseBottomCards,
  parseMyCards,
  parsePlayCards,
  seatsFromRelativePlayers,
  syncSeatReadyFromPlayers,
  toRelativePlayers,
  waitStatusText,
  type RelativePlayer,
  type SeatSide,
} from '@/logic/shared/roomSeats'
import { PHASE, TIMEOUT } from '@/logic/landlord/rules/room'
import { currentTableFeatures } from '@/logic/table/playMode'
import { LandlordEvents, eventBus } from '@/core/eventBus'
import type { ActionQueue } from '@/core/actionQueue'
import { applyRoomSnapshot as applyGlobalRoom, gameState, syncRoundProgress } from '@/services/gameState'
import {
  applyDismissVote,
  clearDismissVote,
  clearSelected,
  flashHandTip,
  landlordState,
  needMyDouble,
  setFarmerDouble,
  showChatBubble,
  clearChatBubbles,
  type ActionBadge,
  type LandlordMatchState,
} from '@/services/landlordState'
import { sortCards } from '@/logic/shared/card'
import type { ClientCard } from '@/logic/shared/serverCard'
import { applySettleResult } from '@/logic/landlord/settleResult'
import {
  playClockActionKey,
  restartCountdown,
  stopCountdownDisplay,
  syncPlayCountdownForce,
} from '@/logic/landlord/playCountdown'
import { resolvePlayFxType } from '@/logic/landlord/resolvePlayFx'
import {
  playDdzBidSfx,
  playDdzDoubleSfx,
  playDdzPlaySfx,
  playDdzWarnSfx,
  type DdzAudioRules,
} from '@/logic/shared/ddzTableAudio'

type Ctx = {
  queue: ActionQueue
  onDissolved?: (data: Record<string, unknown>) => void
}

/**
 * 斗地主 WS 回调：只改 landlordState → emit → enqueue，不碰 Pixi。
 */
export class LandlordCallback extends GameCallback {
  declare protected ctx: Ctx & Record<string, unknown>

  constructor(ctx: Ctx) {
    super(ctx as unknown as Record<string, unknown>)
    this.register(CallBack.JOIN_ROOM, this.onJoinRoom)
    this.register(CallBack.ROOM_READY, this.onRoomReady)
    this.register(CallBack.WAIT_START, this.onWaitStart)
    this.register(CallBack.START_GAME, this.onStartGame)
    this.register(CallBack.BID, this.onBid)
    this.register(CallBack.DOUBLE, this.onDouble)
    this.register(CallBack.PLAY, this.onPlay)
    this.register(CallBack.TRUST, this.onTrust)
    this.register(CallBack.UNTRUST, this.onUntrust)
    this.register(CallBack.ROOM_DISSOLVE, this.onRoomDissolve)
    this.register(CallBack.APPLY_DISMISS, this.onApplyDismiss)
    this.register(CallBack.VOTE_DISMISS, this.onVoteDismiss)
    this.register(CallBack.CANCEL_DISMISS, this.onCancelDismiss)
    this.register(CallBack.CHAT, this.onChat)
  }

  onChat(data: Record<string, unknown>): void {
    const text = data.text ?? data.content ?? data.message
    const abs = Number(data.seatNo ?? data.seat_no ?? data.index)
    showChatBubble(abs, text)
  }

  onRoomDissolve(data: Record<string, unknown>): void {
    clearDismissVote()
    this.ctx.onDissolved?.(data)
    eventBus.emit(LandlordEvents.DISSOLVE, data)
    this.ctx.queue.enqueue({ type: 'dissolve', payload: data })
  }

  onApplyDismiss(data: Record<string, unknown>): void {
    applyDismissVote(data)
  }

  onVoteDismiss(data: Record<string, unknown>): void {
    applyDismissVote(data)
    if (data.dissolve) {
      this.onRoomDissolve(data)
    }
  }

  onCancelDismiss(data: Record<string, unknown>): void {
    // 仅关投票面板；不同意用绿/黄按钮，不广播 tip（多人同步拒绝会刷屏）
    clearDismissVote()
    eventBus.emit(LandlordEvents.DISSOLVE, { ...data, cancelled: true })
  }

  onJoinRoom(data: Record<string, unknown>): void {
    this.applyRoomSnapshot(data, { forceReady: false })
    if ('inGame' in data) syncRoundProgress(data, 'join')
    if (data.inGame && data.resume && typeof data.resume === 'object') {
      this.applyResume(data.resume as Record<string, unknown>)
    }
  }

  onRoomReady(data: Record<string, unknown>): void {
    this.applyRoomSnapshot(data, { forceReady: true })
    if ('inGame' in data) syncRoundProgress(data, 'join')
    if (data.inGame && data.resume && typeof data.resume === 'object') {
      this.applyResume(data.resume as Record<string, unknown>)
    }
  }

  onWaitStart(data: Record<string, unknown>): void {
    this.applyReadyOnly(data)
  }

  onStartGame(data: Record<string, unknown>): void {
    this.applyStartGame(data)
  }

  /** 托管开关：全桌同步头像旁 tip */
  onTrust(data: Record<string, unknown>): void {
    applyTrustFlag(data, true)
  }

  onUntrust(data: Record<string, unknown>): void {
    applyTrustFlag(data, false)
  }

  onBid(data: Record<string, unknown>): void {
    const s = landlordState
    applyLastBidMark(data)

    // 三家都不叫且服务端已重发牌：走 startgame 同路径（新牌 + 提示）
    if (data.redraw && isAutoRedealPacket(data)) {
      this.applyStartGame({ ...data, callback: CallBack.START_GAME })
      return
    }

    if (data.redraw) {
      // 人数不足等无法自动重发：回等待准备
      s.dealPlayed = false
      s.phase = PHASE.WAIT_READY
      s.hand = []
      s.hasCaller = false
      s.maxBid = 0
      s.bidTurn = null
      s.tipReveal = ''
      s.canStart = true
      s.selfReady = false
      s.readyCount = 0
      s.statusText = ''
      gameState.room.players.forEach((p) => {
        p.ready = false
      })
      s.seats.left.isReady = false
      s.seats.right.isReady = false
      clearActionBadges()
      showCenterTip('cxfp', 2800)
      eventBus.emit(LandlordEvents.PHASE, s.phase)
      eventBus.emit(LandlordEvents.TIP, { key: 'cxfp', data })
      return
    }

    if (data.phase === 'play') {
      // 叫抢结束且无加倍：剩余 tip 留 1s 一起清，再开打
      beginBidTipRevealThen(() => {
        clearActionBadges()
        this.applyLandlordResult(data)
      })
      return
    }

    if (data.phase === 'double') {
      // 兼容旧推送 callback=bid + phase=double：立刻进加倍，tip 再留 1s
      beginDoubleAfterBidClear(data, () => this.enterDoublePhase(data))
      return
    }

    // 下一轮轮到谁：只清「即将行动」座位的上一轮 tip（第二轮覆盖）；其他人 tip 继续留着
    if (data.nextBidTurn != null) {
      const next = Number(data.nextBidTurn)
      const nextSide = indexToSide(s.myIndex, next)
      if (s.actionBadgeBySide[nextSide]) {
        s.actionBadgeBySide = { ...s.actionBadgeBySide, [nextSide]: '' }
      }
      s.bidTurn = next
    }
    if (data.stage === 'rob') s.hasCaller = true
    s.phase = data.phase ? String(data.phase) : s.phase || PHASE.BID
    if (s.phase === 'grab') s.phase = PHASE.GRAB
    restartCountdown(TIMEOUT.BID)
    eventBus.emit(LandlordEvents.BID, data)
    this.ctx.queue.enqueue({ type: 'bid', payload: data })
  }

  onDouble(data: Record<string, unknown>): void {
    const s = landlordState
    const fromBid =
      s.phase === PHASE.BID ||
      s.phase === PHASE.GRAB ||
      s.phase === 'bid' ||
      s.phase === 'grab'

    if (data.farmerDoubles && typeof data.farmerDoubles === 'object') {
      s.farmerDoubles = { ...(data.farmerDoubles as Record<string, number>) }
    }
    const mult = Number(data.multiple ?? data.multiplier)
    if (Number.isFinite(mult) && mult > 0) s.multiple = mult

    // 定庄首包：callback=double + lastBid（尚无 lastDouble）→ 贴叫抢 tip，立刻进加倍
    if (data.phase === 'double' && !data.lastDouble && fromBid) {
      applyLastBidMark(data)
      beginDoubleAfterBidClear(data, () => this.enterDoublePhase(data))
      return
    }

    // 加倍完成 → 出牌
    if (data.phase === 'play') {
      applyLastDoubleMark(data)
      if (data.farmerDoubles && typeof data.farmerDoubles === 'object') {
        syncDoubleBadgesFromMap(data.farmerDoubles as Record<string, number>)
      }
      beginDoubleTipRevealThen(data, () => this.applyLandlordResult(data))
      return
    }

    // 同时加倍：漏了定庄包时先补进加倍
    if (fromBid && (data.lastDouble || data.phase === 'double')) {
      applyLastBidMark(data)
      beginDoubleAfterBidClear(
        { ...data, lastDouble: undefined },
        () => this.enterDoublePhase({ ...data, lastDouble: undefined }),
      )
    }

    applyLastDoubleMark(data)
    if (data.farmerDoubles && typeof data.farmerDoubles === 'object') {
      syncDoubleBadgesFromMap(data.farmerDoubles as Record<string, number>)
    }

    if (data.phase === 'double' || s.phase === PHASE.DOUBLE || s.phase === 'double') {
      s.phase = PHASE.DOUBLE
      if (!needMyDouble()) s.doubleOpsLocked = true
      else if (s.countdown <= 0) {
        if (data.doubleSeconds != null) armDoubleDeadline(data)
        const left = remainingDoubleSec()
        if (left > 0) restartCountdown(left)
        else {
          s.doubleOpsLocked = true
          stopCountdownDisplay()
        }
      }
      eventBus.emit(LandlordEvents.DOUBLE, data)
      eventBus.emit(LandlordEvents.PHASE, s.phase)
      this.ctx.queue.enqueue({ type: 'double', payload: data })
    }
  }

  onPlay(data: Record<string, unknown>): void {
    const s = landlordState
    const myIndex = s.myIndex

    if (data.autoHosted || data.hosted || data.trusted === true) {
      const hostedIndex = data.hostedIndex ?? data.playIndex ?? data.passIndex ?? data.seatNo
      if (hostedIndex != null) {
        applyTrustSeat(Number(hostedIndex), true)
      }
    }
    if (data.trusted === false && data.seatNo != null) {
      applyTrustSeat(Number(data.seatNo), false)
    }

    const mult = Number(data.multiple)
    if (Number.isFinite(mult) && mult > 0) s.multiple = mult

    const action = String(data.action || '')
    const rawCards = Array.isArray(data.cards) ? (data.cards as unknown[]) : []
    const isPass =
      action === 'pass' || (rawCards.length === 0 && data.passIndex != null)

    if (isPass) {
      const passAbs = Number(data.passIndex ?? data.playIndex)
      if (!Number.isFinite(passAbs)) {
        // 缺座位号则无法落到正确出牌区，避免误画到 left
      } else {
        const side = indexToSide(myIndex, passAbs)
        s.outBySide = { ...s.outBySide, [side]: [] }
        s.passBadgeBySide = { ...s.passBadgeBySide, [side]: true }
        consecutivePasses += 1
        if (consecutivePasses >= 2) finishTrickAfterDoublePass()
        playDdzPlaySfx({ action: 'pass', sex: 0, rules: audioRules() })
        eventBus.emit(LandlordEvents.PASS, { side, data })
        this.ctx.queue.enqueue({ type: 'pass', payload: { side, data } })
      }
    } else if (action === 'play' || rawCards.length > 0) {
      const playIndex = Number(data.playIndex)
      if (!Number.isFinite(playIndex)) {
        console.warn('[landlord] play 缺 playIndex，跳过展示', data)
      } else {
        const side = indexToSide(myIndex, playIndex)
        const cards = parsePlayCards(rawCards, playIndex)
        const following =
          s.lastPlayed.length > 0 && Number(s.lastPlayedByAbs) !== playIndex && s.lastPlayedByAbs >= 0
        // 新一轮领出：先清桌再亮本手（对齐 QQ / 旧桌）
        if (consecutivePasses >= 2 || s.lastPlayed.length === 0) {
          if (consecutivePasses >= 2) finishTrickAfterDoublePass()
        }
        consecutivePasses = 0
        s.outBySide = { ...s.outBySide, [side]: cards }
        s.passBadgeBySide = { ...s.passBadgeBySide, [side]: false }

        let remainForWarn = 0
        if (side === 'self') {
          if (Array.isArray(data.myCards)) {
            s.hand = parseMyCards({ myCards: data.myCards })
          } else {
            const playedSet = new Set(rawCards.map((c) => String(c).toUpperCase()))
            s.hand = s.hand.filter((c) => !playedSet.has(String(c.code || '').toUpperCase()))
          }
          clearSelected()
          remainForWarn = s.hand.length
          maybeBaojing(s.hand.length)
        } else {
          const seat = s.seats[side as 'left' | 'right']
          if (seat) {
            const hc = data.handCount
            seat.cardsLeft =
              typeof hc === 'number'
                ? hc
                : Math.max(0, (seat.cardsLeft ?? 17) - cards.length)
            remainForWarn = seat.cardsLeft ?? 0
            maybeBaojing(seat.cardsLeft)
          }
        }

        s.lastPlayed = cards
        s.lastPlayedBy = side
        s.lastPlayedByAbs = playIndex
        if (data.isBomb) {
          s.bombCount = Number(data.bombCount ?? s.bombCount + 1)
        }

        const fxType = resolvePlayFxType(cards, data as { isBomb?: boolean })
        playDdzPlaySfx({
          action: 'play',
          pattern: fxType,
          cards,
          following,
          sex: 0,
          rules: audioRules(),
        })
        if (remainForWarn === 1 || remainForWarn === 2) {
          playDdzWarnSfx(remainForWarn, true, 0)
        }
        eventBus.emit(LandlordEvents.PLAY, { side, cards, data })
        this.ctx.queue.enqueue({
          type: fxType,
          payload: { side, cards, data },
        })
      }
    }

    if (data.invalidPlay || data.code === 'invalid_play') {
      flashHandTip('chuPaiBuFuHe')
    }
    if (data.cannotBeat) {
      flashHandTip('daBuGuoShangJia')
    }
    // 部分拒答只带 message
    const rejectMsg = String(data.message || data.msg || '')
    if (/牌型不合法|不符合/.test(rejectMsg)) flashHandTip('chuPaiBuFuHe')
    else if (/压不过|打不过|管不住|大过上家/.test(rejectMsg)) flashHandTip('daBuGuoShangJia')

    if (data.settle) {
      s.dealPlayed = false
      s.phase = PHASE.SETTLE
      s.settle = data.settle as Record<string, unknown>
      stopCountdownDisplay()
      consecutivePasses = 0
      syncRoundProgress(data.settle as Record<string, unknown>, 'settle')
      applySettleResult({
        ...(data.settle as Record<string, unknown>),
        winnerIndex: data.winnerIndex ?? (data.settle as Record<string, unknown>).winnerIndex,
      })
      eventBus.emit(LandlordEvents.SETTLE, data)
      this.ctx.queue.enqueue({ type: 'settle', payload: data })
      return
    }

    if (data.currentIndex != null) {
      s.turnIndex = Number(data.currentIndex)
      const turnSide = indexToSide(myIndex, s.turnIndex)
      // 再次轮到该座位：清其「不要」，留给新出牌
      s.passBadgeBySide = { ...s.passBadgeBySide, [turnSide]: false }
      // 轮到自己：清掉上一手出牌区，避免挡操作按钮
      clearSelfPlayAreaIfMyTurn()
      // 本人管不住本地 5s；看别人始终 20s（各端自判）；新动作即使回到同一座位也重开闹钟
      syncPlayCountdownForce(playClockActionKey(data))
    }
  }

  enterDoublePhase(data: Record<string, unknown>): void {
    const s = landlordState
    s.phase = PHASE.DOUBLE
    s.bidTurn = -1
    // tipReveal 由 beginDoubleAfterBidClear 管理；此处勿强清
    if (data.farmerDoubles && typeof data.farmerDoubles === 'object') {
      s.farmerDoubles = { ...(data.farmerDoubles as Record<string, number>) }
    } else if (!s.farmerDoubles) {
      s.farmerDoubles = {}
    }
    if (data.landlordIndex != null) s.landlordIndex = Number(data.landlordIndex)
    else if (data.landlordSeat != null) s.landlordIndex = Number(data.landlordSeat)
    syncDoubleBadgesFromMap(s.farmerDoubles)
    if (Array.isArray(data.remain) || Array.isArray(data.bottom)) {
      s.bottom = parseBottomCards(data.remain ?? data.bottom)
    }
    if (Array.isArray(data.myCards)) {
      s.hand = parseMyCards(data)
      clearSelected()
    }
    const mult = Number(data.multiple ?? data.landlordMultiple)
    if (Number.isFinite(mult) && mult > 0) s.multiple = mult
    // 底牌已亮后再开加倍：解锁本人按钮并开表（按服务端截止时间，不按 tip 后再重新计满）
    const left = remainingDoubleSec()
    s.doubleOpsLocked = !needMyDouble()
    if (left <= 0) {
      // tip 动画拖超时：服务端多半已代操作并进 play，等推送即可
      s.doubleOpsLocked = true
      stopCountdownDisplay()
      clearDoubleDeadline()
    } else {
      restartCountdown(left)
    }
    eventBus.emit(LandlordEvents.PHASE, s.phase)
    eventBus.emit(LandlordEvents.DOUBLE, data)
    this.ctx.queue.enqueue({ type: 'double_enter', payload: data })
  }

  applyRoomSnapshot(data: Record<string, unknown>, opts: { forceReady?: boolean } = {}): void {
    if (!data) return
    const s = landlordState
    const hasPlayerList = extractPlayers(data).length > 0
    const players = hasPlayerList ? occupiedPlayers(data) : []
    this.lockMyIndex(data, players)

    let mapped = gameState.room.players
    if (hasPlayerList) {
      const prevLeftCount = s.seats.left.cardsLeft
      const prevRightCount = s.seats.right.cardsLeft
      const prevLeftAbs = Number(s.seats.left.seatIndex)
      const prevRightAbs = Number(s.seats.right.seatIndex)
      const prevLeftTrusted = !!s.seats.left.isTrusted
      const prevRightTrusted = !!s.seats.right.isTrusted
      const keepCounts = !isWaitPhase(String(s.phase))
      mapped = ensureSelfPlayer(toRelativePlayers(players, s.myIndex, gameState.room.players as RelativePlayer[]))
      s.seats = seatsFromRelativePlayers(mapped as RelativePlayer[])
      if (keepCounts) {
        if (s.seats.left.occupied && Number(s.seats.left.seatIndex) === prevLeftAbs && prevLeftCount != null) {
          s.seats.left.cardsLeft = prevLeftCount
        }
        if (s.seats.right.occupied && Number(s.seats.right.seatIndex) === prevRightAbs && prevRightCount != null) {
          s.seats.right.cardsLeft = prevRightCount
        }
      }
      // 座位重建时包里常不带托管字段，保留本局已同步的 tip 状态
      if (s.seats.left.occupied && Number(s.seats.left.seatIndex) === prevLeftAbs && prevLeftTrusted) {
        s.seats.left.isTrusted = true
      }
      if (s.seats.right.occupied && Number(s.seats.right.seatIndex) === prevRightAbs && prevRightTrusted) {
        s.seats.right.isTrusted = true
      }
      syncSeatReadyFromPlayers(s.seats, mapped as RelativePlayer[])
      applyGlobalRoom({ ...data, myIndex: s.myIndex, players: mapped })
    } else {
      mapped = ensureSelfPlayer(mapped)
      applyGlobalRoom({ ...data, myIndex: s.myIndex, players: mapped })
    }

    const waiting = isWaitPhase(s.phase)
    if (waiting) {
      s.phase = PHASE.WAIT_READY
      const full = computeCanStart(data, opts) || mapped.length >= 3
      s.canStart = full
      // roomready = 人满可开局，≠ 有人已点「开始」。先清空准备态，再只认 readyIds
      mapped.forEach((p) => {
        p.ready = false
      })
      s.selfReady = false
      s.readyCount = 0
      s.seats.left.isReady = false
      s.seats.right.isReady = false
      if (hasPlayerList) {
        applyGlobalRoom({ ...data, myIndex: s.myIndex, players: mapped })
      }
    }

    const readyIds = Array.isArray(data.readyIds) ? data.readyIds : []
    if (waiting && readyIds.length > 0) {
      this.applyReadyOnly(data)
      return
    }
    if (waiting) {
      s.statusText = waitStatusText(mapped.length, s.canStart, s.selfReady, s.readyCount)
    }

    if (Array.isArray(data.applyCloseStateList) && data.applyCloseStateList.length) {
      applyDismissVote(data)
    }

    eventBus.emit(LandlordEvents.STATE, { ...snapshotPublic(s) })
  }

  applyReadyOnly(data: Record<string, unknown>): void {
    const s = landlordState
    if (!isWaitPhase(s.phase)) s.phase = PHASE.WAIT_READY
    const myUid = Number(gameState.user.uid || 0)
    const result = applyReadyFlags(gameState.room.players as RelativePlayer[], data, myUid)
    gameState.room.players = result.players
    // 以本次推送为准，禁止 || 残留把上一局 selfReady 粘住
    s.selfReady = result.selfReady
    s.readyCount = result.readyCount
    syncSeatReadyFromPlayers(s.seats, result.players)
    s.statusText = waitStatusText(result.players.length, s.canStart, s.selfReady, s.readyCount)
    eventBus.emit(LandlordEvents.STATE, { ...snapshotPublic(s) })
  }

  applyStartGame(data: Record<string, unknown>): void {
    const cb = String(data.callback ?? '').toLowerCase()
    if (cb === 'waitstart') {
      this.applyReadyOnly(data)
      return
    }

    const s = landlordState
    const fromResume = !!data._fromResume
    const incoming = Array.isArray(data.myCards) ? parseMyCards(data, { sorted: false }) : []
    // 三家都不叫 → 服务端自动重开叫抢：放开 dealPlayed 锁，提示后重新发牌
    const isAutoRedeal = isAutoRedealPacket(data)
    if (isAutoRedeal) {
      const rid = Number(data.roundId ?? data.round_id ?? 0)
      // RPC + 广播可能各来一包，同一局只处理一次，避免 dealing 被反复打断导致闹钟停死
      if (rid > 0 && rid === lastAutoRedealRoundId) return
      if (rid > 0) lastAutoRedealRoundId = rid
      s.dealPlayed = false
      stopCountdownDisplay()
      applyLastBidMark(data)
      notifySanJiaBuJiaoRedeal()
    }
    // 只有已经拿到完整手牌后才挡重复动画；空包绝不能锁死；resume / 自动重发必须强制覆盖
    if (!fromResume && !isAutoRedeal && s.dealPlayed && s.hand.length >= currentTableFeatures().dealMinLock) {
      return
    }
    if (!fromResume && !isAutoRedeal) syncRoundProgress(data, 'start')

    clearActionBadges()
    clearBadgePhaseTimer()
    consecutivePasses = 0
    s.hasCaller = false
    s.maxBid = 0
    s.farmerDoubles = {}
    s.doubleOpsLocked = false
    s.trusted = false
    s.tipReveal = ''
    s.seats.left.isOffline = false
    s.seats.right.isOffline = false
    s.seats.left.isTrusted = false
    s.seats.right.isTrusted = false
    s.settle = null
    s.lastPlayed = []
    s.lastPlayedBy = ''
    s.lastPlayedByAbs = -1
    s.outBySide = { self: [], left: [], right: [] }
    s.passBadgeBySide = { self: false, left: false, right: false }
    s.canStart = false
    s.selfReady = false
    s.starting = false
    s.statusText = ''
    s.dealing = !fromResume

    if (incoming.length) {
      s.hand = incoming
      clearSelected()
    }
    if (data.phase) s.phase = String(data.phase)
    else if (s.hand.length) s.phase = PHASE.BID

    if (s.phase === 'grab') s.phase = PHASE.GRAB
    if (data.bidTurn != null) s.bidTurn = Number(data.bidTurn)
    if (data.currentIndex != null) s.turnIndex = Number(data.currentIndex)
    if (data.landlordIndex != null) s.landlordIndex = Number(data.landlordIndex)
    if (data.maxBid != null) s.maxBid = Number(data.maxBid) || 0
    // 叫抢阶段先摆三张牌背；定庄后再换成真底牌
    if (Array.isArray(data.remain) || Array.isArray(data.bottom)) {
      s.bottom = parseBottomCards(data.remain ?? data.bottom)
    }
    if (data.farmerDoubles && typeof data.farmerDoubles === 'object') {
      s.farmerDoubles = { ...(data.farmerDoubles as Record<string, number>) }
    }
    const mult = Number(data.multiple)
    if (Number.isFinite(mult) && mult > 0) s.multiple = mult
    if (data.bombCount != null) s.bombCount = Number(data.bombCount) || 0
    if (data.trusted != null) s.trusted = !!data.trusted

    this.applyRoomSnapshot(data)
    s.canStart = false
    // 重连、加倍开局没有飞牌，先按广播把左右张数补上。新开的一局交给洗牌动画从 0 数上去。
    const freshDeal = !fromResume && s.hand.length > 0
    const openingPlay = s.phase === PHASE.PLAY || s.phase === 'play'
    if (s.hand.length && !(freshDeal && openingPlay)) ensureOpponentCardsLeft(data)

    if (Array.isArray(data.applyCloseStateList) && data.applyCloseStateList.length) {
      applyDismissVote(data)
    }

    if (s.phase === PHASE.DOUBLE || s.phase === 'double') {
      s.dealing = false
      s.dealPlayed = true
      clearBidActionBadges()
      this.enterDoublePhase(data)
      return
    }
    if (openingPlay && !freshDeal) {
      s.dealing = false
      s.dealPlayed = true
      this.applyLandlordResult(data)
      return
    }
    if (openingPlay && freshDeal) s.doubleOpsLocked = true

    if (!s.hand.length) {
      s.dealing = false
      return
    }

    if (fromResume) {
      s.dealPlayed = true
      s.dealing = false
      if (s.bidTurn != null && Number(s.bidTurn) >= 0) restartCountdown(TIMEOUT.BID)
      eventBus.emit(LandlordEvents.PHASE, s.phase)
      return
    }
    this.queueOpeningDeal()
  }

  /**
   * 斗地主、癞子、跑得快开局洗牌发牌只走这里。
   * 重连不要调：手牌直接摆上，不播动画。
   */
  protected queueOpeningDeal(): void {
    const s = landlordState
    s.dealPlayed = true
    s.dealing = true
    stopCountdownDisplay()
    if (s.seats.left.occupied) s.seats.left.cardsLeft = 0
    if (s.seats.right.occupied) s.seats.right.cardsLeft = 0
    eventBus.emit(LandlordEvents.DEAL, { hand: s.hand })
    this.ctx.queue.enqueue({ type: 'deal', payload: { hand: [...s.hand] } })
  }

  /**
   * Join 带回的进行中牌局快照：强制覆盖本地状态（含上手牌、剩余张数）。
   */
  applyResume(resume: Record<string, unknown>): boolean {
    if (!resume || typeof resume !== 'object') return false
    const s = landlordState
    s.dealPlayed = false
    s.hand = []
    this.ctx.queue.clear()
    this.applyResumeStart({
      ...resume,
      _fromResume: true,
      callback: 'startgame',
      myCards: resume.myCards,
      phase: resume.phase,
      bidTurn: resume.bidTurn,
      currentIndex: resume.currentIndex ?? resume.firstIndex,
      firstIndex: resume.firstIndex,
      landlordIndex: resume.landlordIndex,
      multiple: resume.multiple,
      maxBid: resume.maxBid,
      farmerDoubles: resume.farmerDoubles,
      bottom: resume.bottom,
      remain: resume.bottom,
      bombCount: resume.bombCount,
      mustSpade3: resume.mustSpade3,
      trusted: resume.trusted,
      seats: resume.seats,
    })
    applyResumeSeatCounts(resume)
    if (!s.hand.length && Array.isArray(resume.myCards) && resume.myCards.length) {
      s.hand = parseMyCards({ myCards: resume.myCards })
    }
    if (s.hand.length) {
      s.hand = sortCards(s.hand)
      s.dealing = false
      s.dealPlayed = true
    }
    s.trusted = !!resume.trusted

    const lp = resume.lastPlay as { cards?: unknown[]; index?: number } | null | undefined
    if (lp && Array.isArray(lp.cards) && lp.cards.length && lp.index != null) {
      const playIndex = Number(lp.index)
      if (Number.isFinite(playIndex)) {
        const cards = parsePlayCards(lp.cards, playIndex)
        const side = indexToSide(s.myIndex, playIndex)
        s.lastPlayed = cards
        s.lastPlayedBy = side
        s.lastPlayedByAbs = playIndex
        s.outBySide = { self: [], left: [], right: [], [side]: [...cards] }
        s.passBadgeBySide = { self: false, left: false, right: false }
        consecutivePasses = 0
      }
    }

    const phase = String(s.phase || '').toLowerCase()
    if (phase === PHASE.PLAY || phase === 'play') {
      syncPlayCountdownForce()
    }
    syncRoundProgress(resume, 'resume')
    eventBus.emit(LandlordEvents.STATE, { ...snapshotPublic(s), resumed: true })
    return true
  }

  /** 子类可覆盖（跑得快走 applyPdkStart） */
  protected applyResumeStart(data: Record<string, unknown>): void {
    this.applyStartGame(data)
  }

  private lockMyIndex(data: Record<string, unknown>, players: unknown[]): void {
    const s = landlordState
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
    const found = findSelfAbsIndex(players, Number(gameState.user.uid || 0))
    if (found != null) {
      s.myIndex = found
      s.myIndexLocked = true
      gameState.room.myIndex = s.myIndex
    }
  }

  applyLandlordResult(data: Record<string, unknown>): void {
    const s = landlordState
    s.phase = PHASE.PLAY
    s.doubleOpsLocked = true
    // 开打首轮必领出：清掉上一局/加倍残留，避免误当成跟牌而藏「提示」
    consecutivePasses = 0
    s.lastPlayed = []
    s.lastPlayedBy = ''
    s.lastPlayedByAbs = -1
    s.outBySide = { self: [], left: [], right: [] }
    s.passBadgeBySide = { self: false, left: false, right: false }
    if (data.landlordIndex != null) s.landlordIndex = Number(data.landlordIndex)
    if (Array.isArray(data.remain) || Array.isArray(data.bottom)) {
      s.bottom = parseBottomCards(data.remain ?? data.bottom)
    }
    if (Array.isArray(data.myCards)) {
      s.hand = parseMyCards(data)
    }
    if (data.currentIndex != null) s.turnIndex = Number(data.currentIndex)
    else if (s.landlordIndex != null) s.turnIndex = s.landlordIndex
    // 叫抢结束才拿底牌：地主 20、农民 17。发牌时写成 0 或仍是 17 的地主座在这里补上。
    ensureOpponentCardsLeft(data)
    const mult = Number(data.multiple)
    if (Number.isFinite(mult) && mult > 0) s.multiple = mult
    // 定庄开打：全桌挂倒计时（本人若管不住则本地 5s）
    if (s.turnIndex != null && Number(s.turnIndex) >= 0) {
      clearSelfPlayAreaIfMyTurn()
      syncPlayCountdownForce()
    } else stopCountdownDisplay()
    eventBus.emit(LandlordEvents.PHASE, s.phase)
    this.ctx.queue.enqueue({ type: 'landlord_set', payload: data })
  }
}

function clearActionBadges(): void {
  landlordState.actionBadgeBySide = { self: '', left: '', right: '' }
}

/** 轮到自己出牌时清掉自己上一手出牌区 / 「不要」，避免挡操作按钮 */
export function clearSelfPlayAreaIfMyTurn(): void {
  const s = landlordState
  if (s.turnIndex == null || Number(s.turnIndex) !== Number(s.myIndex)) return
  if (!s.outBySide.self.length && !s.passBadgeBySide.self) return
  s.outBySide = { ...s.outBySide, self: [] }
  s.passBadgeBySide = { ...s.passBadgeBySide, self: false }
  eventBus.emit(LandlordEvents.PASS, { side: 'self', clearOnTurn: true })
}

const BID_CLEAR_MS = 1000
const DOUBLE_CLEAR_MS = 1000

/** 服务端加倍阶段截止时间（收到定庄包时对齐，避免 tip 动画拖慢本地闹钟） */
let doubleDeadlineAt = 0

function armDoubleDeadline(data: Record<string, unknown>): void {
  const sec = Math.max(0, Math.floor(Number(data.doubleSeconds) || TIMEOUT.DOUBLE))
  doubleDeadlineAt = Date.now() + sec * 1000
}

function remainingDoubleSec(): number {
  if (doubleDeadlineAt <= 0) return Math.max(0, TIMEOUT.DOUBLE)
  return Math.max(0, Math.ceil((doubleDeadlineAt - Date.now()) / 1000))
}

function clearDoubleDeadline(): void {
  doubleDeadlineAt = 0
}
/** tip 展示后再出按钮，避免盖住叫地主/抢地主/加倍 tip */
const OPS_AFTER_TIP_MS = 900
let badgePhaseTimer: ReturnType<typeof setTimeout> | null = null
let opsTipHoldTimer: ReturnType<typeof setTimeout> | null = null
/** 连续「不要」次数；满 2 清桌进入新一轮领出 */
let consecutivePasses = 0
/** 三家都不叫自动重发：去重，避免同一 round 双包打断闹钟 */
let lastAutoRedealRoundId = 0

function clearBadgePhaseTimer(): void {
  if (badgePhaseTimer) {
    clearTimeout(badgePhaseTimer)
    badgePhaseTimer = null
  }
}

function clearOpsTipHoldTimer(): void {
  if (opsTipHoldTimer) {
    clearTimeout(opsTipHoldTimer)
    opsTipHoldTimer = null
  }
}

/** tip 还在时挡住叫抢/加倍按钮；到点再放行 */
function holdOpsUntilTipGone(ms: number = OPS_AFTER_TIP_MS): void {
  const s = landlordState
  s.opsTipHold = true
  clearOpsTipHoldTimer()
  opsTipHoldTimer = setTimeout(() => {
    opsTipHoldTimer = null
    s.opsTipHold = false
  }, ms)
}

/** 离桌时清 tip 定时器，避免卸载后仍改 reactive 触发 Vue emitsOptions 空引用 */
export function disposeLandlordUiTimers(): void {
  clearBadgePhaseTimer()
  clearOpsTipHoldTimer()
  landlordState.opsTipHold = false
  clearChatBubbles()
  consecutivePasses = 0
  lastAutoRedealRoundId = 0
}

/** 只清叫抢类 tip，保留加倍 tip */
function clearBidActionBadges(): void {
  const map = landlordState.actionBadgeBySide
  const next = { ...map }
  ;(['self', 'left', 'right'] as const).forEach((side) => {
    const t = next[side]
    if (t === 'call' || t === 'rob' || t === 'noBid' || t === 'noRob') next[side] = ''
  })
  landlordState.actionBadgeBySide = next
}

/** 叫/抢 tip：谁出了谁立刻全员可见（含自己） */
function applyLastBidMark(data: Record<string, unknown>): void {
  const s = landlordState
  const lastBid = data.lastBid as { score?: number; index?: number; action?: number } | undefined
  if (!lastBid || lastBid.index == null) return

  if (typeof lastBid.score === 'number' && lastBid.score > s.maxBid) {
    s.maxBid = lastBid.score
  }
  const mult = Number(data.multiple ?? data.multiplier ?? data.rate)
  if (Number.isFinite(mult) && mult > 0) s.multiple = mult
  if (data.maxBid != null && Number(data.maxBid) > s.maxBid) {
    s.maxBid = Number(data.maxBid)
  }

  const score = Number(lastBid.score ?? 0)
  const abs = Number(lastBid.index)
  if (!Number.isFinite(abs)) return
  const side = indexToSide(s.myIndex, abs)
  if (score > 0) s.hasCaller = true

  let resolved: ActionBadge = 'noBid'
  if (score > 0) {
    const act = Number(lastBid.action)
    if (act === 2) resolved = 'rob'
    else if (act === 1) resolved = 'call'
    else resolved = score > 1 || data.stage === 'rob' || s.maxBid > 1 ? 'rob' : 'call'
  } else {
    resolved = s.hasCaller || data.stage === 'rob' ? 'noRob' : 'noBid'
  }
  // 只写当前行动座位；同座位第二轮再叫/抢时直接覆盖
  s.actionBadgeBySide = { ...s.actionBadgeBySide, [side]: resolved }
  if (side !== 'self' && s.seats[side]) {
    s.seats[side].robState = score > 0 ? 'rob' : 'pass'
  }
  playDdzBidSfx({
    score,
    isRob: resolved === 'rob' || resolved === 'noRob',
    sex: 0,
  })
  // tip 先露一会儿，再允许下家/本人按钮出现
  holdOpsUntilTipGone()
}

/** 加倍 tip：谁选了立刻全员可见；同座位覆盖 */
function applyLastDoubleMark(data: Record<string, unknown>): void {
  const last = data.lastDouble as { score?: number; index?: number } | undefined
  if (!last || last.index == null) return
  const abs = Number(last.index)
  if (!Number.isFinite(abs)) return
  const score = Number(last.score ?? 0) > 0 ? 2 : 0
  setFarmerDouble(abs, score)
  const side = indexToSide(landlordState.myIndex, abs)
  landlordState.actionBadgeBySide = {
    ...landlordState.actionBadgeBySide,
    [side]: score > 0 ? 'double' : 'noDouble',
  }
  playDdzDoubleSfx({ doubled: score > 0, sex: 0 })
  holdOpsUntilTipGone()
}

function syncDoubleBadgesFromMap(map: Record<string, number>): void {
  const my = landlordState.myIndex
  const next = { ...landlordState.actionBadgeBySide }
  for (const [key, raw] of Object.entries(map)) {
    const abs = Number(key)
    if (!Number.isFinite(abs)) continue
    const side = indexToSide(my, abs)
    next[side] = Number(raw) > 0 ? 'double' : 'noDouble'
  }
  landlordState.actionBadgeBySide = next
}

/**
 * 叫抢结束进加倍：先定庄亮底牌并展示叫抢 tip；tip 消失后再解锁加倍按钮。
 */
function beginDoubleAfterBidClear(data: Record<string, unknown>, enter: () => void): void {
  const s = landlordState
  clearBadgePhaseTimer()
  clearOpsTipHoldTimer()
  s.bidTurn = -1
  s.tipReveal = 'bid'
  s.opsTipHold = true
  // 立刻亮底牌 / 定庄；加倍操作先锁住；闹钟按服务端截止对齐
  armDoubleDeadline(data)
  revealLandlordBottom(data)
  stopCountdownDisplay()
  badgePhaseTimer = setTimeout(() => {
    badgePhaseTimer = null
    s.tipReveal = ''
    clearBidActionBadges()
    // tip 清掉后再出加倍按钮，避免盖住叫/抢 tip
    holdOpsUntilTipGone(280)
    enter()
  }, BID_CLEAR_MS)
}

/**
 * 定庄后立刻展示底牌与地主身份（尚未开始加倍倒计时）。
 */
function revealLandlordBottom(data: Record<string, unknown>): void {
  const s = landlordState
  s.phase = PHASE.DOUBLE
  s.bidTurn = -1
  s.doubleOpsLocked = true
  if (data.landlordIndex != null) s.landlordIndex = Number(data.landlordIndex)
  else if (data.landlordSeat != null) s.landlordIndex = Number(data.landlordSeat)
  if (Array.isArray(data.remain) || Array.isArray(data.bottom)) {
    s.bottom = parseBottomCards(data.remain ?? data.bottom)
  }
  if (Array.isArray(data.myCards)) {
    s.hand = parseMyCards(data)
    clearSelected()
  }
  const mult = Number(data.multiple ?? data.landlordMultiple)
  if (Number.isFinite(mult) && mult > 0) s.multiple = mult
  if (data.farmerDoubles && typeof data.farmerDoubles === 'object') {
    s.farmerDoubles = { ...(data.farmerDoubles as Record<string, number>) }
  } else {
    s.farmerDoubles = {}
  }
  syncDoubleBadgesFromMap(s.farmerDoubles)
  eventBus.emit(LandlordEvents.PHASE, s.phase)
  eventBus.emit(LandlordEvents.DOUBLE, { ...data, bottomReveal: true })
}

/**
 * 叫抢整轮结束且无加倍：tip 再留 1s，然后回调清 tip / 开打。
 */
function beginBidTipRevealThen(after: () => void): void {
  const s = landlordState
  clearBadgePhaseTimer()
  s.bidTurn = -1
  stopCountdownDisplay()
  s.tipReveal = 'bid'
  badgePhaseTimer = setTimeout(() => {
    badgePhaseTimer = null
    s.tipReveal = ''
    after()
  }, BID_CLEAR_MS)
}

/**
 * 加倍全员选完：tip 再留 1s 一起消失，再开打。
 */
function beginDoubleTipRevealThen(data: Record<string, unknown>, goPlay: () => void): void {
  const s = landlordState
  clearBadgePhaseTimer()
  clearOpsTipHoldTimer()
  clearBidActionBadges()
  clearDoubleDeadline()
  if (data.farmerDoubles && typeof data.farmerDoubles === 'object') {
    s.farmerDoubles = { ...(data.farmerDoubles as Record<string, number>) }
    syncDoubleBadgesFromMap(s.farmerDoubles)
  }
  s.doubleOpsLocked = true
  s.opsTipHold = true
  stopCountdownDisplay()
  s.tipReveal = 'double'
  badgePhaseTimer = setTimeout(() => {
    badgePhaseTimer = null
    s.tipReveal = ''
    clearActionBadges()
    s.opsTipHold = false
    goPlay()
  }, DOUBLE_CLEAR_MS)
}

/** 两家不要后清桌，下家领出 */
function finishTrickAfterDoublePass(): void {
  const s = landlordState
  consecutivePasses = 0
  s.lastPlayed = []
  s.lastPlayedBy = ''
  s.lastPlayedByAbs = -1
  s.outBySide = { self: [], left: [], right: [] }
  s.passBadgeBySide = { self: false, left: false, right: false }
}

export function applyTrustSeat(absSeat: number, trusted: boolean): void {
  const s = landlordState
  if (!Number.isFinite(absSeat)) return
  const side = indexToSide(s.myIndex, absSeat)
  if (side === 'self') {
    s.trusted = trusted
    if (!trusted && s.centerTip === 'tuoguan') s.centerTip = ''
    return
  }
  if (s.seats[side]) s.seats[side].isTrusted = trusted
}

function applyTrustFlag(data: Record<string, unknown>, trusted: boolean): void {
  const seat = Number(data.seatNo ?? data.seat_no ?? data.seat ?? data.index ?? data.hostedIndex)
  if (Number.isFinite(seat)) {
    applyTrustSeat(seat, trusted)
    return
  }
  // 无座位号时：仅更新本人（兼容旧包）
  if (data.trusted === trusted || data.trusted == null) {
    landlordState.trusted = trusted
    if (!trusted && landlordState.centerTip === 'tuoguan') landlordState.centerTip = ''
  }
}

/**
 * 左右剩余张数。
 * 优先用广播 players[].cardsCount；没有则按自己手牌和是否地主推算。
 * 已有正数张数时保留（避免把打过的牌数刷回开局），只把 0/空补上，并把仍停在发牌张数的地主座加上底牌。
 */
export function ensureOpponentCardsLeft(data?: Record<string, unknown>): void {
  const s = landlordState
  const fromServer = new Map<number, number>()
  if (data) {
    for (const raw of extractPlayers(data)) {
      if (!raw || typeof raw !== 'object') continue
      const rec = raw as Record<string, unknown>
      const abs = Number(rec.seat_no ?? rec.seatNo ?? rec.seat ?? rec.index)
      const cnt = Number(rec.cardsCount ?? rec.cards_count ?? rec.cardCount)
      if (!Number.isFinite(abs) || !Number.isFinite(cnt) || cnt <= 0) continue
      fromServer.set(abs, cnt)
    }
  }

  const feat = currentTableFeatures()
  // 斗地主 handSize 是发牌张数 17；癞子 handSize 是地主满手 20
  const farmerN = feat.kind === 'laizi' ? Math.max(1, feat.handSize - 3) : feat.handSize
  const lordN = feat.hasBottom ? farmerN + 3 : farmerN
  const bottomN = feat.hasBottom ? Math.max(s.bottom.length, 3) : 0
  const lord =
    feat.hasLandlordRole && s.landlordIndex != null && Number(s.landlordIndex) >= 0
      ? Number(s.landlordIndex)
      : null
  const myLen = s.hand.length
  const iAmLord = lord != null && lord === Number(s.myIndex)

  const expectedFor = (absSeat: number): number => {
    if (!feat.hasLandlordRole || lord == null) return myLen > 0 ? myLen : farmerN
    if (absSeat === lord) {
      if (iAmLord && myLen > 0) return myLen
      return lordN
    }
    if (!iAmLord && myLen > 0) return myLen
    if (iAmLord && myLen > farmerN) return Math.max(farmerN, myLen - bottomN)
    return farmerN
  }

  for (const side of ['left', 'right'] as const) {
    const seat = s.seats[side]
    if (!seat?.occupied || seat.seatIndex == null) continue
    const abs = Number(seat.seatIndex)
    const server = fromServer.get(abs)
    if (server != null) {
      seat.cardsLeft = server
      continue
    }
    const expected = expectedFor(abs)
    const cur = Number(seat.cardsLeft)
    const isLord = lord != null && abs === lord
    if (!Number.isFinite(cur) || cur <= 0) {
      seat.cardsLeft = expected
    } else if (isLord && bottomN > 0 && cur + bottomN === expected && cur < expected) {
      seat.cardsLeft = expected
    }
  }
}

function applyResumeSeatCounts(resume: Record<string, unknown>): void {
  const s = landlordState
  const rows = Array.isArray(resume.seats) ? resume.seats : []
  for (const row of rows) {
    if (!row || typeof row !== 'object') continue
    const rec = row as Record<string, unknown>
    const abs = Number(rec.seatNo ?? rec.seat ?? rec.index)
    if (!Number.isFinite(abs)) continue
    const side = indexToSide(s.myIndex, abs)
    const cnt = Number(rec.cardsCount ?? rec.cards_count ?? rec.cardCount)
    if (Number.isFinite(cnt)) {
      if (side === 'left' && s.seats.left.occupied) s.seats.left.cardsLeft = cnt
      if (side === 'right' && s.seats.right.occupied) s.seats.right.cardsLeft = cnt
    }
    if (rec.trusted === true || rec.trusted === 1 || rec.is_auto === 1) {
      if (side === 'self') s.trusted = true
      if (side === 'left' && s.seats.left) s.seats.left.isTrusted = true
      if (side === 'right' && s.seats.right) s.seats.right.isTrusted = true
    }
  }
}

function showCenterTip(key: string, ms = 1400): void {
  if (!key || key === 'baojing') return
  landlordState.centerTip = key
  eventBus.emit(LandlordEvents.TIP, { key })
  window.setTimeout(() => {
    if (landlordState.centerTip === key) landlordState.centerTip = ''
  }, ms)
}

/** 服务端三家都不叫后自动重开：带新牌 / autoRedeal 标记 */
function isAutoRedealPacket(data: Record<string, unknown>): boolean {
  if (data.autoRedeal || data.redeal) return true
  if (!data.redraw) return false
  return Array.isArray(data.myCards) && data.myCards.length > 0
}

/** 「三家都不叫地主系统重新发牌」合并 tip（cxfp.png） */
function notifySanJiaBuJiaoRedeal(): void {
  showCenterTip('cxfp', 2800)
}

/** 报警改挂头像旁（见 GameTableView seatIsAlarm），此处仅保留调用点兼容 */
function maybeBaojing(_remain: number | null | undefined): void {
  // no-op：剩 1/2 张由 UI 根据 hand/cardsLeft 常显头像报警；语音在 onPlay 里 playDdzWarnSfx
}

function audioRules(): DdzAudioRules {
  const k = currentTableFeatures().kind
  if (k === 'pdk') return 'pdk'
  if (k === 'laizi') return 'laizi'
  return 'landlord'
}

function isWaitPhase(phase: string): boolean {
  const p = String(phase || '').toLowerCase()
  return !p || p === PHASE.WAIT_READY || p === 'wait' || p === 'waitready'
}

function ensureSelfPlayer(players: typeof gameState.room.players) {
  if (players.some((p) => p.seat === 0 || Number(p.uid) === Number(gameState.user.uid))) {
    return players
  }
  const uid = Number(gameState.user.uid || 0)
  if (!uid && !gameState.user.nickname) return players
  return [
    {
      uid,
      nickname: gameState.user.nickname || '我',
      seat: 0,
      absSeat: landlordState.myIndex,
      gold: gameState.user.gold,
      ready: landlordState.selfReady,
      online: true,
      isOwner: gameState.room.isMaster,
      avatar: gameState.user.avatar || '',
    },
    ...players,
  ]
}

function snapshotPublic(s: LandlordMatchState) {
  return {
    phase: s.phase,
    canStart: s.canStart,
    selfReady: s.selfReady,
    handCount: s.hand.length,
    seats: s.seats,
  }
}

export type { SeatSide, ClientCard }
