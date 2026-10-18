import { createActionQueue, type ActionQueue } from '@/core/actionQueue'
import { FLOWER_TABLE_AUDIO } from '@/config/assetManifest'
import { FlowerCallback } from '@/logic/flower/FlowerCallback'
import { PHASE, WS_ACTION } from '@/logic/flower/rules/room'
import { playFlowerBgm, playFlowerSfx, stopFlowerBgm } from '@/logic/flower/flowerAudio'
import { audioManager } from '@/services/audioManager'
import { gameState, roomHasStarted } from '@/services/gameState'
import { wsService } from '@/services/gameSocket'
import {
  applyFlowerDismissVote,
  flashFlowerTip,
  flowerMe,
  flowerState,
  resetFlowerState,
} from '@/services/flowerState'
import { consumeLastJoinPayload, joinRoomWs } from '@/services/roomApi'
import { leaveToHallIntent } from '@/services/roomResume'
import { setWsRoomResyncing } from '@/services/wsUi'
import { optimisticDismissVote } from '@/logic/shared/dismiss'

let queue: ActionQueue | null = null
let callback: FlowerCallback | null = null
let offPush: (() => void) | null = null
let offReady: (() => void) | null = null
let countdownTimer: ReturnType<typeof setInterval> | null = null
const FLOWER_CLOCK = '__qipaiFlowerClock'
let active = false
let resyncing = false
let sending = false

function clockHolder(): { id: ReturnType<typeof setInterval> | null } {
  const g = globalThis as typeof globalThis & {
    [FLOWER_CLOCK]?: { id: ReturnType<typeof setInterval> | null }
  }
  if (!g[FLOWER_CLOCK]) g[FLOWER_CLOCK] = { id: null }
  return g[FLOWER_CLOCK]
}

function stopCountdown(): void {
  const holder = clockHolder()
  if (holder.id) clearInterval(holder.id)
  if (countdownTimer && countdownTimer !== holder.id) clearInterval(countdownTimer)
  holder.id = null
  countdownTimer = null
}

function startCountdown(): void {
  stopCountdown()
  const timer = setInterval(() => {
    if (!active) return
    if (flowerState.dismissOpen && flowerState.dismissLeft > 0) flowerState.dismissLeft -= 1
    const until = flowerState.countdownUntil
    if (!until) {
      if (flowerState.countdown !== 0) flowerState.countdown = 0
      return
    }
    const left = Math.max(0, Math.ceil((until - Date.now()) / 1000))
    if (flowerState.countdown !== left) flowerState.countdown = left
    if (left <= 0) flowerState.countdownUntil = 0
  }, 200)
  countdownTimer = timer
  clockHolder().id = timer
}

function isWait(phase: string): boolean {
  const p = String(phase || '').toLowerCase()
  return !p || p === PHASE.WAIT || p === 'wait'
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
    console.warn('[flowerSession] reconnect resync', err)
  } finally {
    resyncing = false
    setWsRoomResyncing(false)
  }
}

export const flowerSession = {
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
    offReady = wsService.onReady(({ reconnect }) => {
      if (!reconnect || !active) return
      void resyncRoomAfterReconnect()
    })
    if (active) return
    active = true
    resetFlowerState()
    flowerState.myUid = Number(gameState.user.uid) || 0
    if (!gameState.room.gameType) gameState.room.gameType = 3
    if (!gameState.room.playName) gameState.room.playName = '炸金花'
    if (gameState.room.myIndex != null) flowerState.myIndex = Number(gameState.room.myIndex)

    try {
      await audioManager.preloadPacks(FLOWER_TABLE_AUDIO)
    } catch (err) {
      console.warn('[flowerSession] audio', err)
    }
    try {
      await wsService.connect()
    } catch (err) {
      console.warn('[flowerSession] ws connect', err)
    }

    queue = createActionQueue({ gapMs: 40 })
    queue.setHandler(async (action) => {
      if (action.type === 'deal') {
        await new Promise((r) => setTimeout(r, 700))
      }
    })
    callback = new FlowerCallback({
      queue,
      onDissolved: () => {
        leaveToHallIntent()
        void flowerSession.leave()
      },
    })

    const roomId = gameState.room.roomId
    if (roomId && wsService.ready) {
      try {
        const latest = await joinRoomWs(roomId)
        callback.dispatch({
          data: { ...latest, callback: String(latest.callback || 'joinroom') },
        })
      } catch (err) {
        console.warn('[flowerSession] rejoin', err)
        const snap = consumeLastJoinPayload()
        if (snap) callback.dispatch({ data: snap })
      }
    } else {
      const snap = consumeLastJoinPayload()
      if (snap) callback.dispatch({ data: snap })
    }
    startCountdown()
    try {
      if (active) playFlowerBgm()
    } catch {
      /* optional */
    }
  },

  async leave(opts?: { keepSeat?: boolean }): Promise<void> {
    if (!active) return
    active = false
    stopCountdown()
    stopFlowerBgm()
    offPush?.()
    offPush = null
    offReady?.()
    offReady = null
    queue?.setHandler(null)
    queue?.stop()
    queue = null
    callback = null
    try {
      audioManager.unloadPacks(['flower-sfx'])
    } catch {
      /* ignore */
    }
    if (!opts?.keepSeat) {
      try {
        if (gameState.room.roomId && wsService.ready) {
          await wsService.request(WS_ACTION.LEAVE, { roomId: gameState.room.roomId })
        }
      } catch {
        /* ignore */
      }
    }
    resetFlowerState()
  },

  /** 牌局中返回大厅：发 Leave，服务端改托管并保留座位 */
  async enableTrust(): Promise<void> {
    if (flowerState.trusted) return
    try {
      const data = await rpc(WS_ACTION.LEAVE, {})
      if (data.callback) callback?.dispatch({ data })
      else if (data.trusted) flowerState.trusted = true
    } catch (err) {
      console.warn('[flower] trust via leave', err)
    }
  },

  async sendReady(): Promise<void> {
    if (flowerState.starting || flowerState.selfReady || sending) return
    if (flowerState.phase === PHASE.WAIT && !flowerState.canStart) {
      flashFlowerTip(`人数未满（${flowerState.players.length}/${flowerState.seats}）`)
      return
    }
    sending = true
    flowerState.starting = true
    flowerState.selfReady = true
    flowerState.myPattern = ''
    flowerState.selfDelta = 0
    flowerState.players.forEach((p) => {
      p.pattern = ''
      p.lastDelta = 0
    })
    try {
      const data = await rpc(WS_ACTION.START, {})
      const cb = String(data.callback || '').toLowerCase()
      callback?.dispatch({
        data: { ...data, callback: cb === 'startgame' ? 'startgame' : 'waitstart' },
      })
    } catch (err) {
      flowerState.selfReady = false
      flashFlowerTip(err instanceof Error ? err.message : '准备失败')
    } finally {
      flowerState.starting = false
      sending = false
    }
  },

  async sendLook(): Promise<void> {
    const me = flowerMe()
    if (!me || me.looked || me.folded || sending) return
    if (flowerState.phase !== PHASE.BET) return
    sending = true
    try {
      const data = await rpc(WS_ACTION.LOOK, {})
      if (data.callback) callback?.dispatch({ data })
      else playFlowerSfx('look')
    } catch (err) {
      flashFlowerTip(err instanceof Error ? err.message : '看牌失败')
    } finally {
      sending = false
    }
  },

  async sendCall(): Promise<void> {
    if (!canAct() || sending) return
    sending = true
    try {
      const data = await rpc(WS_ACTION.CALL, {})
      if (data.callback) callback?.dispatch({ data })
    } catch (err) {
      flashFlowerTip(err instanceof Error ? err.message : '跟注失败')
    } finally {
      sending = false
    }
  },

  async sendRaise(amount: number): Promise<void> {
    if (!canAct() || sending) return
    sending = true
    try {
      const data = await rpc(WS_ACTION.RAISE, { amount: Number(amount) })
      if (data.callback) callback?.dispatch({ data })
    } catch (err) {
      flashFlowerTip(err instanceof Error ? err.message : '加注失败')
    } finally {
      sending = false
    }
  },

  async sendFold(): Promise<void> {
    if (!canAct() || sending) return
    sending = true
    try {
      const data = await rpc(WS_ACTION.FOLD, {})
      if (data.callback) callback?.dispatch({ data })
    } catch (err) {
      flashFlowerTip(err instanceof Error ? err.message : '弃牌失败')
    } finally {
      sending = false
    }
  },

  async sendCompare(targetPlayerId: number): Promise<void> {
    if (!canAct() || sending || targetPlayerId <= 0) return
    sending = true
    flowerState.compareOpen = false
    try {
      const data = await rpc(WS_ACTION.COMPARE, { targetPlayerId })
      if (data.callback) callback?.dispatch({ data })
      else playFlowerSfx('compare')
    } catch (err) {
      flashFlowerTip(err instanceof Error ? err.message : '比牌失败')
    } finally {
      sending = false
    }
  },

  async requestDismiss(): Promise<void> {
    if (isWait(flowerState.phase) && !roomHasStarted()) {
      if (!gameState.room.isMaster) throw new Error('只有房主可以解散房间')
      const data = await rpc(WS_ACTION.DISMISS, {})
      if (data.callback) callback?.dispatch({ data })
      return
    }
    if (flowerState.dismissOpen) return
    const data = await rpc(WS_ACTION.APPLY_DISMISS, {})
    const cb = String(data.callback || '').toLowerCase()
    if (cb === 'applydismiss' || cb === 'votedismiss') {
      applyFlowerDismissVote(data)
      return
    }
    if (data.callback) callback?.dispatch({ data })
  },

  async voteDismiss(vote: 1 | 2): Promise<void> {
    if (!flowerState.dismissOpen) return
    const revert = optimisticDismissVote(flowerState, vote)
    if (!revert) return
    try {
      const data = await rpc(WS_ACTION.VOTE_DISMISS, { vote })
      if (data.callback) callback?.dispatch({ data })
    } catch (err) {
      revert()
      flashFlowerTip(err instanceof Error ? err.message : '投票失败')
    }
  },
}

function canAct(): boolean {
  if (flowerState.phase !== PHASE.BET || flowerState.dealing) return false
  const me = flowerMe()
  return !!me && !me.folded && me.seat === flowerState.turnSeat
}
