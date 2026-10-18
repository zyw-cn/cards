export type CallbackHandler = (data: Record<string, unknown>, msg?: unknown) => void

/** WebSocket 推送回调基类：data.callback / event / action → handler */
export class GameCallback {
  protected ctx: Record<string, unknown>
  private readonly handlers = new Map<string, CallbackHandler>()

  constructor(ctx: Record<string, unknown> = {}) {
    this.ctx = ctx
  }

  register(keys: string | string[], handler: CallbackHandler): this {
    const list = Array.isArray(keys) ? keys : [keys]
    const bound = handler.bind(this) as CallbackHandler
    for (const key of list) {
      if (key == null || key === '') continue
      this.handlers.set(String(key), bound)
      this.handlers.set(String(key).toLowerCase(), bound)
    }
    return this
  }

  resolveKey(data: Record<string, unknown> | null | undefined): string {
    if (!data || typeof data !== 'object') return ''
    return String(data.callback ?? data.event ?? data.action ?? '').trim()
  }

  fallbackKey(_data: Record<string, unknown>): string {
    return ''
  }

  dispatch(msg: { data?: Record<string, unknown> } | Record<string, unknown>): boolean {
    const data =
      msg && typeof msg === 'object' && 'data' in msg && msg.data && typeof msg.data === 'object'
        ? (msg.data as Record<string, unknown>)
        : (msg as Record<string, unknown>)

    if (!data || typeof data !== 'object') {
      console.warn('[GameCallback] 推送无 data:', msg)
      return false
    }

    let key = this.resolveKey(data)
    if (!key) key = this.fallbackKey(data)
    if (!key) {
      console.warn('[GameCallback] 推送缺少 callback/event:', msg)
      return false
    }

    const handler = this.handlers.get(key) || this.handlers.get(String(key).toLowerCase())
    if (!handler) {
      console.warn(`[GameCallback] 未注册回调: ${key}`, data)
      return false
    }

    handler(data, msg)
    return true
  }
}
