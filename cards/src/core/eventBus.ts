type Handler = (payload?: unknown) => void

/**
 * 轻量全局事件总线（无 Pinia）。
 * WS / 逻辑层 emit，Pixi / Vue 订阅。
 */
class EventBus {
  private readonly map = new Map<string, Set<Handler>>()

  on(event: string, handler: Handler): () => void {
    let set = this.map.get(event)
    if (!set) {
      set = new Set()
      this.map.set(event, set)
    }
    set.add(handler)
    return () => set?.delete(handler)
  }

  once(event: string, handler: Handler): () => void {
    const off = this.on(event, (payload) => {
      off()
      handler(payload)
    })
    return off
  }

  off(event: string, handler: Handler): void {
    this.map.get(event)?.delete(handler)
  }

  emit(event: string, payload?: unknown): void {
    const set = this.map.get(event)
    if (!set?.size) return
    ;[...set].forEach((fn) => {
      try {
        fn(payload)
      } catch (err) {
        console.error(`[eventBus] ${event}`, err)
      }
    })
  }

  clear(event?: string): void {
    if (event) this.map.delete(event)
    else this.map.clear()
  }
}

export const eventBus = new EventBus()

/** 斗地主相关事件名 */
export const LandlordEvents = Object.freeze({
  STATE: 'landlord:state',
  DEAL: 'landlord:deal',
  BID: 'landlord:bid',
  DOUBLE: 'landlord:double',
  PLAY: 'landlord:play',
  PASS: 'landlord:pass',
  SETTLE: 'landlord:settle',
  DISSOLVE: 'landlord:dissolve',
  PHASE: 'landlord:phase',
  TIP: 'landlord:tip',
})
