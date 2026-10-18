/**
 * 围桌扑克 Pixi 层：只读座位快照，发牌走 GSAP。
 * 不在 ticker 里读响应式状态；由 Vue watch / actionQueue 驱动。
 */
import gsap from 'gsap'
import { Application, Container, Graphics, Sprite, Text, TextStyle } from 'pixi.js'
import type { ActionQueue, GameAction } from '@/core/actionQueue'
import { faceCodeToAtlas } from '@/logic/shared/faceCode'
import { DESIGN_H, DESIGN_W, ringHandSlot, ringSelfAvatarSlot, ringSlot } from '@/logic/shared/ringSeats'
import { preloadSheets, getSheetTexture } from '@/services/sheetLoader'
import { createPixiApp, destroyPixiApp, stylePixiCanvas } from '@/pixi/pixiApp'

const SELF_W = 78
const SELF_H = 107
const OTHER_W = 46
const OTHER_H = 63

export interface PokerDrawSeat {
  id: number
  rel: number
  cards: string[]
  highlight: boolean
}

export interface PokerDrawModel {
  seats: PokerDrawSeat[]
  total: number
  pot?: number
  dealing?: boolean
}

function cardTexture(code: string) {
  const key = !code || code === 'back' ? 'BACK' : faceCodeToAtlas(code)
  try {
    return getSheetTexture(key === 'BACK' ? 'BACK' : key)
  } catch {
    try {
      return getSheetTexture('BACK')
    } catch {
      return null
    }
  }
}

function isBackCode(code: string): boolean {
  return faceCodeToAtlas(code) === 'BACK'
}

function makeCard(code: string, w: number, h: number): Sprite | null {
  const tex = cardTexture(code)
  if (!tex) return null
  const sp = new Sprite(tex)
  sp.width = w
  sp.height = h
  sp.anchor.set(0.5)
  return sp
}

interface LaidCard {
  seatId: number
  index: number
  code: string
  x: number
  y: number
  w: number
  h: number
  wrap: Container
  sprite: Sprite
}

interface CardSlot {
  seatId: number
  index: number
  code: string
  highlight: boolean
  x: number
  y: number
  w: number
  h: number
}

export class PokerSeatTable {
  readonly root = new Container()
  private readonly cardLayer = new Container()
  private readonly potLayer = new Container()
  private readonly fxLayer = new Container()
  private queue: ActionQueue | null = null
  private last: PokerDrawModel = { seats: [], total: 2 }
  private placed: LaidCard[] = []
  private dealToken = 0
  private dealPlaying = false
  private dealTimeline: gsap.core.Timeline | null = null

  constructor(app: Application) {
    this.root.addChild(this.cardLayer, this.potLayer, this.fxLayer)
    app.stage.addChild(this.root)
  }

  attachQueue(q: ActionQueue): void {
    this.queue = q
    q.setHandler((action) => this.onAction(action))
  }

  /** Vue watch 调用：按当前快照重绘，不读外部 proxy。 */
  render(model: PokerDrawModel): void {
    this.last = model
    if (this.dealPlaying || model.dealing) return
    this.sync(model, true)
  }

  playDeal(): void {
    void this.animateDeal()
  }

  /** 定庄轮转：短闪。自己打在右下角头像，他人打在围桌座位。 */
  pulseSeat(rel: number): void {
    this.fxLayer.removeChildren().forEach((child) => {
      gsap.killTweensOf(child)
      child.destroy()
    })
    const ring = new Graphics()
    ring.circle(0, 0, 36).stroke({ width: 4, color: 0xfbbf24 })
    const slot = rel <= 0 ? ringSelfAvatarSlot() : ringSlot(rel, Math.max(this.last.total, 2))
    ring.x = slot.x
    ring.y = slot.y
    this.fxLayer.addChild(ring)
    gsap.to(ring, {
      alpha: 0,
      duration: 0.28,
      onComplete: () => ring.destroy(),
    })
  }

  playClash(fromRel: number, toRel: number): void {
    const total = Math.max(this.last.total, 2)
    const a = ringSlot(fromRel, total)
    const b = ringSlot(toRel, total)
    const midX = (a.x + b.x) / 2
    const midY = (a.y + b.y) / 2 - 36
    for (const slot of [a, b]) {
      const sp = makeCard('back', 46, 63)
      if (!sp) continue
      sp.x = slot.x
      sp.y = slot.y
      this.fxLayer.addChild(sp)
      gsap.to(sp, {
        x: midX,
        y: midY,
        duration: 0.22,
        ease: 'power2.in',
        onComplete: () => {
          gsap.to(sp, {
            alpha: 0,
            duration: 0.18,
            delay: 0.1,
            onComplete: () => sp.destroy(),
          })
        },
      })
    }
  }

  playSettle(rows: Array<{ rel: number; delta: number }>): void {
    const total = Math.max(this.last.total, 2)
    const cx = DESIGN_W / 2
    const cy = 300
    for (const row of rows) {
      if (!row.delta) continue
      const slot = ringSlot(row.rel, total)
      const chip = new Graphics()
      chip.circle(0, 0, 14).fill({ color: row.delta > 0 ? 0xf5d76e : 0xd6d3d1 })
      const from = row.delta > 0 ? { x: cx, y: cy } : slot
      const to = row.delta > 0 ? slot : { x: cx, y: cy }
      chip.x = from.x
      chip.y = from.y
      this.fxLayer.addChild(chip)
      gsap.to(chip, {
        x: to.x,
        y: to.y,
        alpha: 0.15,
        duration: 0.45,
        ease: 'power2.out',
        onComplete: () => chip.destroy(),
      })
    }
  }

  destroy(): void {
    this.dealToken += 1
    this.queue?.setHandler(null)
    this.killCardTweens()
    gsap.killTweensOf(this.fxLayer)
    this.root.destroy({ children: true })
  }

  private async onAction(action: GameAction): Promise<void> {
    if (action.type === 'deal') {
      await this.animateDeal()
      return
    }
    if (action.type === 'banker-roll') {
      await this.pulseHighlight()
    }
  }

  private slotsOf(model: PokerDrawModel): CardSlot[] {
    const slots: CardSlot[] = []
    for (const seat of model.seats) {
      const self = seat.rel === 0
      const pos = self ? ringSlot(seat.rel, model.total) : ringHandSlot(seat.rel, model.total)
      const w = self ? SELF_W : OTHER_W
      const h = self ? SELF_H : OTHER_H
      const overlap = self ? 42 : 22
      const n = seat.cards.length
      const originX = pos.x - ((n - 1) * overlap) / 2
      const y = pos.y
      seat.cards.forEach((code, index) => {
        slots.push({
          seatId: seat.id,
          index,
          code,
          highlight: seat.highlight,
          x: originX + index * overlap,
          y,
          w,
          h,
        })
      })
    }
    return slots
  }

  private sameLayout(slots: CardSlot[]): boolean {
    if (slots.length !== this.placed.length) return false
    return slots.every((slot, i) => {
      const cur = this.placed[i]
      return cur.seatId === slot.seatId && cur.index === slot.index && cur.x === slot.x && cur.y === slot.y
    })
  }

  private sync(model: PokerDrawModel, allowFlip: boolean): void {
    this.drawPot(Number(model.pot || 0))
    const slots = this.slotsOf(model)
    if (!slots.length) {
      this.clearCards()
      return
    }
    if (!this.sameLayout(slots)) {
      this.placeStill(slots)
      return
    }
    slots.forEach((slot, i) => {
      const card = this.placed[i]
      card.sprite.tint = slot.highlight ? 0xfff2b0 : 0xffffff
      if (card.code === slot.code) return
      const reveal = allowFlip && isBackCode(card.code) && !isBackCode(slot.code)
      card.code = slot.code
      if (reveal) void this.flipTo(card, slot.code)
      else this.applyFace(card, slot.code)
    })
  }

  private placeStill(slots: CardSlot[]): void {
    this.clearCards()
    for (const slot of slots) {
      const card = this.makeLaid(slot, slot.code)
      if (!card) continue
      card.wrap.x = slot.x
      card.wrap.y = slot.y
      this.cardLayer.addChild(card.wrap)
      this.placed.push(card)
    }
  }

  private makeLaid(slot: CardSlot, code: string): LaidCard | null {
    const sprite = makeCard(code, slot.w, slot.h)
    if (!sprite) return null
    sprite.tint = slot.highlight ? 0xfff2b0 : 0xffffff
    const wrap = new Container()
    wrap.addChild(sprite)
    return {
      seatId: slot.seatId,
      index: slot.index,
      code,
      x: slot.x,
      y: slot.y,
      w: slot.w,
      h: slot.h,
      wrap,
      sprite,
    }
  }

  private applyFace(card: LaidCard, code: string): void {
    if (card.wrap.destroyed) return
    const tex = cardTexture(code)
    if (!tex) return
    card.sprite.texture = tex
    card.sprite.width = card.w
    card.sprite.height = card.h
    card.code = code
  }

  private clearCards(): void {
    this.killCardTweens()
    this.placed = []
    for (const child of this.cardLayer.removeChildren()) {
      child.destroy({ children: true })
    }
  }

  private killCardTweens(): void {
    this.dealTimeline?.kill()
    this.dealTimeline = null
    for (const card of this.placed) {
      gsap.killTweensOf(card.wrap)
      gsap.killTweensOf(card.wrap.scale)
    }
  }

  private drawPot(amount: number): void {
    this.potLayer.removeChildren().forEach((child) => child.destroy({ children: true }))
    if (amount <= 0) return
    const chip = new Graphics()
    chip.circle(0, 0, 28).fill({ color: 0xc9a227 })
    chip.circle(0, 0, 20).stroke({ width: 3, color: 0xffe7a0 })
    chip.x = DESIGN_W / 2
    chip.y = 300
    this.potLayer.addChild(chip)
    const label = new Text({
      text: String(amount),
      style: new TextStyle({
        fontFamily: 'sans-serif',
        fontSize: 16,
        fill: 0x3a2a08,
        fontWeight: '700',
      }),
    })
    label.anchor.set(0.5)
    label.x = chip.x
    label.y = chip.y
    this.potLayer.addChild(label)
  }

  private dealOrder(slots: CardSlot[]): CardSlot[] {
    const max = slots.reduce((n, slot) => Math.max(n, slot.index), -1)
    const order: CardSlot[] = []
    for (let index = 0; index <= max; index += 1) {
      for (const slot of slots) {
        if (slot.index === index) order.push(slot)
      }
    }
    return order
  }

  private animateDeal(): Promise<void> {
    const token = ++this.dealToken
    const model = this.last
    const slots = this.dealOrder(this.slotsOf(model))
    this.dealPlaying = true
    this.clearCards()
    this.drawPot(Number(model.pot || 0))
    if (!slots.length) {
      this.dealPlaying = false
      return Promise.resolve()
    }

    const deckX = DESIGN_W / 2
    const deckY = 280
    const flying: Array<{ card: LaidCard; face: string }> = []
    for (const slot of slots) {
      const card = this.makeLaid(slot, 'back')
      if (!card) continue
      card.wrap.x = deckX
      card.wrap.y = deckY
      card.wrap.alpha = 0
      this.cardLayer.addChild(card.wrap)
      flying.push({ card, face: slot.code })
    }
    this.placed = flying.map((item) => item.card)
    if (!flying.length) {
      this.dealPlaying = false
      return Promise.resolve()
    }

    return new Promise((resolve) => {
      const tl = gsap.timeline({
        onComplete: () => {
          if (token !== this.dealToken) {
            resolve()
            return
          }
          const reveal = flying.filter((item) => !isBackCode(item.face))
          if (!reveal.length) {
            this.finishDeal(token)
            resolve()
            return
          }
          void Promise.all(reveal.map((item, i) => this.flipTo(item.card, item.face, i * 0.04))).then(() => {
            this.finishDeal(token)
            resolve()
          })
        },
      })
      this.dealTimeline = tl
      flying.forEach((item, i) => {
        tl.to(item.card.wrap, {
          x: item.card.x,
          y: item.card.y,
          alpha: 1,
          duration: 0.22,
          ease: 'power2.out',
        }, i * 0.055)
      })
    })
  }

  private finishDeal(token: number): void {
    if (token !== this.dealToken) return
    this.dealPlaying = false
    this.sync(this.last, true)
  }

  private flipTo(card: LaidCard, code: string, delay = 0): Promise<void> {
    const { wrap } = card
    gsap.killTweensOf(wrap.scale)
    return new Promise((resolve) => {
      gsap.timeline({ delay, onComplete: () => resolve() })
        .to(wrap.scale, { x: 0, duration: 0.1, ease: 'power1.in' })
        .call(() => {
          if (wrap.destroyed) return
          this.applyFace(card, code)
        })
        .to(wrap.scale, { x: 1, duration: 0.12, ease: 'power1.out' })
    })
  }

  private pulseHighlight(): Promise<void> {
    const ring = new Graphics()
    ring.circle(0, 0, 36).stroke({ width: 4, color: 0xfbbf24 })
    const hot = this.last.seats.find((s) => s.highlight) || this.last.seats[0]
    const slot = ringSlot(hot?.rel ?? 1, this.last.total)
    ring.x = slot.x
    ring.y = slot.y
    this.fxLayer.addChild(ring)
    return new Promise((resolve) => {
      gsap.to(ring, {
        alpha: 0.2,
        duration: 0.15,
        yoyo: true,
        repeat: 8,
        onComplete: () => {
          ring.destroy()
          resolve()
        },
      })
    })
  }
}

export async function mountPokerPixi(host: HTMLElement): Promise<{
  app: Application
  table: PokerSeatTable
  destroy: () => void
}> {
  await preloadSheets(['cards'])
  const app = await createPixiApp({ width: DESIGN_W, height: DESIGN_H })
  host.innerHTML = ''
  host.appendChild(app.canvas)
  stylePixiCanvas(app.canvas as HTMLCanvasElement)

  const table = new PokerSeatTable(app)
  return {
    app,
    table,
    destroy: () => {
      table.destroy()
      destroyPixiApp(app)
    },
  }
}
