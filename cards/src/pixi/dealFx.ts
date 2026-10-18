import gsap from 'gsap'
import { Application, Container, Rectangle, Sprite, Texture } from 'pixi.js'
import { cardAtlasFrame, ddzSortRank, sortCards } from '@/logic/shared/card'
import type { ClientCard } from '@/logic/shared/serverCard'
import { assetUrl } from '@/services/assetUrl'
import { loadLocalTexture, readText } from '@/services/readLocal'
import { audioManager } from '@/services/audioManager'
import { landlordState } from '@/services/landlordState'
import { createPixiApp, destroyPixiApp, stylePixiCanvas } from '@/pixi/pixiApp'
import { HAND_BOTTOM_GAP, HAND_CARD_H, HAND_CARD_W, HAND_STEP } from '@/pixi/landlordTable'

const DEAL_N = 17
const DECK_TOTAL = 54
const STEP_S = 0.05
const FLY_S = 0.26
const FLY_W = 64
const FLY_H = 88
const SELF_W = HAND_CARD_W
const SELF_H = HAND_CARD_H
const SELF_GAP = HAND_STEP
/** 与 landlordTable 手牌底边距一致，发完牌和真手牌对齐 */
const SELF_BOTTOM_GAP = HAND_BOTTOM_GAP
const OPP_W = 52
const OPP_H = 72
const CARD_BACK_REL = 'images/common/card_back.png'
const FACE_JSON_REL = 'images/card/card.json'

type RelSeat = 0 | 1 | 2

type LandedSelf = {
  sprite: Sprite
  card: ClientCard
}

type TpFrame = { frame: { x: number; y: number; w: number; h: number } }
type TpSheet = {
  frames: Record<string, TpFrame>
  meta: { image: string }
}

type DealLayout = {
  deckX: number
  deckY: number
  self: (index: number, total: number) => { x: number; y: number }
  opp: (rel: 1 | 2, index: number, total: number) => { x: number; y: number }
}

/** 按当前画布落点：自己在底栏手牌处，左右落在头像旁牌背 */
function buildLayout(w: number, h: number): DealLayout {
  const selfY = h - SELF_BOTTOM_GAP - SELF_H / 2
  return {
    deckX: w / 2,
    deckY: h * 0.42,
    self(index, total) {
      const n = Math.max(1, total)
      const span = (n - 1) * SELF_GAP + SELF_W
      const x0 = w / 2 - span / 2 + SELF_W / 2
      return { x: x0 + index * SELF_GAP, y: selfY }
    },
    opp(rel, index, total) {
      const baseX = rel === 1 ? w - 176 : 176
      const baseY = 248
      const inward = rel === 1 ? -1 : 1
      const n = Math.max(1, total)
      const offset = index - (n - 1) / 2
      return {
        x: baseX + inward * 10 + offset * 1.2,
        y: baseY + offset * 0.45,
      }
    },
  }
}

function sliceFace(base: Texture, x: number, y: number, w: number, h: number): Texture {
  const tex = new Texture({
    source: base.source,
    frame: new Rectangle(x, y, w, h),
    orig: new Rectangle(0, 0, w, h),
  })
  tex.updateUvs()
  return tex
}

/**
 * 发牌特效：独立牌背 PNG 飞行 → 理牌 → 再翻正面。
 * 禁止用图集 card/0 当牌背（会和其它帧抢 UV，54 张都变成同一张 9）。
 */
export class DealFx {
  private app: Application | null = null
  /** 是否由本类创建 Application（共享时为 false，destroy 不拆 context） */
  private ownsApp = true
  private readonly layer = new Container()
  private timeline: gsap.core.Timeline | null = null
  private playing = false
  /** reset 时递增，进行中的 play 不再接着播下一段 */
  private playGen = 0
  private readonly sprites: Sprite[] = []
  private backSource: Texture | null = null
  private readonly faceTex = new Map<string, Texture>()

  /**
   * @param existing 若传入则挂到同一 WebGL Application（与 PatternFx 共用，少占一个 context）
   */
  async mount(host: HTMLElement, existing?: Application | null): Promise<void> {
    this.destroy()
    if (existing) {
      this.app = existing
      this.ownsApp = false
      this.layer.sortableChildren = true
      if (!this.layer.parent) existing.stage.addChild(this.layer)
      await this.preloadTextures()
      return
    }
    const app = await createPixiApp({ width: 1280, height: 720 })
    stylePixiCanvas(app.canvas as HTMLCanvasElement, { pointerEvents: 'none' })
    host.appendChild(app.canvas)
    this.layer.sortableChildren = true
    app.stage.addChild(this.layer)
    this.app = app
    this.ownsApp = true
    await this.preloadTextures()
  }

  resetTable(): void {
    this.playGen += 1
    this.killAllTweens()
    this.playing = false
    this.evict()
  }

  destroy(): void {
    this.resetTable()
    if (this.layer.parent) this.layer.parent.removeChild(this.layer)
    if (this.ownsApp) destroyPixiApp(this.app)
    this.app = null
    this.ownsApp = true
    this.backSource = null
    this.faceTex.clear()
  }

  get busy(): boolean {
    return this.playing
  }

  async play(hand: ClientCard[]): Promise<void> {
    const cards = hand.length ? [...hand] : [...landlordState.hand]
    this.resetTable()
    const gen = this.playGen
    const stale = () => gen !== this.playGen
    if (!this.app) return
    if (!this.backSource) await this.preloadTextures()
    if (stale() || !this.backSource) return
    this.playing = true

    const layout = buildLayout(this.app.screen.width, this.app.screen.height)
    const dealN = Math.max(1, cards.length || DEAL_N)
    // 自己若已含底牌（20），左右仍按 17 张发；跑得快等大家同张数时两边一样
    const oppN = dealN > DEAL_N ? DEAL_N : dealN
    const flyTotal = dealN + oppN * 2
    const pile = this.spawnPile(Math.max(DECK_TOTAL, flyTotal), layout.deckX, layout.deckY)
    const selfHand: LandedSelf[] = []

    audioManager.playEffect('deal', 'ddz-sfx')
    try {
      if (landlordState.seats.left.occupied) landlordState.seats.left.cardsLeft = 0
      if (landlordState.seats.right.occupied) landlordState.seats.right.cardsLeft = 0

      await this.shufflePileAnim(pile, layout.deckX, layout.deckY)
      if (stale()) return
      await this.dealCardsAnim(pile, cards, selfHand, layout, dealN, oppN)
      if (stale()) return
      await this.sortHandCardAnim(selfHand)
      if (stale()) return
      await this.flipSelfHandAnim(selfHand)
    } finally {
      if (stale()) return
      // 左右按发牌张数补回。已经加上底牌的地主座（多于这一手）不要刷回去。
      const restore = (side: 'left' | 'right') => {
        const seat = landlordState.seats[side]
        if (!seat.occupied) return
        const cur = Number(seat.cardsLeft)
        if (Number.isFinite(cur) && cur > oppN) return
        seat.cardsLeft = oppN
      }
      restore('left')
      restore('right')
      this.playing = false
      this.evict()
    }
  }

  private async preloadTextures(): Promise<void> {
    this.backSource = await loadLocalTexture(assetUrl(CARD_BACK_REL))
    const jsonUrl = assetUrl(FACE_JSON_REL)
    const sheet = JSON.parse(await readText(jsonUrl)) as TpSheet
    const imgName = String(sheet.meta.image || 'card.png')
    const imgRel = imgName.includes('/')
      ? imgName.replace(/^\/+/, '').replace(/^assets\//, '')
      : `${FACE_JSON_REL.slice(0, FACE_JSON_REL.lastIndexOf('/') + 1)}${imgName}`
    const base = await loadLocalTexture(assetUrl(imgRel))
    this.faceTex.clear()
    for (const [name, f] of Object.entries(sheet.frames)) {
      const { x, y, w, h } = f.frame
      this.faceTex.set(name, sliceFace(base, x, y, w, h))
    }
  }

  /** 洗牌：牌堆拆成左右两叠再合上，然后再发 */
  private async shufflePileAnim(pile: Sprite[], deckX: number, deckY: number): Promise<void> {
    const half = Math.floor(pile.length / 2)
    if (half < 4) return
    const tl = gsap.timeline({ paused: true })
    this.timeline = tl
    const spread = 78
    pile.forEach((sprite, i) => {
      const left = i < half
      const at = (i % 6) * 0.012
      tl.to(sprite, {
        x: deckX + (left ? -spread : spread) + ((i % 3) - 1) * 0.8,
        y: deckY - (left ? i : i - half) * 0.28,
        rotation: left ? -0.18 : 0.18,
        duration: 0.22,
        ease: 'power2.out',
      }, at)
    })
    pile.forEach((sprite, i) => {
      tl.to(sprite, {
        x: deckX + ((i % 3) - 1) * 0.6,
        y: deckY - i * 0.35,
        rotation: 0,
        duration: 0.2,
        ease: 'power2.inOut',
      }, 0.28 + (i % 8) * 0.008)
    })
    await this.waitTimeline(tl)
  }

  /** ① 飞行全程背面；自己多出的底牌不发给左右 */
  private async dealCardsAnim(
    pile: Sprite[],
    cards: ClientCard[],
    selfHand: LandedSelf[],
    layout: DealLayout,
    dealN = DEAL_N,
    oppN = DEAL_N,
  ): Promise<void> {
    const tl = gsap.timeline({ paused: true })
    this.timeline = tl
    const seats: RelSeat[] = [0, 1, 2]
    let shot = 0
    const rounds = Math.max(dealN, oppN)
    for (let round = 0; round < rounds; round += 1) {
      for (const seat of seats) {
        if (seat === 0 && round >= dealN) continue
        if (seat !== 0 && round >= oppN) continue
        const idx = shot
        const sprite = pile[idx]
        if (!sprite) {
          shot += 1
          continue
        }
        this.paintBack(sprite)
        const dest = seat === 0 ? layout.self(round, dealN) : layout.opp(seat, round, oppN)
        const spin = (Math.random() - 0.5) * 0.48
        const at = idx * STEP_S
        const w = seat === 0 ? SELF_W : OPP_W
        const h = seat === 0 ? SELF_H : OPP_H
        if (seat === 0 && cards[round]) {
          selfHand.push({ sprite, card: cards[round] })
        }
        tl.call(() => {
          this.paintBack(sprite)
          sprite.zIndex = 2000 + idx
        }, undefined, at)
        tl.to(sprite, {
          x: dest.x,
          y: dest.y,
          width: w,
          height: h,
          rotation: 0,
          duration: FLY_S,
          ease: 'power2.out',
          onStart: () => {
            this.paintBack(sprite)
            sprite.rotation = spin
            sprite.zIndex = 2000 + idx
            if (seat === 1 && landlordState.seats.right.occupied) {
              landlordState.seats.right.cardsLeft = round + 1
            }
            if (seat === 2 && landlordState.seats.left.occupied) {
              landlordState.seats.left.cardsLeft = round + 1
            }
          },
        }, at)
        tl.call(() => {
          sprite.zIndex = seat * 20 + round
        }, undefined, at + FLY_S)
        shot += 1
      }
    }
    await this.waitTimeline(tl)
  }

  /** kill 不会结束 timeline 的 Promise，最小化打断时要在这里放行 */
  private waitTimeline(tl: gsap.core.Timeline): Promise<void> {
    return new Promise((resolve) => {
      let settled = false
      const finish = () => {
        if (settled) return
        settled = true
        resolve()
      }
      tl.eventCallback('onComplete', finish)
      tl.eventCallback('onInterrupt', finish)
      tl.play()
    })
  }

  /** ② 按 3<4<…<2<小王<大王 理牌（仍是背面，从发到的位置滑到排序位） */
  private async sortHandCardAnim(selfHand: LandedSelf[]): Promise<void> {
    selfHand.sort((a, b) => ddzSortRank(a.card) - ddzSortRank(b.card))
    landlordState.hand = sortCards(selfHand.map((x) => x.card))
    const tl = gsap.timeline({ paused: true })
    this.timeline = tl
    const layout = this.app
      ? buildLayout(this.app.screen.width, this.app.screen.height)
      : null
    selfHand.forEach((item, i) => {
      const dest = layout ? layout.self(i, selfHand.length) : { x: item.sprite.x, y: item.sprite.y }
      const at = i * 0.015
      tl.call(() => {
        item.sprite.zIndex = 40 + i
      }, undefined, at)
      tl.to(item.sprite, { x: dest.x, y: dest.y, duration: 0.18, ease: 'power2.out' }, at)
    })
    await this.waitTimeline(tl)
  }

  /** ③ 排完后才翻正面；复用同一 sprite */
  private async flipSelfHandAnim(selfHand: LandedSelf[]): Promise<void> {
    const list = selfHand
    const tl = gsap.timeline({ paused: true })
    this.timeline = tl
    list.forEach((item, i) => {
      const t = i * 0.03
      tl.to(item.sprite.scale, { x: 0, duration: 0.1, ease: 'power1.in' }, t)
      tl.call(() => {
        const face = this.faceTex.get(cardAtlasFrame(item.card))
        if (face) item.sprite.texture = face
        item.sprite.width = SELF_W
        item.sprite.height = SELF_H
      }, undefined, t + 0.1)
      tl.to(item.sprite.scale, { x: 1, duration: 0.12, ease: 'power1.out' }, t + 0.1)
    })
    await this.waitTimeline(tl)
  }

  private spawnPile(count: number, x: number, y: number): Sprite[] {
    const list: Sprite[] = []
    for (let i = 0; i < count; i += 1) {
      const s = this.makeBackSprite()
      if (!s) continue
      s.position.set(x + ((i % 3) - 1) * 0.6, y - i * 0.35)
      s.rotation = 0
      s.zIndex = i
      this.layer.addChild(s)
      this.sprites.push(s)
      list.push(s)
    }
    return list
  }

  private makeBackSprite(): Sprite | null {
    if (!this.backSource) return null
    const s = new Sprite(this.backSource)
    s.anchor.set(0.5, 0.5)
    s.width = FLY_W
    s.height = FLY_H
    return s
  }

  private paintBack(sprite: Sprite): void {
    if (!this.backSource) return
    if (sprite.texture !== this.backSource) sprite.texture = this.backSource
  }

  private killAllTweens(): void {
    this.timeline?.kill()
    this.timeline = null
    gsap.killTweensOf(this.sprites)
    gsap.killTweensOf(this.layer.children)
  }

  private evict(): void {
    this.killAllTweens()
    this.sprites.splice(0)
    this.layer.removeChildren().forEach((c) => {
      gsap.killTweensOf(c)
      c.destroy({ texture: false, textureSource: false })
    })
  }
}
