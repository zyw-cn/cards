export type ActionHandler = (action: GameAction) => void | Promise<void>

export interface GameAction {
  type: string
  payload?: unknown
  /** 跳过排队间隔（罕见） */
  immediate?: boolean
}

export interface ActionQueueOptions {
  /** 相邻动作最小间隔 ms，默认 0（仅串行，不强制等待） */
  gapMs?: number
}

/**
 * 动画 / 表现动作队列：WS 入队，Pixi 串行消费，防止叠动画。
 */
export function createActionQueue(options: ActionQueueOptions = {}) {
  const gapMs = options.gapMs ?? 0
  const queue: GameAction[] = []
  let running = false
  let stopped = false
  let handler: ActionHandler | null = null
  let lastFinishedAt = 0

  function setHandler(fn: ActionHandler | null): void {
    handler = fn
    if (handler && !running) void pump()
  }

  function enqueue(action: GameAction): void {
    if (stopped) return
    queue.push(action)
    void pump()
  }

  function clear(): void {
    queue.length = 0
  }

  function stop(): void {
    stopped = true
    handler = null
    clear()
  }

  function resume(): void {
    stopped = false
  }

  function size(): number {
    return queue.length
  }

  async function pump(): Promise<void> {
    if (running || stopped || handler == null) return
    running = true
    while (queue.length && handler != null && !stopped) {
      const action = queue.shift()!
      const current = handler
      if (!action.immediate && gapMs > 0) {
        const wait = Math.max(0, gapMs - (Date.now() - lastFinishedAt))
        if (wait > 0) await sleep(wait)
      }
      try {
        await current(action)
      } catch (err) {
        console.error('[actionQueue]', action.type, err)
      }
      lastFinishedAt = Date.now()
    }
    running = false
  }

  return { setHandler, enqueue, clear, stop, resume, size }
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

export type ActionQueue = ReturnType<typeof createActionQueue>
