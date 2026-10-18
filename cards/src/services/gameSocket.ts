import {
  bodyToJson,
  decodeFrame,
  encodeFrame,
  MessageType,
} from '@/services/wsBinary'
import {
  applyUserProfile,
  gameState,
  resolveToken,
} from '@/services/gameState'
import { forceLogin } from '@/services/authRedirect'
import { wsUi } from '@/services/wsUi'
import { captureReconnectFromAuth, tryEnterPendingRoom } from '@/services/roomResume'
import { acceptWsPayload } from '@/stores/wsBridge'

export interface WsRpcResponse {
  code: number
  message?: string
  data?: Record<string, unknown>
  requestId?: string
}

export type WsPushHandler = (msg: WsRpcResponse) => void
/** 兼容旧 on(cmd) / on('*')：第二参为整包 */
export type WsLegacyHandler = (
  data: Record<string, unknown>,
  raw: { cmd: string; data?: Record<string, unknown> },
) => void

const CONNECT_TIMEOUT_MS = 12_000
const REQUEST_TIMEOUT_MS = 8_000
const HEARTBEAT_MS = 15_000
const RECONNECT_MS = 1_000

type Pending = {
  resolve: (v: WsRpcResponse) => void
  reject: (e: Error) => void
  timer: ReturnType<typeof setTimeout>
}

/**
 * 游戏 WebSocket：binary 帧 + { route, body, requestId }
 * 鉴权：wsUrl/?token=JWT，NOTICE code=0 后 ready
 */
export class GameWebSocket {
  private socket: WebSocket | null = null
  private manualClose = false
  private connecting = false
  private authenticated = false
  private uid: number | null = null
  private nextRequestId = 1
  private readonly pending = new Map<string, Pending>()
  private readonly pushHandlers = new Set<WsPushHandler>()
  private readonly legacyWildcard = new Set<WsLegacyHandler>()
  private readonly legacyByCmd = new Map<string, Set<WsLegacyHandler>>()
  private readonly readyHandlers = new Set<(info: { reconnect: boolean }) => void>()
  private heartbeatTimer: ReturnType<typeof setInterval> | null = null
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null
  private reconnectAttempts = 0
  private connectWaiters: {
    resolve: (v: { uid: number | null }) => void
    reject: (e: Error) => void
  }[] = []
  private connectTimer: ReturnType<typeof setTimeout> | null = null
  /** 是否曾经鉴权成功过（用于区分首连与重连） */
  private everReady = false

  private syncUi(): void {
    wsUi.ready = this.ready
    // 排队重连（还没 openSocket）也要给遮罩；真正 ready 后必须清掉
    wsUi.connecting = !this.ready && (this.connecting || this.willAutoReconnect)
    wsUi.willAutoReconnect = this.willAutoReconnect
    wsUi.reconnectAttempts = this.reconnectAttempts
  }

  /** 已有 socket 在握手或已 OPEN（等鉴权），才算“建连中” */
  private socketInFlight(): boolean {
    const s = this.socket
    if (!s) return false
    return s.readyState === WebSocket.CONNECTING || s.readyState === WebSocket.OPEN
  }

  get willAutoReconnect(): boolean {
    return !this.manualClose && Boolean(resolveToken() && gameState.wsUrl)
  }

  get reconnectCount(): number {
    return this.reconnectAttempts
  }

  get connected(): boolean {
    return this.socket?.readyState === WebSocket.OPEN
  }

  get ready(): boolean {
    return this.connected && this.authenticated
  }

  get userId(): number | null {
    return this.uid
  }

  /** 推送监听（推荐） */
  onPush(handler: WsPushHandler): () => void {
    this.pushHandlers.add(handler)
    return () => this.pushHandlers.delete(handler)
  }

  /**
   * 鉴权成功回调。`info.reconnect` 为 true 表示断线后再次鉴权（含服务端重启）。
   */
  onReady(handler: (info: { reconnect: boolean }) => void): () => void {
    this.readyHandlers.add(handler)
    return () => this.readyHandlers.delete(handler)
  }

  /** 兼容旧 API：on('*') / on(cmd) */
  on(cmd: string, handler: WsLegacyHandler): () => void {
    if (cmd === '*') {
      this.legacyWildcard.add(handler)
      return () => this.legacyWildcard.delete(handler)
    }
    let set = this.legacyByCmd.get(cmd)
    if (!set) {
      set = new Set()
      this.legacyByCmd.set(cmd, set)
    }
    set.add(handler)
    return () => set?.delete(handler)
  }

  off(cmd: string, handler: WsLegacyHandler): void {
    if (cmd === '*') {
      this.legacyWildcard.delete(handler)
      return
    }
    this.legacyByCmd.get(cmd)?.delete(handler)
  }

  buildUrl(token = resolveToken()): string {
    const base = (gameState.wsUrl || '').trim().replace(/\/+$/, '')
    if (!base) return ''
    const t = String(token || '').trim()
    if (!t) return base
    const sep = base.includes('?') ? '&' : '?'
    return `${base}${sep}token=${encodeURIComponent(t)}`
  }

  /**
   * 建立连接并等待鉴权 NOTICE code=0。
   * 无 token / 无 wsUrl 时直接 resolve（本地演示）。
   */
  connect(url?: string): Promise<{ uid: number | null }> {
    const token = resolveToken()
    const target = (url || this.buildUrl(token)).trim()
    if (!target || !token) {
      console.warn('[ws] 未配置 VITE_WS_BASE_URL 或未登录，跳过长连接')
      this.syncUi()
      return Promise.resolve({ uid: null })
    }
    if (this.ready) {
      this.syncUi()
      return Promise.resolve({ uid: this.uid })
    }

    return new Promise((resolve, reject) => {
      this.connectWaiters.push({ resolve, reject })
      // 仅复用正在握手/已 OPEN 的 socket。
      // 断线后 scheduleReconnect 曾把 connecting=true 且 socket=null，
      // 若这里用 connecting 判断会永远不再 openSocket，遮罩卡死「网络连接中」。
      if (this.socketInFlight()) {
        this.syncUi()
        return
      }
      this.openSocket(target)
    })
  }

  /** 手动刷新：打断排队重连，立即再建一次 */
  async refreshConnection(): Promise<{ uid: number | null }> {
    this.clearReconnectTimer()
    this.manualClose = true
    this.releaseSocket({ close: true })
    this.manualClose = false
    this.reconnectAttempts = 0
    this.connecting = false
    this.authenticated = false
    this.syncUi()
    return this.connect()
  }

  /**
   * 卸掉当前 socket。服务端已先 disconnect 时不要再 close，否则 Chrome 报 Close received after close。
   * 注意：清掉 onclose 后不会再回调，若需要重连须由调用方 scheduleReconnect。
   */
  private releaseSocket(opts: { close: boolean }): void {
    const s = this.socket
    this.socket = null
    if (!s) return
    s.onopen = null
    s.onmessage = null
    s.onerror = null
    s.onclose = null
    if (
      opts.close &&
      (s.readyState === WebSocket.CONNECTING || s.readyState === WebSocket.OPEN)
    ) {
      try {
        s.close()
      } catch {
        /* ignore */
      }
    }
  }

  private openSocket(target: string): void {
    this.manualClose = false
    this.connecting = true
    this.authenticated = false
    this.clearReconnectTimer()
    this.clearConnectTimer()
    // 丢掉旧连接，避免 CLOSING 的 onclose 把新 socket 引用清掉
    this.releaseSocket({ close: true })
    this.syncUi()

    let socket: WebSocket
    try {
      socket = new WebSocket(target)
      socket.binaryType = 'arraybuffer'
      this.socket = socket
    } catch (err) {
      this.connecting = false
      this.rejectConnect(err instanceof Error ? err : new Error('ws create failed'))
      this.scheduleReconnect()
      this.syncUi()
      return
    }

    this.connectTimer = setTimeout(() => {
      if (this.socket !== socket) return
      this.connecting = false
      this.rejectConnect(new Error('实时服务连接超时'))
      // releaseSocket 会摘掉 onclose，必须在这里排重连
      this.releaseSocket({ close: true })
      if (!this.manualClose) this.scheduleReconnect()
      this.syncUi()
    }, CONNECT_TIMEOUT_MS)

    socket.onopen = () => {
      if (this.socket !== socket) return
      this.clearConnectTimer()
      this.startHeartbeat()
      this.syncUi()
    }

    socket.onmessage = (ev) => {
      if (this.socket !== socket) return
      if (!(ev.data instanceof ArrayBuffer)) return
      let frame: ReturnType<typeof decodeFrame>
      try {
        frame = decodeFrame(ev.data)
      } catch {
        return
      }
      if (frame.type === MessageType.PING) return
      const msg = bodyToJson(frame.body)
      if (!msg || typeof msg !== 'object') return
      const packet = msg as WsRpcResponse
      const kind = frame.type === MessageType.NOTICE ? 'notice' : 'message'
      acceptWsPayload(kind, packet)
      if (frame.type === MessageType.NOTICE) {
        this.handleNotice(packet)
        return
      }
      if (frame.type === MessageType.MESSAGE) {
        this.handleMessage(packet)
      }
    }

    socket.onerror = () => {
      if (this.socket !== socket) return
      this.syncUi()
    }

    socket.onclose = () => {
      // 即使 this.socket 已被换成新连接，也要结束「这一次」的 connecting/waiters
      const isCurrent = this.socket === socket
      if (isCurrent) {
        this.socket = null
      }
      this.stopHeartbeat()
      this.clearConnectTimer()
      if (isCurrent) {
        this.connecting = false
        this.authenticated = false
        this.uid = null
        this.rejectAllPending(new Error('实时连接已断开'))
      }
      if (!this.manualClose) {
        if (this.connectWaiters.length) {
          this.rejectConnect(new Error('实时连接已断开'))
        }
        // 服务重启 / CONNECTION_REFUSED：持续重连
        this.scheduleReconnect()
      }
      this.syncUi()
    }
  }

  disconnect(): void {
    this.manualClose = true
    this.stopHeartbeat()
    this.clearReconnectTimer()
    this.clearConnectTimer()
    this.rejectAllPending(new Error('实时连接已关闭'))
    this.releaseSocket({ close: true })
    this.authenticated = false
    this.everReady = false
    this.connecting = false
    this.reconnectAttempts = 0
    this.uid = null
    this.syncUi()
  }

  /**
   * RPC：发 Dou.* / Room.*，等 requestId 回包。
   * 自动注入 body.roomId（当前房间）。
   */
  request(route: string, body: Record<string, unknown> = {}, timeoutMs = REQUEST_TIMEOUT_MS): Promise<WsRpcResponse> {
    if (!this.ready) {
      return Promise.reject(new Error('实时服务未连接，请稍后重试'))
    }
    const requestId = String(this.nextRequestId++)
    const payload = {
      ...body,
      roomId: body.roomId ?? gameState.room.roomId,
    }
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        if (!this.pending.has(requestId)) return
        this.pending.delete(requestId)
        reject(new Error('服务器无响应，请检查网络'))
      }, timeoutMs)
      this.pending.set(requestId, { resolve, reject, timer })
      const ok = this.sendRaw(MessageType.MESSAGE, { route, body: payload, requestId })
      if (!ok) {
        clearTimeout(timer)
        this.pending.delete(requestId)
        reject(new Error('实时服务未连接，请稍后重试'))
      }
    })
  }

  /**
   * 兼容旧调用：`send({ cmd, data })` → fire-and-forget request（无 await）。
   * 新代码请用 `request(route, body)`。
   */
  send(payload: { cmd: string; data?: Record<string, unknown> }): boolean {
    if (!this.ready) {
      console.warn('[ws] 未就绪，丢弃', payload.cmd)
      return false
    }
    void this.request(payload.cmd, payload.data || {}).catch((err) => {
      console.warn('[ws] send failed', payload.cmd, err)
    })
    return true
  }

  private sendRaw(type: number, body: unknown): boolean {
    if (!this.socket || this.socket.readyState !== WebSocket.OPEN) return false
    this.socket.send(encodeFrame(type, body))
    return true
  }

  private handleNotice(msg: WsRpcResponse): void {
    if (msg?.message === 'logout') {
      if (!this.authenticated) return
      this.manualClose = true
      this.disconnect()
      forceLogin()
      return
    }

    if (!this.authenticated) {
      if (Number(msg.code) === 0) {
        const reconnect = this.everReady
        this.authenticated = true
        this.everReady = true
        const data = (msg.data || {}) as Record<string, unknown>
        this.uid = data.uid != null ? Number(data.uid) : null
        if (this.uid) applyUserProfile({ uid: this.uid })
        captureReconnectFromAuth(data)
        this.clearConnectTimer()
        const waiters = this.connectWaiters.splice(0)
        waiters.forEach((w) => w.resolve({ uid: this.uid }))
        this.connecting = false
        this.reconnectAttempts = 0
        this.syncUi()
        this.readyHandlers.forEach((fn) => {
          try {
            fn({ reconnect })
          } catch (err) {
            console.error('[ws] ready handler', err)
          }
        })
        // 刷新后鉴权带回 reconnect 房号：在大厅自动 Join 进桌
        void tryEnterPendingRoom().catch(() => {})
        return
      }
      if (Number(msg.code) === 401) {
        this.manualClose = true
        const reason = String(msg.message || '登录已失效，请重新登录')
        this.rejectConnect(new Error(reason))
        this.stopHeartbeat()
        this.clearConnectTimer()
        // 服务端 rejectOpen 已 disconnect，这里只卸引用，勿再 close
        this.releaseSocket({ close: false })
        this.authenticated = false
        this.connecting = false
        this.uid = null
        this.syncUi()
        forceLogin()
        console.warn('[ws] 鉴权失败，已退出登录', reason)
        return
      }
      this.rejectConnect(new Error(msg.message || 'WebSocket 鉴权失败'))
      this.syncUi()
      return
    }

    this.dispatchPush(msg)
  }

  private handleMessage(msg: WsRpcResponse): void {
    const requestId = msg.requestId != null && msg.requestId !== '' ? String(msg.requestId) : null
    if (requestId && this.pending.has(requestId)) {
      const p = this.pending.get(requestId)!
      clearTimeout(p.timer)
      this.pending.delete(requestId)
      if (Number(msg.code) !== 0) {
        p.reject(new Error(msg.message || '请求失败'))
      } else {
        p.resolve(msg)
      }
      // 与旧 game 一致：有 requestId 的回包只 resolve，不进推送。
      // 否则 StartGame 会「回包 dispatch + 广播」各播一次发牌。
      return
    }
    this.dispatchPush(msg)
  }

  private dispatchPush(msg: WsRpcResponse): void {
    this.pushHandlers.forEach((fn) => {
      try {
        fn(msg)
      } catch (err) {
        console.error('[ws] push handler', err)
      }
    })

    const data = (msg.data && typeof msg.data === 'object' ? msg.data : {}) as Record<string, unknown>
    const callback = String(data.callback ?? data.event ?? data.action ?? '').toLowerCase()
    const raw = { cmd: callback || 'push', data }
    if (callback) {
      this.legacyByCmd.get(callback)?.forEach((fn) => fn(data, raw))
    }
    this.legacyWildcard.forEach((fn) => fn(data, raw))
  }

  private startHeartbeat(): void {
    this.stopHeartbeat()
    this.heartbeatTimer = setInterval(() => {
      if (this.connected) {
        this.sendRaw(MessageType.PING, { t: Date.now() })
      }
    }, HEARTBEAT_MS)
  }

  private stopHeartbeat(): void {
    if (this.heartbeatTimer != null) {
      clearInterval(this.heartbeatTimer)
      this.heartbeatTimer = null
    }
  }

  private scheduleReconnect(): void {
    if (this.manualClose || this.reconnectTimer != null) return
    if (!resolveToken() || !gameState.wsUrl) {
      this.syncUi()
      return
    }
    this.reconnectAttempts += 1
    this.syncUi()
    // 退避：1s、2s、3s…最长 8s，服务重启期间持续重试
    const delay = Math.min(RECONNECT_MS * this.reconnectAttempts, 8000)
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null
      void this.connect().catch(() => {
        /* connect 失败会再走 onclose → scheduleReconnect，避免 Uncaught */
      })
    }, delay)
  }

  private clearReconnectTimer(): void {
    if (this.reconnectTimer != null) {
      clearTimeout(this.reconnectTimer)
      this.reconnectTimer = null
    }
  }

  private clearConnectTimer(): void {
    if (this.connectTimer != null) {
      clearTimeout(this.connectTimer)
      this.connectTimer = null
    }
  }

  private rejectConnect(err: Error): void {
    this.clearConnectTimer()
    const waiters = this.connectWaiters.splice(0)
    waiters.forEach((w) => {
      try {
        w.reject(err)
      } catch {
        /* ignore */
      }
    })
  }

  private rejectAllPending(err: Error): void {
    for (const p of this.pending.values()) {
      clearTimeout(p.timer)
      try {
        p.reject(err)
      } catch {
        /* ignore */
      }
    }
    this.pending.clear()
  }
}

export const wsService = new GameWebSocket()
