import gsap from 'gsap'
import { Application, Container, Graphics, Sprite, type Texture } from 'pixi.js'
import type { LandlordFxType } from '@/logic/landlord/resolvePlayFx'
import { getSheetTexture, isSheetLoaded, preloadSheet } from '@/services/sheetLoader'
import type { SheetId } from '@/config/assetManifest'
import { audioManager } from '@/services/audioManager'
import { createPixiApp, destroyPixiApp, stylePixiCanvas } from '@/pixi/pixiApp'

const DESIGN_W = 1334
const DESIGN_H = 750

type FxSpec = {
  sheet: SheetId
  frames: string[]
  /** 多帧间隔 ms；单帧 banner 用 duration */
  frameMs?: number
  /** 王炸/炸弹：引信段帧间隔 */
  fuseMs?: number
  /** 王炸/炸弹：爆炸段帧间隔 */
  boomMs?: number
  /** 引信段最多播几帧（其余进爆炸段） */
  fuseCount?: number
  duration?: number
  sfx?: string
  /** 飞机：横向飞过 */
  fly?: boolean
  scale?: number
  /** 相对桌高的目标高度比例 */
  targetHRatio?: number
}

const FX_SPECS: Partial<Record<LandlordFxType, FxSpec>> = {
  bomb: {
    sheet: 'ddz-bomb',
    frames: Array.from({ length: 8 }, (_, i) => `bomb/joker_bomb_${i + 1}.png`),
    fuseMs: 58,
    boomMs: 75,
    fuseCount: 5,
    sfx: 'bomb',
    scale: 1,
    targetHRatio: 0.34,
  },
  rocket: {
    sheet: 'ddz-wang-bomb',
    // atlas 共 12 帧（Pixi 序列帧）：bomb_0_1…9 引信 + bomb_1_0…2 爆炸
    frames: [
      ...Array.from({ length: 9 }, (_, i) => `wang_bomb/bomb_0_${i + 1}.png`),
      'wang_bomb/bomb_1_0.png',
      'wang_bomb/bomb_1_1.png',
      'wang_bomb/bomb_1_2.png',
    ],
    // 对齐旧桌：约 7 帧引信 + 剩余爆炸，节奏略放慢让 12 帧都看得见
    fuseMs: 70,
    boomMs: 90,
    fuseCount: 7,
    sfx: 'joker',
    scale: 1.15,
    targetHRatio: 0.42,
  },
  plane: {
    sheet: 'ddz-plane',
    frames: Array.from({ length: 5 }, (_, i) => `plane/plane_${i + 1}.png`),
    frameMs: 72,
    duration: 1600,
    sfx: 'plane',
    fly: true,
    scale: 1.05,
  },
  shunzi: {
    sheet: 'ddz-shunzi',
    frames: ['shunzi/shunzi.png'],
    duration: 1400,
    scale: 1.1,
  },
  liandui: {
    sheet: 'ddz-liandui',
    frames: ['liandui/liandui.png'],
    duration: 1400,
    scale: 1.1,
  },
  spring: {
    sheet: 'ddz-spring',
    frames: [
      'spring/spring.png',
      'spring/spring1.png',
      'spring/spring2.png',
      'spring/spring3.png',
    ],
    frameMs: 120,
    duration: 1600,
    scale: 1.15,
    targetHRatio: 0.36,
  },
}

function safeTexture(frameKey: string): Texture | null {
  try {
    return getSheetTexture(frameKey)
  } catch {
    console.warn('[patternFx] 缺帧', frameKey)
    return null
  }
}

function destroyFxSprite(sp: Sprite | null): void {
  if (!sp || sp.destroyed) return
  // 绝不能连带毁掉 atlas 里的共享 Texture
  sp.destroy({ children: true, texture: false, textureSource: false })
}

/**
 * 斗地主牌型特效：炸弹 / 王炸 / 飞机 / 顺子 / 连对。
 * 挂在与发牌相同的 fxHost 上（独立透明画布）。
 */
export class PatternFx {
  private app: Application | null = null
  private ownsApp = true
  private readonly layer = new Container()
  private gen = 0
  private timers: number[] = []
  private rafJobs: number[] = []

  /** 暴露给 DealFx 共用同一 Application */
  get application(): Application | null {
    return this.app
  }

  async mount(host: HTMLElement, hooks?: { onContextLost?: () => void }): Promise<void> {
    this.destroy()
    await new Promise<void>((r) => requestAnimationFrame(() => r()))
    if (!host.isConnected) {
      throw new Error('[patternFx] host 未挂载')
    }
    const w = Math.max(host.clientWidth, host.parentElement?.clientWidth || 0, 640)
    const h = Math.max(host.clientHeight, host.parentElement?.clientHeight || 0, 360)
    const app = await createPixiApp({ width: w, height: h }, hooks)
    stylePixiCanvas(app.canvas as HTMLCanvasElement, {
      pointerEvents: 'none',
      absolute: true,
      zIndex: '1',
    })
    host.appendChild(app.canvas)
    this.layer.scale.set(w / DESIGN_W, h / DESIGN_H)
    app.stage.addChild(this.layer)
    this.app = app
    this.ownsApp = true
  }

  get ready(): boolean {
    return Boolean(this.app)
  }

  destroy(): void {
    this.gen += 1
    this.clearTimers()
    gsap.killTweensOf(this.layer.children)
    this.layer.removeChildren().forEach((c) => {
      if (c instanceof Sprite) destroyFxSprite(c)
      else c.destroy({ children: true })
    })
    if (this.layer.parent) this.layer.parent.removeChild(this.layer)
    if (this.ownsApp) destroyPixiApp(this.app)
    this.app = null
    this.ownsApp = true
  }

  async play(kind: LandlordFxType): Promise<void> {
    if (kind === 'play') return
    if (!this.app) {
      console.warn('[patternFx] 未 mount，无法播放', kind)
      return
    }
    const spec = FX_SPECS[kind]
    if (!spec || !spec.frames.length) {
      console.warn('[patternFx] 无规格', kind)
      return
    }

    // 打断上一轮，避免旧 setTimeout 把新动画掐断
    this.gen += 1
    const gen = this.gen
    this.clearTimers()
    gsap.killTweensOf(this.layer.children)
    this.layer.removeChildren().forEach((c) => {
      if (c instanceof Sprite) destroyFxSprite(c)
      else c.destroy({ children: true })
    })

    try {
      if (!isSheetLoaded(spec.sheet)) {
        await preloadSheet(spec.sheet)
      }
      if (!this.app || gen !== this.gen) return
      if (spec.sfx) {
        try {
          audioManager.unlock()
          audioManager.playEffect(spec.sfx, 'ddz-sfx')
        } catch {
          /* 音效失败不阻断画面 */
        }
      }
      if (spec.fly) await this.playFly(spec, gen)
      else if (kind === 'bomb' || kind === 'rocket') await this.playBombSequence(spec, gen)
      else if (spec.frames.length === 1) await this.playBanner(spec, gen)
      else await this.playFrames(spec, gen)
    } catch (err) {
      console.warn('[patternFx]', kind, err)
      throw err
    }
  }

  private clearTimers(): void {
    for (const id of this.timers) window.clearTimeout(id)
    this.timers = []
    for (const id of this.rafJobs) window.cancelAnimationFrame(id)
    this.rafJobs = []
  }

  private wait(ms: number, gen: number): Promise<void> {
    return new Promise((resolve) => {
      const id = window.setTimeout(() => {
        this.timers = this.timers.filter((t) => t !== id)
        resolve()
      }, ms)
      this.timers.push(id)
      if (gen !== this.gen) {
        window.clearTimeout(id)
        resolve()
      }
    })
  }

  private fitSprite(sp: Sprite, tex: Texture, targetH: number, pop = 1): void {
    sp.texture = tex
    const th = Math.max(1, tex.height || tex.frame.height || 1)
    const s = (targetH / th) * pop
    sp.scale.set(s)
  }

  /** 粒子 / 震屏：对齐旧桌 bomb.js（纯 Graphics，不依赖额外贴图） */
  private spawnBurst(x: number, y: number, count: number, color: number, gen: number): void {
    const size = Math.max(3, DESIGN_H * 0.006)
    for (let i = 0; i < count; i += 1) {
      const g = new Graphics()
      g.circle(0, 0, size * (0.5 + Math.random()))
      g.fill({ color, alpha: 1 })
      g.position.set(x, y)
      this.layer.addChild(g)
      const ang = Math.random() * Math.PI * 2
      const spd = (0.18 + Math.random() * 0.55) * DESIGN_H
      const state = {
        vx: Math.cos(ang) * spd,
        vy: Math.sin(ang) * spd * 0.72,
        life: 420 + Math.random() * 380,
        max: 0,
        spin: (Math.random() - 0.5) * 0.012,
      }
      state.max = state.life
      const gravity = DESIGN_H * 0.55
      let last = performance.now()
      const tick = (now: number) => {
        if (gen !== this.gen || g.destroyed) {
          if (!g.destroyed) g.destroy()
          return
        }
        const dt = Math.min(40, now - last)
        last = now
        const sec = dt / 1000
        state.life -= dt
        state.vx *= 0.985
        state.vy += gravity * sec
        g.x += state.vx * sec
        g.y += state.vy * sec
        g.rotation += state.spin * dt
        const t = Math.max(0, state.life / state.max)
        g.alpha = t
        g.scale.set(0.35 + t * 0.75)
        if (state.life <= 0) {
          g.destroy()
          return
        }
        const id = window.requestAnimationFrame(tick)
        this.rafJobs.push(id)
      }
      const id = window.requestAnimationFrame(tick)
      this.rafJobs.push(id)
    }
  }

  private spawnShockwave(x: number, y: number, gen: number): void {
    const ring = new Graphics()
    const r0 = DESIGN_H * 0.06
    ring.circle(0, 0, r0)
    ring.stroke({ width: Math.max(4, DESIGN_H * 0.01), color: 0xffe08a, alpha: 1 })
    ring.position.set(x, y)
    ring.scale.set(0.2)
    this.layer.addChild(ring)
    gsap.to(ring.scale, {
      x: 4.6,
      y: 4.6,
      duration: 0.52,
      ease: 'power2.out',
      onComplete: () => {
        if (!ring.destroyed) ring.destroy()
      },
    })
    gsap.to(ring, {
      alpha: 0,
      duration: 0.52,
      ease: 'power2.out',
      onUpdate: () => {
        if (gen !== this.gen && !ring.destroyed) ring.destroy()
      },
    })
  }

  private spawnFlash(gen: number): void {
    const g = new Graphics()
    g.rect(0, 0, DESIGN_W, DESIGN_H)
    g.fill({ color: 0xfff1c2, alpha: 0.42 })
    this.layer.addChild(g)
    gsap.to(g, {
      alpha: 0,
      duration: 0.22,
      onComplete: () => {
        if (!g.destroyed) g.destroy()
      },
      onUpdate: () => {
        if (gen !== this.gen && !g.destroyed) g.destroy()
      },
    })
  }

  private spawnSpark(x: number, y: number, gen: number): void {
    const g = new Graphics()
    const r = Math.max(2, DESIGN_H * 0.004)
    g.star(0, 0, 4, r * 2.2, r * 0.6)
    g.fill({ color: 0xffc14a, alpha: 1 })
    g.position.set(x + (Math.random() - 0.5) * DESIGN_H * 0.06, y - DESIGN_H * 0.05)
    this.layer.addChild(g)
    const lifeMax = 180 + Math.random() * 120
    const state = { life: lifeMax }
    let last = performance.now()
    const tick = (now: number) => {
      if (gen !== this.gen || g.destroyed) {
        if (!g.destroyed) g.destroy()
        return
      }
      const dt = Math.min(40, now - last)
      last = now
      state.life -= dt
      g.y -= DESIGN_H * 0.04 * (dt / 1000)
      g.rotation += 0.02 * dt
      g.alpha = Math.max(0, state.life / lifeMax)
      g.scale.set(0.4 + state.life / lifeMax)
      if (state.life <= 0) {
        g.destroy()
        return
      }
      const id = window.requestAnimationFrame(tick)
      this.rafJobs.push(id)
    }
    const id = window.requestAnimationFrame(tick)
    this.rafJobs.push(id)
  }

  private shakeLayer(durationMs: number, gen: number): void {
    const amp = DESIGN_H * 0.014
    const start = performance.now()
    const tick = (now: number) => {
      if (gen !== this.gen) {
        this.layer.position.set(0, 0)
        return
      }
      const t = Math.min(1, (now - start) / durationMs)
      const k = (1 - t) * amp
      this.layer.x = (Math.random() - 0.5) * 2 * k
      this.layer.y = (Math.random() - 0.5) * 2 * k
      if (t >= 1) {
        this.layer.position.set(0, 0)
        return
      }
      const id = window.requestAnimationFrame(tick)
      this.rafJobs.push(id)
    }
    const id = window.requestAnimationFrame(tick)
    this.rafJobs.push(id)
  }

  private async playBanner(spec: FxSpec, gen: number): Promise<void> {
    const tex = safeTexture(spec.frames[0])
    if (!tex || !this.app || gen !== this.gen) return
    const sp = new Sprite(tex)
    sp.anchor.set(0.5)
    sp.x = DESIGN_W / 2
    sp.y = DESIGN_H * 0.42
    const sc = spec.scale || 1
    sp.scale.set(sc * 0.75)
    sp.alpha = 0
    this.layer.addChild(sp)
    const hold = (spec.duration || 1400) / 1000
    await new Promise<void>((resolve) => {
      const tl = gsap.timeline({
        onComplete: () => {
          destroyFxSprite(sp)
          resolve()
        },
      })
      tl.to(sp, { alpha: 1, duration: 0.18 }, 0)
        .to(sp.scale, { x: sc, y: sc, duration: 0.22, ease: 'back.out(1.6)' }, 0)
        .to({}, { duration: Math.max(0.4, hold - 0.45) })
        .to(sp, { alpha: 0, duration: 0.25 })
      if (gen !== this.gen) {
        tl.kill()
        destroyFxSprite(sp)
        resolve()
      }
    })
  }

  /** 炸弹 / 王炸：12/8 帧序列 + 闪光/冲击波/碎片/震屏（Pixi） */
  private async playBombSequence(spec: FxSpec, gen: number): Promise<void> {
    const keys = spec.frames
    const textures: Texture[] = []
    for (const key of keys) {
      const t = safeTexture(key)
      if (t) textures.push(t)
    }
    if (!textures.length || !this.app || gen !== this.gen) {
      console.warn('[patternFx] 炸弹帧为空', spec.sheet, `期望${keys.length} 实得${textures.length}`)
      return
    }
    if (textures.length < keys.length) {
      console.warn(
        `[patternFx] ${spec.sheet} 只加载到 ${textures.length}/${keys.length} 帧，将播已有帧`,
      )
    }

    const cx = DESIGN_W / 2
    const cy = DESIGN_H * 0.4
    const targetH = DESIGN_H * (spec.targetHRatio || 0.36)
    // 旧桌：fuseUntil = min(7, n)；王炸 atlas 12 帧 → 7 引信 + 5 爆炸
    const fuseUntil = Math.min(
      spec.fuseCount || Math.min(7, Math.ceil(textures.length * 0.6)),
      Math.max(1, textures.length - 1),
    )
    const fuseMs = spec.fuseMs || 70
    const boomMs = spec.boomMs || 90
    const baseScale = spec.scale || 1

    const sp = new Sprite(textures[0])
    sp.anchor.set(0.5)
    sp.position.set(cx, -DESIGN_H * 0.08)
    this.fitSprite(sp, textures[0], targetH, 0.55 * baseScale)
    this.layer.addChild(sp)

    // 落下
    await new Promise<void>((resolve) => {
      gsap.to(sp, {
        y: cy,
        duration: 0.32,
        ease: 'back.out(1.6)',
        onUpdate: () => {
          if (gen !== this.gen || sp.destroyed) return
          const p = Math.min(1, Math.max(0, (sp.y + DESIGN_H * 0.08) / (cy + DESIGN_H * 0.08)))
          this.fitSprite(sp, sp.texture, targetH, (0.55 + 0.5 * p) * baseScale)
          sp.rotation = (1 - p) * 0.35
        },
        onComplete: () => resolve(),
      })
    })
    if (gen !== this.gen || sp.destroyed || !this.app) {
      destroyFxSprite(sp)
      return
    }

    // 引信段（逐帧 + 火星）
    let sparkAcc = 0
    for (let i = 0; i < fuseUntil; i += 1) {
      if (gen !== this.gen || sp.destroyed || !this.app) {
        destroyFxSprite(sp)
        return
      }
      this.fitSprite(sp, textures[i], targetH, baseScale)
      sp.rotation = Math.sin(i * 1.8) * 0.035
      sparkAcc += fuseMs
      if (sparkAcc >= 70) {
        sparkAcc = 0
        this.spawnSpark(cx, cy, gen)
      }
      await this.wait(fuseMs, gen)
    }

    if (gen !== this.gen || sp.destroyed || !this.app) {
      destroyFxSprite(sp)
      return
    }

    // 爆炸瞬间：全屏闪光 + 冲击波 + 碎片 + 震屏
    this.spawnFlash(gen)
    this.spawnShockwave(cx, cy, gen)
    this.spawnBurst(cx, cy, 36, 0xffc14a, gen)
    this.spawnBurst(cx, cy, 18, 0xff6a2a, gen)
    this.shakeLayer(280, gen)

    // 爆炸段：播完剩余全部帧
    for (let i = fuseUntil; i < textures.length; i += 1) {
      if (gen !== this.gen || sp.destroyed || !this.app) {
        destroyFxSprite(sp)
        return
      }
      const pop = (1.08 + (i - fuseUntil) * 0.06) * baseScale
      this.fitSprite(sp, textures[i], targetH, pop)
      sp.rotation = 0
      await this.wait(boomMs, gen)
    }

    if (gen !== this.gen || sp.destroyed || !this.app) {
      destroyFxSprite(sp)
      return
    }
    await this.wait(200, gen)
    if (gen !== this.gen || sp.destroyed) {
      destroyFxSprite(sp)
      return
    }
    await new Promise<void>((resolve) => {
      gsap.to(sp, {
        alpha: 0,
        duration: 0.28,
        onUpdate: () => {
          if (sp.destroyed) return
          this.fitSprite(sp, sp.texture, targetH, 1.22 * baseScale)
        },
        onComplete: () => {
          destroyFxSprite(sp)
          resolve()
        },
      })
    })
  }

  private async playFrames(spec: FxSpec, gen: number): Promise<void> {
    const frames = spec.frames
      .map((k) => safeTexture(k))
      .filter((t): t is Texture => !!t)
    if (!frames.length || !this.app || gen !== this.gen) return

    const targetH = DESIGN_H * 0.32
    const sp = new Sprite(frames[0])
    sp.anchor.set(0.5)
    sp.x = DESIGN_W / 2
    sp.y = DESIGN_H * 0.4
    this.fitSprite(sp, frames[0], targetH, spec.scale || 1)
    this.layer.addChild(sp)
    const frameMs = spec.frameMs || 60

    for (let i = 1; i < frames.length; i += 1) {
      if (gen !== this.gen || sp.destroyed || !this.app) break
      await this.wait(frameMs, gen)
      if (gen !== this.gen || sp.destroyed || !this.app) break
      this.fitSprite(sp, frames[i], targetH, spec.scale || 1)
    }
    await this.wait(frameMs, gen)
    destroyFxSprite(sp)
  }

  private async playFly(spec: FxSpec, gen: number): Promise<void> {
    const frames = spec.frames
      .map((k) => safeTexture(k))
      .filter((t): t is Texture => !!t)
    if (!frames.length || !this.app || gen !== this.gen) return

    const sp = new Sprite(frames[0])
    sp.anchor.set(0.5)
    sp.y = DESIGN_H * 0.38
    sp.x = -120
    sp.scale.set(spec.scale || 1)
    this.layer.addChild(sp)
    const frameMs = spec.frameMs || 72
    const duration = (spec.duration || 1600) / 1000
    let fi = 0
    const anim = window.setInterval(() => {
      if (gen !== this.gen || sp.destroyed) return
      fi = (fi + 1) % frames.length
      sp.texture = frames[fi]
    }, frameMs)
    this.timers.push(anim)
    await new Promise<void>((resolve) => {
      gsap.to(sp, {
        x: DESIGN_W + 140,
        duration,
        ease: 'none',
        onComplete: () => {
          window.clearInterval(anim)
          this.timers = this.timers.filter((t) => t !== anim)
          destroyFxSprite(sp)
          resolve()
        },
      })
    })
  }
}
