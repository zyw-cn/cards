import { createActionQueue, type ActionQueue } from '@/core/actionQueue'
import { eventBus, LandlordEvents } from '@/core/eventBus'
import { LandlordCallback, disposeLandlordUiTimers } from '@/logic/landlord/LandlordCallback'
import { PdkCallback } from '@/logic/pdk/PdkCallback'
import { clearPreviousRoundDesk } from '@/logic/landlord/settleResult'
import { PHASE } from '@/logic/landlord/rules/room'
import { findHint, playRejectTip } from '@/logic/table/cardRules'
import { currentTableFeatures, resolveTableFeatures, type TableFeatures } from '@/logic/table/playMode'
import { cardsToServer } from '@/logic/shared/serverCard'
import { audioManager } from '@/services/audioManager'
import { gameState, roomHasStarted } from '@/services/gameState'
import { wsService } from '@/services/gameSocket'
import {
  applyDismissVote,
  clearSelected,
  flashHandTip,
  landlordState,
  needMyDouble,
  resetLandlordState,
  selectedCards,
  setFarmerDouble,
  showChatBubble,
} from '@/services/landlordState'
import { DDZ_TABLE_AUDIO, DDZ_TABLE_SHEETS } from '@/config/assetManifest'
import { preloadSheets, unloadSheets } from '@/services/sheetLoader'
import { optimisticDismissVote } from '@/logic/shared/dismiss'
import { consumeLastJoinPayload, joinRoomWs } from '@/services/roomApi'
import { exitEndedRoom, isRoomGoneMessage, leaveToHallIntent } from '@/services/roomResume'
import { setWsRoomResyncing } from '@/services/wsUi'
import {
  bindPlayCountdownWatch,
  canAutoFinishNow,
  clearCountdownClock,
  currentCountdownGen,
  mustFollowNow,
  mustLeadNow,
  selfCannotBeatNow,
  setCountdownExpireHandler,
  syncPlayCountdownForce,
  syncPlayCountdown,
  unbindPlayCountdownWatch,
} from '@/logic/landlord/playCountdown'
import { playDdzTableBgm, stopDdzTableBgm } from '@/logic/shared/ddzTableAudio'

let queue: ActionQueue | null = null
let callback: LandlordCallback | null = null
let offPush: (() => void) | null = null
let offReady: (() => void) | null = null
let countdownTimer: ReturnType<typeof setInterval> | null = null
let active = false
let autoPassing = false
let autoFinishing = false
let resyncing = false
let features: TableFeatures = resolveTableFeatures(1)

const AUTO_FINISH_PAUSE_MS = 750

function isWaitPhase(phase: string): boolean {
  const p = String(phase || '').toLowerCase()
  return !p || p === PHASE.WAIT_READY || p === 'wait' || p === 'waitready'
}

function isPlayPhase(phase: string): boolean {
  const p = String(phase || '').toLowerCase()
  return p === PHASE.PLAY || p === 'play'
}

function ws() {
  return features.ws
}

/** 断线/服务端重启后：重新 Join 并吃 resume，对齐牌局 */
async function resyncRoomAfterReconnect(): Promise<void> {
  if (!active || resyncing || !callback) return
  const roomId = gameState.room.roomId
  if (!roomId || !wsService.ready) return
  resyncing = true
  setWsRoomResyncing(true)
  try {
    const latest = await joinRoomWs(roomId)
    features = resolveTableFeatures(gameState.room.gameType)
    callback.dispatch({
      data: {
        ...latest,
        callback: String(latest.callback || 'joinroom'),
      },
    })
  } catch (err) {
    console.warn('[tableSession] reconnect resync', err)
  } finally {
    resyncing = false
    setWsRoomResyncing(false)
  }
}

/** 剩一手可出完：稍候自动打出整手 */
async function runAutoFinishPlay(): Promise<boolean> {
  const s = landlordState
  if (autoFinishing || autoPassing || !active || s.trusted) return false
  if (!isPlayPhase(String(s.phase))) return false
  if (s.turnIndex == null || Number(s.turnIndex) !== Number(s.myIndex)) return false
  if (s.dealing) return false
  if (!canAutoFinishNow()) return false
  autoFinishing = true
  try {
    await new Promise<void>((r) => setTimeout(r, AUTO_FINISH_PAUSE_MS))
    if (!active || s.trusted) return false
    if (!isPlayPhase(String(s.phase))) return false
    if (Number(s.turnIndex) !== Number(s.myIndex)) return false
    if (!canAutoFinishNow()) return false
    s.selectedCodes = s.hand.map((c) => String(c.code || ''))
    return await tableSession.sendPlay()
  } catch (err) {
    console.warn('[table] auto finish failed', err)
    return false
  } finally {
    autoFinishing = false
  }
}

async function onPlayTimeout(): Promise<void> {
  const s = landlordState
  const gen = currentCountdownGen()
  const turn = Number(s.turnIndex)
  if (autoPassing || autoFinishing || !active || s.trusted) return
  if (!isPlayPhase(String(s.phase))) return
  if (s.turnIndex == null || turn !== Number(s.myIndex)) return
  // 最后一手（整手能一次出完）先自动出。不是最后一手则继续往下，不会代出一部分牌。
  if (await runAutoFinishPlay()) return
  // 让出期间若已换手并重开闹钟，这轮过期作废，避免刚轮到自己就打出一张最小单
  if (gen !== currentCountdownGen()) return
  if (Number(s.turnIndex) !== turn || turn !== Number(s.myIndex)) return
  // 跑得快不是最后一手：不代出一部分牌。管不住才往下走「不出」。
  // 领出、或还能管但不是最后一手，留给玩家；闹钟走完由服务端处理。
  if (
    features.kind === 'pdk' &&
    (mustLeadNow() || (mustFollowNow() && findHint(s.hand, s.lastPlayed).length > 0))
  ) {
    return
  }
  // 领出超时：打出提示牌（通常最小单），避免闹钟停在 0 干等服务端
  if (mustLeadNow()) {
    const pick = findHint(s.hand, [])
    if (pick.length) {
      s.selectedCodes = pick.map((c: { code?: string }) => String(c.code || ''))
      await tableSession.sendPlay()
    }
    return
  }
  // 跑得快能管必须管：可管时超时仍尝试出提示牌，否则过不了
  if (features.forceBeat && mustFollowNow() && findHint(s.hand, s.lastPlayed).length > 0) {
    const pick = findHint(s.hand, s.lastPlayed)
    s.selectedCodes = pick.map((c: { code?: string }) => String(c.code || ''))
    await tableSession.sendPlay()
    return
  }
  autoPassing = true
  try {
    await tableSession.sendPass()
  } catch (err) {
    console.warn('[table] auto pass failed', err)
  } finally {
    autoPassing = false
  }
}

function onCountdownExpire(): void {
  if (!active) return
  const phase = String(landlordState.phase).toLowerCase()
  if (
    features.hasDouble &&
    (phase === PHASE.DOUBLE || phase === 'double') &&
    needMyDouble() &&
    !landlordState.doubleOpsLocked
  ) {
    void tableSession.sendDouble(0)
    return
  }
  if (
    features.hasBid &&
    (phase === PHASE.BID || phase === PHASE.GRAB || phase === 'bid' || phase === 'grab') &&
    landlordState.bidTurn != null &&
    Number(landlordState.bidTurn) === Number(landlordState.myIndex)
  ) {
    void tableSession.passBid()
    return
  }
  if (isPlayPhase(phase) && Number(landlordState.turnIndex) === Number(landlordState.myIndex)) {
    void onPlayTimeout()
  }
}

function startCountdownTick(): void {
  stopCountdownTick()
  setCountdownExpireHandler(onCountdownExpire)
  bindPlayCountdownWatch()
  // 仅驱动解散倒计时；出牌/叫抢闹钟按截止时间走
  countdownTimer = setInterval(() => {
    if (!active) return
    if (landlordState.dismissOpen && landlordState.dismissLeft > 0) {
      landlordState.dismissLeft -= 1
    }
  }, 1000)
}

function stopCountdownTick(): void {
  if (countdownTimer) {
    clearInterval(countdownTimer)
    countdownTimer = null
  }
  unbindPlayCountdownWatch()
  setCountdownExpireHandler(null)
  clearCountdownClock()
}

export { selfCannotBeatNow, syncPlayCountdown, syncPlayCountdownForce, mustFollowNow }

async function rpc(route: string, body: Record<string, unknown> = {}): Promise<Record<string, unknown>> {
  try {
    const res = await wsService.request(route, {
      roomId: gameState.room.roomId,
      ...body,
    })
    return (res.data || {}) as Record<string, unknown>
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err ?? '')
    if (isRoomGoneMessage(msg)) exitEndedRoom()
    throw err
  }
}

function createCallback(q: ActionQueue): LandlordCallback {
  const opts = {
    queue: q,
    onDissolved: () => {
      leaveToHallIntent()
      void tableSession.leave()
    },
  }
  if (features.kind === 'pdk') return new PdkCallback(opts)
  return new LandlordCallback(opts)
}

/**
 * 共用牌桌会话：按 gameType 选 Dou / Lz / Pdk 协议与 Callback，UI 仍绑同一套桌面。
 */
export const tableSession = {
  get queue(): ActionQueue | null {
    return queue
  },

  get active(): boolean {
    return active
  },

  get features(): TableFeatures {
    return features
  },

  async enter(): Promise<void> {
    offPush?.()
    offPush = wsService.onPush((msg) => {
      const data =
        msg.data && typeof msg.data === 'object'
          ? ({ ...msg.data } as Record<string, unknown>)
          : ({} as Record<string, unknown>)
      if (msg.code != null && Number(msg.code) !== 0 && !data.callback) return
      callback?.dispatch({ data, code: msg.code, message: msg.message })
    })
    offReady?.()
    offReady = wsService.onReady(({ reconnect }) => {
      if (!reconnect || !active) return
      void resyncRoomAfterReconnect()
    })
    if (active) return
    active = true
    resetLandlordState()
    features = resolveTableFeatures(gameState.room.gameType)
    if (!gameState.room.gameType) gameState.room.gameType = features.gameType
    if (!gameState.room.playName) gameState.room.playName = features.playName

    await Promise.all([
      preloadSheets(DDZ_TABLE_SHEETS),
      audioManager.preloadPacks(DDZ_TABLE_AUDIO),
    ])

    try {
      await wsService.connect()
    } catch (err) {
      console.warn('[tableSession] ws connect', err)
    }

    queue = createActionQueue({ gapMs: 80 })
    callback = createCallback(queue)

    if (gameState.room.myIndex != null) {
      landlordState.myIndex = Number(gameState.room.myIndex)
      landlordState.myIndexLocked = true
    }

    const roomId = gameState.room.roomId
    if (roomId && wsService.ready) {
      try {
        const latest = await joinRoomWs(roomId)
        // Join 回包可能带回真实 gameType
        features = resolveTableFeatures(gameState.room.gameType)
        callback = createCallback(queue)
        callback.dispatch({
          data: {
            ...latest,
            callback: String(latest.callback || 'joinroom'),
          },
        })
      } catch (err) {
        console.warn('[tableSession] rejoin', err)
        const msg = err instanceof Error ? err.message : String(err ?? '')
        if (isRoomGoneMessage(msg)) {
          exitEndedRoom()
          return
        }
        const joinSnap = consumeLastJoinPayload()
        if (joinSnap) callback.dispatch({ data: joinSnap })
      }
    } else {
      const joinSnap = consumeLastJoinPayload()
      if (joinSnap) callback.dispatch({ data: joinSnap })
    }

    startCountdownTick()
    try {
      if (active) playDdzTableBgm()
    } catch {
      /* bgm 可选 */
    }
    eventBus.emit(LandlordEvents.STATE, { entered: true, kind: features.kind })
  },

  async leave(opts?: { keepSeat?: boolean }): Promise<void> {
    if (!active) return
    active = false
    stopCountdownTick()
    stopDdzTableBgm()
    offPush?.()
    offPush = null
    offReady?.()
    offReady = null
    queue?.setHandler(null)
    queue?.stop()
    queue = null
    callback = null
    disposeLandlordUiTimers()
    unloadSheets(DDZ_TABLE_SHEETS)
    audioManager.unloadPacks(['ddz-sfx', 'ddz-voice'])
    if (!opts?.keepSeat) {
      try {
        if (gameState.room.roomId && wsService.ready) {
          await wsService.request(ws().LEAVE, { roomId: gameState.room.roomId })
        }
      } catch {
        /* 离开失败不阻断 */
      }
    }
    resetLandlordState()
  },

  /** 窗口从最小化回来时补拉当前牌局，避免错过开局包后一直停在准备 */
  async resync(): Promise<void> {
    await resyncRoomAfterReconnect()
  },

  /** 只开托管，不切换取消。叫抢阶段服务端会拒绝，调用方仍可回大厅。 */
  async enableTrust(): Promise<void> {
    if (landlordState.trusted) return
    try {
      const data = await rpc(ws().TRUST, {})
      if (data.trusted === false) return
      if (data.callback) callback?.dispatch({ data })
      else landlordState.trusted = true
    } catch (err) {
      console.warn('[table] trust failed', err)
    }
  },

  async sendReady(): Promise<void> {
    if (landlordState.starting || landlordState.selfReady) return
    if (!landlordState.canStart) {
      landlordState.statusText = '人数未满，请稍候'
      return
    }
    // 点开始立刻清掉上一局出牌区，避免残留到发牌前
    clearPreviousRoundDesk()
    landlordState.starting = true
    landlordState.selfReady = true
    const self = gameState.room.players.find((p) => p.seat === 0)
    if (self) self.ready = true
    try {
      const data = await rpc(ws().START, {})
      const cb = String(data.callback || '').toLowerCase()
      const phase = String(data.phase || '').toLowerCase()
      const isStart =
        cb === 'startgame' ||
        Array.isArray(data.myCards) ||
        (phase !== '' && phase !== 'wait' && phase !== 'waitready')
      callback?.dispatch({
        data: { ...data, callback: isStart ? 'startgame' : 'waitstart' },
      })
    } catch (err) {
      landlordState.selfReady = false
      if (self) self.ready = false
      landlordState.statusText = err instanceof Error ? err.message : '开始失败，请重试'
      console.warn('[table] start failed', err)
    } finally {
      landlordState.starting = false
    }
  },

  async sendBid(score: number): Promise<void> {
    if (!features.hasBid) return
    const s = landlordState
    const prevBadge = s.actionBadgeBySide.self
    const prevTurn = s.bidTurn
    let optimistic: 'call' | 'rob' | 'noBid' | 'noRob' = 'noBid'
    if (score > 0) {
      optimistic = s.maxBid > 0 || s.hasCaller ? 'rob' : 'call'
    } else {
      optimistic = s.maxBid > 0 || s.hasCaller ? 'noRob' : 'noBid'
    }
    s.actionBadgeBySide = { ...s.actionBadgeBySide, self: optimistic }
    s.bidTurn = -1
    try {
      const data = await rpc(ws().BID, { score })
      if (data.callback) callback?.dispatch({ data })
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err ?? '')
      // 倒计时到点已代不叫，服务端可能已先记过这一手：不要把叫地主按钮重新打开
      if (/还没轮到|不是叫地主|已结束|已产生|尚未开始|地主已/.test(msg)) return
      s.actionBadgeBySide = { ...s.actionBadgeBySide, self: prevBadge }
      s.bidTurn = prevTurn
      console.warn('[table] bid failed', err)
    }
  },

  callOrRob(): void {
    const score = landlordState.maxBid > 0 ? landlordState.maxBid + 1 : 1
    void tableSession.sendBid(score)
  },

  passBid(): void {
    void tableSession.sendBid(0)
  },

  async sendDouble(score: number): Promise<void> {
    if (!features.hasDouble) return
    if (landlordState.doubleOpsLocked) return
    if (!needMyDouble()) return
    const phase = String(landlordState.phase).toLowerCase()
    if (phase !== PHASE.DOUBLE && phase !== 'double') return
    const s = score > 0 ? 2 : 0
    landlordState.doubleOpsLocked = true
    setFarmerDouble(landlordState.myIndex, s)
    landlordState.actionBadgeBySide = {
      ...landlordState.actionBadgeBySide,
      self: s > 0 ? 'double' : 'noDouble',
    }
    try {
      const data = await rpc(ws().DOUBLE, { score: s })
      if (data.callback) callback?.dispatch({ data })
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err || '')
      // 本地 tip 动画拖慢闹钟时，服务端可能已超时进 play：忽略即可，等推送对齐
      if (/不是加倍|加倍阶段已|已过加倍/.test(msg)) {
        landlordState.doubleOpsLocked = true
        return
      }
      landlordState.doubleOpsLocked = false
      delete landlordState.farmerDoubles[String(landlordState.myIndex)]
      delete landlordState.farmerDoubles[landlordState.myIndex]
      landlordState.actionBadgeBySide = { ...landlordState.actionBadgeBySide, self: '' }
      console.warn('[table] double failed', err)
    }
  },

  async sendPass(): Promise<void> {
    const data = await rpc(ws().PLAY, { cards: [] })
    if (data.callback) callback?.dispatch({ data })
  },

  async sendPlay(): Promise<boolean> {
    const cards = selectedCards()
    if (!cards.length) return false
    const last = landlordState.lastPlayed
    const mustFollow = mustFollowNow()
    const tip = playRejectTip(cards, last, mustFollow)
    if (tip) {
      flashHandTip(tip)
      return false
    }

    try {
      const data = await rpc(ws().PLAY, { cards: cardsToServer(cards) })
      clearSelected()
      if (data.callback) callback?.dispatch({ data })
      return true
    } catch (err) {
      const msg = err instanceof Error ? err.message : ''
      if (/压不过|打不过|管不住|大过上家|必须管/.test(msg)) flashHandTip('daBuGuoShangJia')
      else flashHandTip('chuPaiBuFuHe')
      console.warn('[table] play failed', err)
      return false
    }
  },

  hint(): void {
    const last = landlordState.lastPlayed
    const needBeat = mustFollowNow() ? last : []
    const pick = findHint(landlordState.hand, needBeat)
    landlordState.selectedCodes = pick.map((c: { code?: string }) => String(c.code || ''))
  },

  tryAutoFinishPlay(): Promise<boolean> {
    return runAutoFinishPlay()
  },

  async sendTrust(): Promise<void> {
    if (landlordState.trusted) {
      await tableSession.cancelTrust()
      return
    }
    if (!isPlayPhase(landlordState.phase)) {
      landlordState.statusText = '出牌阶段才能托管'
      return
    }
    try {
      const data = await rpc(ws().TRUST, {})
      if (data.trusted === false) {
        landlordState.statusText = '当前阶段不能托管'
        return
      }
      if (data.callback) {
        callback?.dispatch({ data })
      } else {
        landlordState.trusted = true
      }
    } catch (err) {
      landlordState.statusText = err instanceof Error ? err.message : '托管失败'
      console.warn('[table] trust failed', err)
    }
  },

  async cancelTrust(): Promise<void> {
    if (!landlordState.trusted) return
    try {
      const data = await rpc(ws().UNTRUST, {})
      if (data.callback) {
        callback?.dispatch({ data })
      } else {
        landlordState.trusted = false
      }
    } catch (err) {
      console.warn('[table] untrust failed', err)
    }
  },

  async requestDismiss(): Promise<void> {
    // 未开局：房主直接解散；已开局：全员投票
    if (isWaitPhase(landlordState.phase) && !roomHasStarted()) {
      if (!gameState.room.isMaster) {
        throw new Error('只有房主可以解散房间')
      }
      const data = await rpc(ws().DISMISS, {})
      if (data.callback) callback?.dispatch({ data })
      return
    }
    if (landlordState.dismissOpen) return
    const data = await rpc(ws().APPLY_DISMISS, {})
    const cb = String(data.callback || '').toLowerCase()
    if (cb === 'applydismiss' || cb === 'votedismiss') {
      applyDismissVote(data)
      return
    }
    if (data.callback) callback?.dispatch({ data })
  },

  async voteDismiss(vote: 1 | 2): Promise<void> {
    if (!landlordState.dismissOpen) return
    const revert = optimisticDismissVote(landlordState, vote)
    if (!revert) return
    try {
      const data = await rpc(ws().VOTE_DISMISS, { vote })
      if (data.callback) callback?.dispatch({ data })
    } catch (err) {
      revert()
      throw err
    }
  },

  bumpPlayCountdown(): void {
    syncPlayCountdownForce()
  },

  async sendChat(text: string): Promise<void> {
    const msg = String(text || '').trim()
    if (!msg) return
    try {
      const data = await rpc('Room.Chat', { text: msg })
      if (data.callback) {
        callback?.dispatch({ data })
        return
      }
      showChatBubble(landlordState.myIndex, data.text ?? data.content ?? msg)
    } catch (err) {
      console.warn('[table] chat failed', err)
      throw err
    }
  },
}

/** @deprecated 请用 tableSession；保留别名避免旧引用断裂 */
export const landlordSession = tableSession

export { currentTableFeatures }
