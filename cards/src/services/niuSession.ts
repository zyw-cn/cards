import { createActionQueue, type ActionQueue } from '@/core/actionQueue'
import { NIU_TABLE_AUDIO } from '@/config/assetManifest'
import { NiuCallback } from '@/logic/niu/NiuCallback'
import { CARDS_PER_PLAYER, PHASE, TIMEOUT, WS_ACTION } from '@/logic/niu/rules/room'
import { playNiuBgm, playNiuSfx, stopNiuBgm } from '@/logic/niu/niuAudio'
import { audioManager } from '@/services/audioManager'
import { gameState, roomHasStarted } from '@/services/gameState'
import { wsService } from '@/services/gameSocket'
import {
  applyNiuDismissVote,
  flashNiuTip,
  niuMe,
  niuState,
  resetNiuState,
} from '@/services/niuState'
import { consumeLastJoinPayload, joinRoomWs } from '@/services/roomApi'
import { leaveToHallIntent } from '@/services/roomResume'
import { setWsRoomResyncing } from '@/services/wsUi'
import { optimisticDismissVote } from '@/logic/shared/dismiss'

let queue: ActionQueue | null = null
let callback: NiuCallback | null = null
let offPush: (() => void) | null = null
let offReady: (() => void) | null = null
let countdownTimer: ReturnType<typeof setInterval> | null = null
let rollTimer: ReturnType<typeof setInterval> | null = null
let active = false
let resyncing = false
let sending = false

function stopCountdown(): void {
  if (countdownTimer) {
    clearInterval(countdownTimer)
    countdownTimer = null
  }
}

function stopRoll(): void {
  if (rollTimer) {
    clearInterval(rollTimer)
    rollTimer = null
  }
}

/** 叫分倒计时走完仍未叫，视为不抢 */
function autoPassGrab(): void {
  if (String(niuState.phase || '').toLowerCase() !== PHASE.GRAB) return
  const me = niuMe()
  if (!me || me.qz >= 0) return
  const free = String(niuState.modeKey || '') === 'free'
  if (!free && Number(niuState.currentGrabId) !== Number(niuState.myUid)) return
  void niuSession.sendGrab(0)
}

function startCountdown(): void {
  stopCountdown()
  countdownTimer = setInterval(() => {
    if (!active) return
    if (niuState.dismissOpen && niuState.dismissLeft > 0) niuState.dismissLeft -= 1
    if (niuState.countdown > 0) {
      niuState.countdown -= 1
      if (niuState.countdown === 0) autoPassGrab()
    }
  }, 1000)
}

async function rpc(route: string, body: Record<string, unknown> = {}): Promise<Record<string, unknown>> {
  const res = await wsService.request(route, {
    roomId: gameState.room.roomId,
    ...body,
  })
  return (res.data || {}) as Record<string, unknown>
}

async function resyncRoomAfterReconnect(): Promise<void> {
  if (!active || resyncing || !callback) return
  const roomId = gameState.room.roomId
  if (!roomId || !wsService.ready) return
  resyncing = true
  setWsRoomResyncing(true)
  try {
    const latest = await joinRoomWs(roomId)
    callback.dispatch({
      data: { ...latest, callback: String(latest.callback || 'joinroom') },
    })
  } catch (err) {
    console.warn('[niuSession] reconnect resync', err)
  } finally {
    resyncing = false
    setWsRoomResyncing(false)
  }
}

export const niuSession = {
  get queue(): ActionQueue | null {
    return queue
  },
  get active(): boolean {
    return active
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
    offReady = wsService.onReady(() => {
      if (!active || !callback) return
      // 断线重连，或进房时曾用本地快照：鉴权成功后补 Join 拉齐座位
      void resyncRoomAfterReconnect()
    })
    if (active) return
    active = true
    resetNiuState()
    niuState.myUid = Number(gameState.user.uid) || 0
    if (!gameState.room.gameType) gameState.room.gameType = 2
    if (!gameState.room.playName) gameState.room.playName = '斗牛'
    if (gameState.room.myIndex != null) niuState.myIndex = Number(gameState.room.myIndex)

    try {
      await audioManager.preloadPacks(NIU_TABLE_AUDIO)
    } catch (err) {
      console.warn('[niuSession] audio', err)
    }

    queue = createActionQueue({ gapMs: 40 })
    queue.setHandler(async (action) => {
      if (action.type === 'deal') {
        niuState.dealing = true
        await new Promise((r) => setTimeout(r, 480))
        niuState.dealing = false
      } else if (action.type === 'banker-roll') {
        stopRoll()
        const ids = niuState.rollCandidates
        if (!ids.length) return
        let i = 0
        await new Promise<void>((resolve) => {
          rollTimer = setInterval(() => {
            niuState.rollHighlightId = ids[i % ids.length]
            i += 1
            if (i >= Math.max(ids.length * 6, 18)) {
              stopRoll()
              niuState.rollHighlightId = niuState.rollWinnerId || ids[0]
              resolve()
            }
          }, 100)
        })
      }
    })
    callback = new NiuCallback({
      queue,
      onDissolved: () => {
        leaveToHallIntent()
        void niuSession.leave()
      },
    })

    try {
      await wsService.connect()
    } catch (err) {
      console.warn('[niuSession] ws connect', err)
    }

    const roomId = gameState.room.roomId
    const applyJoin = (latest: Record<string, unknown>) => {
      callback?.dispatch({
        data: { ...latest, callback: String(latest.callback || 'joinroom') },
      })
    }
    if (roomId) {
      try {
        if (!wsService.ready) await wsService.connect()
        if (wsService.ready) {
          applyJoin(await joinRoomWs(roomId))
        } else {
          const snap = consumeLastJoinPayload()
          if (snap) applyJoin(snap)
        }
      } catch (err) {
        console.warn('[niuSession] rejoin', err)
        const snap = consumeLastJoinPayload()
        if (snap) applyJoin(snap)
      }
    } else {
      const snap = consumeLastJoinPayload()
      if (snap) applyJoin(snap)
    }
    startCountdown()
    try {
      if (active) playNiuBgm()
    } catch {
      /* optional */
    }
  },

  async leave(opts?: { keepSeat?: boolean }): Promise<void> {
    if (!active) return
    active = false
    stopCountdown()
    stopRoll()
    stopNiuBgm()
    offPush?.()
    offPush = null
    offReady?.()
    offReady = null
    queue?.setHandler(null)
    queue?.stop()
    queue = null
    callback = null
    try {
      audioManager.unloadPacks(['niu-sfx'])
    } catch {
      /* ignore */
    }
    if (!opts?.keepSeat) {
      try {
        if (gameState.room.roomId && wsService.ready) {
          await wsService.request(WS_ACTION.LEAVE, { roomId: gameState.room.roomId })
        }
      } catch {
        /* 离开失败不阻断 */
      }
    }
    resetNiuState()
  },

  /** 牌局中返回大厅：发 Leave，服务端改托管并保留座位 */
  async enableTrust(): Promise<void> {
    if (niuState.trusted) return
    try {
      const data = await rpc(WS_ACTION.LEAVE, {})
      if (data.callback) callback?.dispatch({ data })
      else if (data.trusted) niuState.trusted = true
    } catch (err) {
      console.warn('[niu] trust via leave', err)
    }
  },

  async sendReady(): Promise<void> {
    if (niuState.starting || niuState.selfReady || sending) return
    // 已在叫分/下注/亮牌：禁止再点准备把状态打乱
    const phase = String(niuState.phase || '').toLowerCase()
    if (phase === PHASE.GRAB || phase === PHASE.BET || phase === PHASE.SHOW) {
      flashNiuTip('牌局已开始')
      return
    }
    if (niuState.phase === PHASE.WAIT && !niuState.canStart) {
      flashNiuTip(`人数未满（${niuState.players.length}/${niuState.seats}）`)
      return
    }
    sending = true
    niuState.starting = true
    niuState.selfReady = true
    // 再来一局：准备倒计时从 60 秒重新走，不接着结算时已经走掉的时间
    if (phase === PHASE.SETTLE || phase === PHASE.WAIT) {
      niuState.countdown = TIMEOUT.WAIT_START
    }
    // 点准备/再来一局：立刻清掉上一局牛几 tip（全员状态随 waitstart/startgame 再同步）
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
    try {
      const data = await rpc(WS_ACTION.START, {})
      const cb = String(data.callback || '').toLowerCase()
      const nextPhase = String(data.phase || '').toLowerCase()
      const started =
        cb === 'startgame' ||
        nextPhase === PHASE.GRAB ||
        nextPhase === PHASE.BET ||
        nextPhase === PHASE.SHOW
      // 叫分/下注/亮牌中，迟到的 waitstart 不能把牌局打回准备
      const now = String(niuState.phase || '').toLowerCase()
      const playing = now === PHASE.GRAB || now === PHASE.BET || now === PHASE.SHOW
      if (!started && playing) return
      if (!started) niuState.countdown = TIMEOUT.WAIT_START
      callback?.dispatch({
        data: { ...data, callback: started ? 'startgame' : cb || 'waitstart' },
      })
    } catch (err) {
      niuState.selfReady = false
      flashNiuTip(err instanceof Error ? err.message : '准备失败')
    } finally {
      niuState.starting = false
      sending = false
    }
  },

  async sendGrab(multiple: number): Promise<void> {
    if (String(niuState.phase).toLowerCase() !== PHASE.GRAB) return
    const free = String(niuState.modeKey || '') === 'free'
    if (!free && Number(niuState.currentGrabId) !== Number(niuState.myUid)) return
    const me = niuMe()
    if (!me || me.qz >= 0 || sending) return
    // 乐观占位：立刻隐藏操作钮，避免连点不抢/倍数
    me.qz = Number(multiple)
    sending = true
    try {
      const data = await rpc(WS_ACTION.GRAB, { multiple: Number(multiple), score: Number(multiple) })
      playNiuSfx(Number(multiple) > 0 ? 'rob' : 'notRob')
      if (data.callback) callback?.dispatch({ data })
    } catch (err) {
      const msg = err instanceof Error ? err.message : '叫分失败'
      if (/已完成抢庄|已抢庄/.test(msg)) {
        if (me.qz < 0) me.qz = 0
        return
      }
      me.qz = -1
      flashNiuTip(msg)
    } finally {
      sending = false
    }
  },

  async sendBet(multiple: number): Promise<void> {
    if (niuState.phase !== PHASE.BET || sending) return
    if (niuState.bankerId === niuState.myUid) return
    const me = niuMe()
    if (!me || me.bet >= 0) return
    sending = true
    try {
      const data = await rpc(WS_ACTION.BET, { multiple: Number(multiple) })
      if (data.callback) callback?.dispatch({ data })
    } catch (err) {
      flashNiuTip(err instanceof Error ? err.message : '下注失败')
    } finally {
      sending = false
    }
  },

  async sendLook(): Promise<void> {
    if (niuState.phase !== PHASE.SHOW || sending) return
    const me = niuMe()
    if (!me || me.shown || me.looked) return
    if (niuState.myCards.length < CARDS_PER_PLAYER) {
      flashNiuTip('手牌未就绪')
      return
    }
    me.looked = true
    me.cards = niuState.myCards.slice()
    niuState.statusText = '请亮牌'
    playNiuSfx('open')
  },

  async sendShow(): Promise<void> {
    if (niuState.phase !== PHASE.SHOW || sending) return
    const me = niuMe()
    if (!me || me.shown) return
    sending = true
    niuState.statusText = '正在亮牌'
    try {
      const data = await rpc(WS_ACTION.SHOW, {})
      if (data.callback) callback?.dispatch({ data })
    } catch (err) {
      const msg = err instanceof Error ? err.message : '亮牌失败'
      if (!/不是亮牌阶段|已结束|已开牌/.test(msg)) flashNiuTip(msg)
    } finally {
      sending = false
    }
  },

  async requestDismiss(): Promise<void> {
    const phase = String(niuState.phase || '').toLowerCase()
    const waiting = !phase || phase === PHASE.WAIT || phase === PHASE.SETTLE
    if (waiting && !roomHasStarted()) {
      if (!gameState.room.isMaster) throw new Error('只有房主可以解散房间')
      const data = await rpc(WS_ACTION.DISMISS, {})
      if (data.callback) callback?.dispatch({ data })
      return
    }
    if (niuState.dismissOpen) return
    const data = await rpc(WS_ACTION.APPLY_DISMISS, {})
    const cb = String(data.callback || '').toLowerCase()
    if (cb === 'applydismiss' || cb === 'votedismiss') {
      applyNiuDismissVote(data)
      return
    }
    if (data.callback) callback?.dispatch({ data })
  },

  async voteDismiss(vote: 1 | 2): Promise<void> {
    if (!niuState.dismissOpen) return
    const revert = optimisticDismissVote(niuState, vote)
    if (!revert) return
    try {
      const data = await rpc(WS_ACTION.VOTE_DISMISS, { vote })
      if (data.callback) callback?.dispatch({ data })
    } catch (err) {
      revert()
      flashNiuTip(err instanceof Error ? err.message : '投票失败')
    }
  },
}
