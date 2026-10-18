import { LandlordCallback, applyTrustSeat, clearSelfPlayAreaIfMyTurn } from '@/logic/landlord/LandlordCallback'
import { CallBack } from '@/logic/shared/CallBack'
import { PHASE } from '@/logic/landlord/rules/room'
import { HAND_SIZE } from '@/logic/pdk/rules/room'
import { LandlordEvents, eventBus } from '@/core/eventBus'
import { clearSelected, flashHandTip, landlordState } from '@/services/landlordState'
import { sortCards } from '@/logic/shared/card'
import { indexToSide, parseMyCards, parsePlayCards } from '@/logic/shared/roomSeats'
import {
  playClockActionKey,
  stopCountdownDisplay,
  syncPlayCountdownForce,
} from '@/logic/landlord/playCountdown'
import { resolvePlayFxType } from '@/logic/landlord/resolvePlayFx'
import { applyPdkBombSettle, applyPdkSettleResult, resetPdkBombScores } from '@/logic/pdk/settleResult'
import { syncRoundProgress } from '@/services/gameState'
import { playDdzPlaySfx, playDdzWarnSfx } from '@/logic/shared/ddzTableAudio'

/** 连续过牌。两家都管不上则清桌，由上一个出牌的人重新领出 */
let consecutivePasses = 0
/** 同一轮里每个座位只计一次过牌，避免 RPC 和广播各加一次把桌面提前清掉 */
const passedSeats = new Set<number>()

/**
 * 跑得快推送：无叫抢/加倍；开局直接 play；mustSpade3 / bombSettle。
 * 房间快照 / 托管 / 解散复用基类。
 */
export class PdkCallback extends LandlordCallback {
  constructor(ctx: ConstructorParameters<typeof LandlordCallback>[0]) {
    super(ctx)
    this.register(CallBack.START_GAME, this.onStartGame)
    this.register(CallBack.PLAY, this.onPlay)
    this.register(CallBack.WAIT_START, this.onWaitStart)
  }

  override onStartGame(data: Record<string, unknown>): void {
    this.applyPdkStart(data)
  }

  override onWaitStart(data: Record<string, unknown>): void {
    const phase = String(data.phase || '').toLowerCase()
    if (phase === 'play' || Array.isArray(data.myCards)) {
      this.applyPdkStart({ ...data, callback: 'startgame' })
      return
    }
    super.onWaitStart(data)
  }

  applyPdkStart(data: Record<string, unknown>): void {
    const cb = String(data.callback ?? '').toLowerCase()
    if (cb === 'waitstart') {
      this.applyReadyOnly(data)
      return
    }

    const s = landlordState
    const fromResume = !!data._fromResume
    const incoming = Array.isArray(data.myCards) ? parseMyCards(data, { sorted: false }) : []
    if (!fromResume && s.dealPlayed && s.hand.length >= HAND_SIZE) return
    if (!fromResume) {
      resetPdkBombScores()
      syncRoundProgress(data, 'start')
    }

    s.hasCaller = false
    s.maxBid = 0
    s.farmerDoubles = {}
    s.doubleOpsLocked = true
    s.trusted = false
    s.seats.left.isOffline = false
    s.seats.right.isOffline = false
    s.tipReveal = ''
    s.landlordIndex = null
    s.bottom = []
    s.settle = null
    s.result = null
    s.showSettle = false
    consecutivePasses = 0
    passedSeats.clear()
    s.lastPlayed = []
    s.lastPlayedBy = ''
    s.lastPlayedByAbs = -1
    s.outBySide = { self: [], left: [], right: [] }
    s.passBadgeBySide = { self: false, left: false, right: false }
    s.actionBadgeBySide = { self: '', left: '', right: '' }
    s.canStart = false
    s.selfReady = false
    s.starting = false
    s.statusText = ''
    s.bombFlash = ''
    s.coinFly = null
    s.mustSpade3 = !!(data.mustSpade3 ?? data.firstMustSpade3)
    s.phase = PHASE.PLAY
    s.dealing = !fromResume

    if (incoming.length) {
      s.hand = fromResume ? sortCards(incoming) : incoming
      clearSelected()
    }
    s.spade3Hold = false
    if (data.currentIndex != null) s.turnIndex = Number(data.currentIndex)
    else if (data.firstIndex != null) s.turnIndex = Number(data.firstIndex)
    s.spade3FromAbs = null
    if (s.mustSpade3) {
      const abs = Number(data.currentIndex ?? data.firstIndex ?? s.turnIndex)
      if (Number.isFinite(abs) && abs >= 0) {
        s.spade3FromAbs = abs
        s.spade3TipId += 1
        s.spade3Hold = true
      }
    }
    if (data.bombCount != null) s.bombCount = Number(data.bombCount) || 0
    const mult = Number(data.multiple)
    if (Number.isFinite(mult) && mult > 0) s.multiple = mult
    if (data.trusted != null) s.trusted = !!data.trusted

    this.applyRoomSnapshot(data)
    s.canStart = false
    s.phase = PHASE.PLAY

    if (s.hand.length && !fromResume) {
      this.queueOpeningDeal()
      return
    }

    s.dealing = false
    s.dealPlayed = true
    if (!fromResume) {
      if (s.seats.left.occupied) s.seats.left.cardsLeft = HAND_SIZE
      if (s.seats.right.occupied) s.seats.right.cardsLeft = HAND_SIZE
    }
    if (s.turnIndex != null && Number(s.turnIndex) >= 0) {
      syncPlayCountdownForce()
    }
    eventBus.emit(LandlordEvents.PHASE, s.phase)
  }

  override applyResumeStart(data: Record<string, unknown>): void {
    this.applyPdkStart(data)
  }

  override onPlay(data: Record<string, unknown>): void {
    const s = landlordState
    const myIndex = s.myIndex

    if (data.autoHosted || data.hosted || data.trusted === true) {
      const hostedIndex = data.hostedIndex ?? data.playIndex ?? data.passIndex ?? data.seatNo
      if (hostedIndex != null) applyTrustSeat(Number(hostedIndex), true)
    }
    if (data.trusted === false && data.seatNo != null) {
      applyTrustSeat(Number(data.seatNo), false)
    }

    const action = String(data.action || '')
    const rawCards = Array.isArray(data.cards) ? (data.cards as unknown[]) : []
    const isPass = action === 'pass' || (rawCards.length === 0 && data.passIndex != null)

    if (isPass) {
      const passAbs = Number(data.passIndex ?? data.playIndex)
      if (Number.isFinite(passAbs)) {
        const side = indexToSide(myIndex, passAbs)
        s.outBySide = { ...s.outBySide, [side]: [] }
        s.passBadgeBySide = { ...s.passBadgeBySide, [side]: true }
        if (!passedSeats.has(passAbs)) {
          passedSeats.add(passAbs)
          consecutivePasses += 1
        }
        if (consecutivePasses >= 2) {
          consecutivePasses = 0
          passedSeats.clear()
          s.lastPlayed = []
          s.lastPlayedBy = ''
          s.lastPlayedByAbs = -1
          s.outBySide = { self: [], left: [], right: [] }
          s.passBadgeBySide = { self: false, left: false, right: false }
        }
        playDdzPlaySfx({ action: 'pass', sex: 0, rules: 'pdk' })
        eventBus.emit(LandlordEvents.PASS, { side, data })
        this.ctx.queue.enqueue({ type: 'pass', payload: { side, data } })
      }
    } else if (action === 'play' || rawCards.length > 0) {
      const playIndex = Number(data.playIndex)
      if (Number.isFinite(playIndex)) {
        const side = indexToSide(myIndex, playIndex)
        const cards = parsePlayCards(rawCards, playIndex)
        const following =
          s.lastPlayed.length > 0 && Number(s.lastPlayedByAbs) !== playIndex && s.lastPlayedByAbs >= 0
        consecutivePasses = 0
        passedSeats.clear()
        s.mustSpade3 = false
        s.spade3FromAbs = null
        s.spade3Hold = false
        if (s.centerTip === 'spade3') s.centerTip = ''
        s.outBySide = { ...s.outBySide, [side]: cards }
        s.passBadgeBySide = { ...s.passBadgeBySide, [side]: false }

        let remainForWarn = 0
        if (side === 'self') {
          if (Array.isArray(data.myCards)) s.hand = parseMyCards({ myCards: data.myCards })
          else {
            const playedSet = new Set(rawCards.map((c) => String(c).toUpperCase()))
            s.hand = s.hand.filter((c) => !playedSet.has(String(c.code || '').toUpperCase()))
          }
          clearSelected()
          remainForWarn = s.hand.length
        } else {
          const seat = s.seats[side as 'left' | 'right']
          if (seat) {
            const hc = data.handCount
            seat.cardsLeft =
              typeof hc === 'number' ? hc : Math.max(0, (seat.cardsLeft ?? HAND_SIZE) - cards.length)
            remainForWarn = seat.cardsLeft ?? 0
          }
        }

        s.lastPlayed = cards
        s.lastPlayedBy = side
        s.lastPlayedByAbs = playIndex
        if (data.isBomb || data.bombSettle) {
          s.bombCount = Number(data.bombCount ?? s.bombCount + 1)
        }

        const fxType = resolvePlayFxType(cards, data as { isBomb?: boolean })
        playDdzPlaySfx({
          action: 'play',
          pattern: fxType,
          cards,
          following,
          sex: 0,
          rules: 'pdk',
        })
        if (remainForWarn === 1 || remainForWarn === 2) playDdzWarnSfx(remainForWarn, true, 0)
        eventBus.emit(LandlordEvents.PLAY, { side, cards, data })
        this.ctx.queue.enqueue({ type: fxType, payload: { side, cards, data } })

        if (data.bombSettle && typeof data.bombSettle === 'object') {
          applyPdkBombSettle(data.bombSettle as Record<string, unknown>)
        }
      }
    }

    const rejectMsg = String(data.message || data.msg || '')
    if (/牌型不合法|不符合/.test(rejectMsg)) flashHandTip('chuPaiBuFuHe')
    else if (/压不过|打不过|管不住|大过上家|必须管/.test(rejectMsg)) {
      flashHandTip('daBuGuoShangJia')
    }

    if (data.settle) {
      s.dealPlayed = false
      s.phase = PHASE.SETTLE
      s.settle = data.settle as Record<string, unknown>
      stopCountdownDisplay()
      syncRoundProgress(data.settle as Record<string, unknown>, 'settle')
      applyPdkSettleResult({
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
      s.passBadgeBySide = { ...s.passBadgeBySide, [turnSide]: false }
      clearSelfPlayAreaIfMyTurn()
      syncPlayCountdownForce(playClockActionKey(data))
    }
  }
}
