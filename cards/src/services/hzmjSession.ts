import { createActionQueue, type ActionQueue } from '@/core/actionQueue'
import { HzmjCallback } from '@/logic/hzmj/HzmjCallback'
import {
  clearHzmjCountdownClock,
  setHzmjCountdownExpireHandler,
  startHzmjCountdownTick,
} from '@/logic/hzmj/playCountdown'
import { KING_TILE } from '@/logic/hzmj/rules/constants'
import { canWin, SHOW_OP } from '@/logic/hzmj/rules/typed'
import { PHASE, WS_ACTION } from '@/logic/hzmj/rules/room'
import { playHzmjBgm, playHzmjDiscard, playHzmjOp, stopHzmjBgm } from '@/logic/hzmj/hzmjAudio'
import { HZMJ_TABLE_AUDIO } from '@/config/assetManifest'
import { audioManager } from '@/services/audioManager'
import { gameState, roomHasStarted } from '@/services/gameState'
import { wsService } from '@/services/gameSocket'
import {
  applyHzmjDismissVote,
  clearPreviousRoundDesk,
  flashHzmjTip,
  hzmjState,
  resetHzmjState,
} from '@/services/hzmjState'
import { consumeLastJoinPayload, hasValidRoomId, joinRoomWs } from '@/services/roomApi'
import { leaveToHallIntent } from '@/services/roomResume'
import { optimisticDismissVote } from '@/logic/shared/dismiss'
import { setWsRoomResyncing } from '@/services/wsUi'

let queue: ActionQueue | null = null
let callback: HzmjCallback | null = null
let offPush: (() => void) | null = null
let offReady: (() => void) | null = null
let active = false
let resyncing = false
let autoBusy = false

function isPlayPhase(): boolean {
  const p = String(hzmjState.phase).toLowerCase()
  return p === PHASE.PLAY || p === 'play' || p === 'playing' || p === 'waiting'
}

function pickAutoDiscardTile(): number {
  const hand = hzmjState.hand
  const sel = hzmjState.selectedTileIdx
  if (sel != null && sel >= 0 && hand[sel] > 0 && hand[sel] !== KING_TILE) return hand[sel]
  for (const t of hand) {
    if (t > 0 && t !== KING_TILE) return t
  }
  return hand[hand.length - 1] || 0
}

/**
 * 倒计时到 0：本人可操作则代打（可胡则胡 / 应答则过 / 出牌则打）。
 * 托管交给服务端约 2s 代打；与服务端 15s 超时幂等，后到的会因「没轮到」失败。
 */
async function autoActWhenTimeUp(): Promise<void> {
  if (!active || autoBusy || hzmjState.dismissOpen || hzmjState.dealing) return
  if (!isPlayPhase() || hzmjState.settleResult || hzmjState.showResult) return
  if (hzmjState.trusted) return

  const ops = hzmjState.notifyOps || []
  const needClaim = ops.length > 0
  const myTurn =
    Number(hzmjState.turnIndex) === Number(hzmjState.myIndex) && !hzmjState.pendingDiscard
  if (!needClaim && !myTurn) return

  if (needClaim) {
    autoBusy = true
    try {
      if (ops.includes(SHOW_OP.WIN)) {
        await hzmjSession.sendShowOp(SHOW_OP.WIN, 0, true)
      } else {
        await hzmjSession.sendShowOp(SHOW_OP.PASS, 0, true)
      }
    } finally {
      autoBusy = false
    }
    return
  }

  const hand = hzmjState.hand
  if (hand.length % 3 !== 2) return

  autoBusy = true
  try {
    if (canWin(hand, hzmjState.kingTiles)) {
      await hzmjSession.sendShowOp(SHOW_OP.WIN, 0, true)
      return
    }
    const tile = pickAutoDiscardTile()
    if (tile > 0) await hzmjSession.discard(tile, true)
  } finally {
    autoBusy = false
  }
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
      data: {
        ...latest,
        callback: String(latest.callback || 'joinroom'),
      },
    })
  } catch (err) {
    console.warn('[hzmjSession] reconnect resync', err)
  } finally {
    resyncing = false
    setWsRoomResyncing(false)
  }
}

/**
 * 红中麻将会话：规则/协议独立，桌面 UI 用 cards 自有壳（非 game Vue）。
 */
export const hzmjSession = {
  get queue(): ActionQueue | null {
    return queue
  },

  get active(): boolean {
    return active
  },

  async enter(): Promise<void> {
    if (!hasValidRoomId()) {
      throw new Error('缺少房间号，无法进入牌桌')
    }
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
    resetHzmjState()
    if (!gameState.room.gameType) gameState.room.gameType = 7
    if (!gameState.room.playName) gameState.room.playName = '红中麻将'

    try {
      await audioManager.preloadPacks(HZMJ_TABLE_AUDIO)
    } catch (err) {
      console.warn('[hzmjSession] audio', err)
    }

    try {
      await wsService.connect()
    } catch (err) {
      console.warn('[hzmjSession] ws connect', err)
    }

    queue = createActionQueue({ gapMs: 60 })
    callback = new HzmjCallback({
      queue,
      onDissolved: () => {
        leaveToHallIntent()
        gameState.currentScreen = 'LOBBY'
        void hzmjSession.leave()
      },
    })

    if (gameState.room.myIndex != null) {
      hzmjState.myIndex = Number(gameState.room.myIndex)
      hzmjState.myIndexLocked = true
    }

    const roomId = String(gameState.room.roomId || '').trim()
    if (roomId && wsService.ready) {
      try {
        const latest = await joinRoomWs(roomId)
        callback.dispatch({
          data: {
            ...latest,
            callback: String(latest.callback || 'joinroom'),
          },
        })
      } catch (err) {
        console.warn('[hzmjSession] rejoin', err)
        active = false
        queue?.stop()
        queue = null
        callback = null
        throw err instanceof Error ? err : new Error('加入房间失败')
      }
    } else {
      const joinSnap = consumeLastJoinPayload()
      if (!joinSnap || !hasValidRoomId(joinSnap.roomId ?? joinSnap.room_num ?? roomId)) {
        active = false
        queue?.stop()
        queue = null
        callback = null
        throw new Error('缺少房间号，无法进入牌桌')
      }
      callback.dispatch({ data: joinSnap })
    }

    startHzmjCountdownTick()
    setHzmjCountdownExpireHandler(() => {
      void autoActWhenTimeUp()
    })
    try {
      if (active) playHzmjBgm()
    } catch {
      /* bgm 可选 */
    }
  },

  async leave(opts?: { keepSeat?: boolean }): Promise<void> {
    if (!active) return
    active = false
    setHzmjCountdownExpireHandler(null)
    clearHzmjCountdownClock()
    stopHzmjBgm()
    offPush?.()
    offPush = null
    offReady?.()
    offReady = null
    queue?.setHandler(null)
    queue?.stop()
    queue = null
    callback = null
    try {
      audioManager.unloadPacks(['hzmj-sfx', 'hzmj-voice-female', 'hzmj-voice-male'])
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
    resetHzmjState()
  },

  async enableTrust(): Promise<void> {
    if (hzmjState.trusted) return
    try {
      const data = await rpc(WS_ACTION.TRUST, {})
      if (data.trusted === false) return
      if (data.callback) callback?.dispatch({ data })
      else hzmjState.trusted = true
    } catch (err) {
      console.warn('[hzmj] trust', err)
    }
  },

  async sendReady(): Promise<void> {
    if (hzmjState.starting || hzmjState.selfReady) return
    if (!hzmjState.canStart) {
      hzmjState.statusText = '人数未满，请稍候'
      return
    }
    // 点开始立刻清掉上一局弃牌/副露，避免残留到发牌前
    clearPreviousRoundDesk()
    hzmjState.starting = true
    hzmjState.selfReady = true
    const self = gameState.room.players.find((p) => p.seat === 0)
    if (self) self.ready = true
    try {
      const data = await rpc(WS_ACTION.START, {})
      const cb = String(data.callback || '').toLowerCase()
      const phase = String(data.phase || '').toLowerCase()
      const isGrab = cb === 'grab' || phase === 'grab'
      const isStart =
        cb === 'startgame' ||
        Array.isArray(data.myTiles) ||
        Array.isArray(data.handTiles) ||
        (phase !== '' && phase !== 'wait' && phase !== 'waitready' && phase !== 'grab')
      const nextCb = isGrab ? 'grab' : isStart ? 'startgame' : 'waitstart'
      callback?.dispatch({
        data: { ...data, callback: nextCb },
      })
    } catch (err) {
      const msg = err instanceof Error ? err.message : '开始失败，请重试'
      // 别人已把房间推进到抢庄，本端错过推送：重进房拿 resume
      if (/抢庄/.test(msg)) {
        try {
          const roomId = gameState.room.roomId
          if (roomId) {
            const latest = await joinRoomWs(roomId)
            callback?.dispatch({
              data: {
                ...latest,
                callback: String(latest.callback || 'joinroom'),
              },
            })
            return
          }
        } catch {
          /* fall through */
        }
      }
      hzmjState.selfReady = false
      if (self) self.ready = false
      hzmjState.statusText = msg
      console.warn('[hzmj] start failed', err)
    } finally {
      hzmjState.starting = false
    }
  },

  async sendGrab(multiple: number): Promise<void> {
    const n = Number(multiple)
    if (![0, 1, 2, 3].includes(n)) return
    if (String(hzmjState.phase).toLowerCase() !== 'grab') return
    if (hzmjState.myGrabMultiple >= 0) return
    hzmjState.myGrabMultiple = n
    hzmjState.grabsBySeat = { ...hzmjState.grabsBySeat, [hzmjState.myIndex]: n }
    hzmjState.statusText = '等待其他玩家抢庄'
    try {
      const data = await rpc(WS_ACTION.GRAB, { multiple: n })
      if (data.callback) callback?.dispatch({ data })
    } catch (err) {
      hzmjState.myGrabMultiple = -1
      const next = { ...hzmjState.grabsBySeat }
      delete next[hzmjState.myIndex]
      hzmjState.grabsBySeat = next
      hzmjState.statusText = '请选择抢庄倍数'
      flashHzmjTip(err instanceof Error ? err.message : '抢庄失败')
      console.warn('[hzmj] grab', err)
    }
  },

  async discard(tile: number, quiet = false): Promise<void> {
    const t = Number(tile)
    if (!Number.isFinite(t) || t <= 0) return
    try {
      const data = await rpc(WS_ACTION.OP, { op: 'discard', tile: t })
      // 音效由推送 HzmjCallback.onOp 统一播，避免与回包重复
      if (data.callback) callback?.dispatch({ data })
      else playHzmjDiscard(t)
    } catch (err) {
      if (!quiet) flashHzmjTip(err instanceof Error ? err.message : '出牌失败')
      console.warn('[hzmj] discard', err)
    }
  },

  async sendShowOp(showOp: number, tile = 0, quiet = false): Promise<void> {
    const map: Record<number, string> = {
      [SHOW_OP.PASS]: 'pass',
      [SHOW_OP.PONG]: 'pong',
      [SHOW_OP.KONG]: 'kong',
      [SHOW_OP.WIN]: 'win',
    }
    const op = map[Number(showOp)]
    if (!op) return
    try {
      const body: Record<string, unknown> = { op }
      if (tile > 0) body.tile = tile
      const data = await rpc(WS_ACTION.OP, body)
      hzmjState.notifyOps = []
      if (data.callback) callback?.dispatch({ data })
      else {
        const opVoice: Record<number, string> = {
          [SHOW_OP.PONG]: 'pong',
          [SHOW_OP.KONG]: 'kong',
          [SHOW_OP.WIN]: 'win',
          [SHOW_OP.CHOW]: 'chow',
        }
        const voice = opVoice[Number(showOp)]
        if (voice) playHzmjOp(voice)
      }
    } catch (err) {
      if (!quiet) flashHzmjTip(err instanceof Error ? err.message : '操作失败')
      console.warn('[hzmj] op', err)
    }
  },

  async sendTrust(): Promise<void> {
    if (hzmjState.trusted) {
      await hzmjSession.cancelTrust()
      return
    }
    try {
      const data = await rpc(WS_ACTION.TRUST, {})
      if (data.callback) callback?.dispatch({ data })
      else hzmjState.trusted = true
    } catch (err) {
      console.warn('[hzmj] trust', err)
    }
  },

  async cancelTrust(): Promise<void> {
    if (!hzmjState.trusted) return
    try {
      const data = await rpc(WS_ACTION.UNTRUST, {})
      if (data.callback) callback?.dispatch({ data })
      else hzmjState.trusted = false
    } catch (err) {
      console.warn('[hzmj] untrust', err)
    }
  },

  async requestDismiss(): Promise<void> {
    const waiting = !hzmjState.phase || String(hzmjState.phase).toLowerCase() === PHASE.WAIT_READY
      || String(hzmjState.phase).toLowerCase() === 'wait'
      || String(hzmjState.phase).toLowerCase() === 'waitready'
    if (waiting && !roomHasStarted()) {
      if (!gameState.room.isMaster) throw new Error('只有房主可以解散房间')
      const data = await rpc(WS_ACTION.DISMISS, {})
      if (data.callback) callback?.dispatch({ data })
      return
    }
    if (hzmjState.dismissOpen) return
    const data = await rpc(WS_ACTION.APPLY_DISMISS, {})
    const cb = String(data.callback || '').toLowerCase()
    if (cb === 'applydismiss' || cb === 'votedismiss') {
      applyHzmjDismissVote(data)
      return
    }
    if (data.callback) callback?.dispatch({ data })
  },

  async voteDismiss(vote: 1 | 2): Promise<void> {
    if (!hzmjState.dismissOpen) return
    const revert = optimisticDismissVote(hzmjState, vote)
    if (!revert) return
    try {
      const data = await rpc(WS_ACTION.VOTE_DISMISS, { vote })
      if (data.callback) callback?.dispatch({ data })
    } catch (err) {
      revert()
      throw err
    }
  },

  async sendChat(text: string): Promise<void> {
    const msg = String(text || '').trim()
    if (!msg) return
    try {
      const data = await rpc('Room.Chat', { text: msg })
      if (data.callback) callback?.dispatch({ data })
    } catch (err) {
      console.warn('[hzmj] chat failed', err)
      throw err
    }
  },
}
