/** 金币从输家头像飞向赢家头像（DOM，不依赖第二套 Pixi） */

export type CoinSeat = 'self' | 'left' | 'right'

export type CoinSprite = {
  id: number
  x: number
  y: number
  scale: number
  opacity: number
  rot: number
}

const FALLBACK: Record<CoinSeat, { x: number; y: number }> = {
  self: { x: 0.1, y: 0.86 },
  left: { x: 0.12, y: 0.28 },
  right: { x: 0.88, y: 0.28 },
}

function pointOf(root: HTMLElement, el: HTMLElement | null | undefined, side: CoinSeat) {
  const box = root.getBoundingClientRect()
  if (el) {
    const r = el.getBoundingClientRect()
    if (r.width > 0 && r.height > 0) {
      return {
        x: r.left + r.width / 2 - box.left,
        y: r.top + r.height / 2 - box.top,
      }
    }
  }
  return { x: FALLBACK[side].x * box.width, y: FALLBACK[side].y * box.height }
}

function quad(a: number, b: number, c: number, t: number) {
  const u = 1 - t
  return u * u * a + 2 * u * t * b + t * t * c
}

function easeOutCubic(t: number) {
  return 1 - (1 - t) ** 3
}

let seq = 1

/** 返回停止函数。每家约 9 枚，弧线飞向目标头像。 */
export function runCoinFly(opts: {
  root: HTMLElement
  anchors: Partial<Record<CoinSeat, HTMLElement | null>>
  target: CoinSeat
  sources: CoinSeat[]
  onUpdate: (coins: CoinSprite[]) => void
}): () => void {
  const sources = opts.sources.filter((s) => s && s !== opts.target)
  if (!sources.length) return () => {}
  const to = pointOf(opts.root, opts.anchors[opts.target], opts.target)
  const h = opts.root.clientHeight || 750
  const per = 9
  const duration = 780
  const jobs = sources.flatMap((side, si) => {
    const base = pointOf(opts.root, opts.anchors[side], side)
    return Array.from({ length: per }, (_, i) => {
      const jitter = h * 0.02
      const from = {
        x: base.x + (Math.random() - 0.5) * jitter * 2,
        y: base.y + (Math.random() - 0.5) * jitter,
      }
      return {
        id: seq++,
        from,
        mid: {
          x: (from.x + to.x) / 2 + (Math.random() - 0.5) * h * 0.12,
          y: Math.min(from.y, to.y) - h * (0.08 + Math.random() * 0.1),
        },
        delay: si * 40 + i * 38 + Math.random() * 50,
        dur: duration + Math.random() * 120,
        born: performance.now(),
      }
    })
  })

  let raf = 0
  let dead = false
  const tick = (now: number) => {
    if (dead) return
    const coins: CoinSprite[] = []
    let alive = false
    for (const job of jobs) {
      const elapsed = now - job.born - job.delay
      if (elapsed < 0) {
        alive = true
        continue
      }
      const t = Math.min(1, elapsed / job.dur)
      if (t >= 1) continue
      alive = true
      const p = easeOutCubic(t)
      const opacity = t < 0.12 ? t / 0.12 : t > 0.85 ? (1 - t) / 0.15 : 1
      const pop = t < 0.2 ? 0.7 + t * 1.5 : t > 0.75 ? 1 - (t - 0.75) * 1.2 : 1
      coins.push({
        id: job.id,
        x: quad(job.from.x, job.mid.x, to.x, p),
        y: quad(job.from.y, job.mid.y, to.y, p),
        scale: Math.max(0.35, pop),
        opacity,
        rot: (1 - p) * 0.8 * (job.from.x < to.x ? 1 : -1),
      })
    }
    opts.onUpdate(coins)
    if (alive) raf = requestAnimationFrame(tick)
    else opts.onUpdate([])
  }
  raf = requestAnimationFrame(tick)
  return () => {
    dead = true
    cancelAnimationFrame(raf)
    opts.onUpdate([])
  }
}
