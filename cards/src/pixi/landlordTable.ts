import gsap from 'gsap'
import { Application, Container, Sprite } from 'pixi.js'
import type { ActionQueue, GameAction } from '@/core/actionQueue'
import { LandlordEvents, eventBus } from '@/core/eventBus'
import type { ClientCard } from '@/logic/shared/serverCard'
import type { SeatSide } from '@/logic/shared/roomSeats'
import { cardAtlasFrame } from '@/logic/shared/card'
import { landlordState, toggleSelectCard } from '@/services/landlordState'
import { preloadSheets, getSheetTexture } from '@/services/sheetLoader'
import { audioManager } from '@/services/audioManager'
import { createPixiApp, destroyPixiApp, stylePixiCanvas } from '@/pixi/pixiApp'

/** 手牌尺寸。17 张约铺满圆桌近端，避免缩在桌心一小条 */
export const HAND_CARD_W = 124
export const HAND_CARD_H = 170
/** 相邻牌左缘步进（越小叠得越紧） */
export const HAND_STEP = 56
/** 左右留白，避开侧边头像 */
const HAND_SIDE_MARGIN = 96
/**
 * 手牌底边距（相对画布底）。
 * 舞台 1280×720、底栏遮罩约 72px → 贴上沿用 74。
 */
export const HAND_BOTTOM_GAP = 74

/**
 * 斗地主 Pixi 牌桌：只读 landlordState，消费 actionQueue。
 * 画布尺寸与 App 舞台一致（1280×720），避免 1334×750 拉伸后手牌 Y 对不齐底栏。
 */
export class LandlordTableView {
  readonly root = new Container()
  private readonly handLayer = new Container()
  private readonly outLayer = new Container()
  private readonly fxLayer = new Container()
  private readonly seatLabels = new Container()
  private app: Application
  private unsubs: Array<() => void> = []
  private queue: ActionQueue | null = null

  constructor(app: Application) {
    this.app = app
    this.root.addChild(this.seatLabels, this.outLayer, this.handLayer, this.fxLayer)
    app.stage.addChild(this.root)
    this.bindState()
    this.layout()
  }

  attachQueue(q: ActionQueue): void {
    this.queue = q
    q.setHandler((action) => this.onAction(action))
  }

  refresh(): void {
    this.renderHand(false)
    this.renderOuts()
  }

  playDeal(): Promise<void> {
    return this.animateDeal()
  }

  destroy(): void {
    this.unsubs.forEach((u) => u())
    this.unsubs = []
    this.queue?.setHandler(null)
    gsap.killTweensOf(this.handLayer)
    this.root.destroy({ children: true })
  }

  private bindState(): void {
    this.unsubs.push(
      eventBus.on(LandlordEvents.STATE, () => this.syncFromState()),
      eventBus.on(LandlordEvents.DEAL, () => {
        this.refreshSeats()
        this.renderOuts()
      }),
      eventBus.on(LandlordEvents.PLAY, () => {
        this.renderHand(false)
        this.renderOuts()
      }),
      eventBus.on(LandlordEvents.PASS, () => this.renderOuts()),
      eventBus.on(LandlordEvents.DOUBLE, () => {
        this.refreshSeats()
        this.renderHand(false)
        this.renderOuts()
      }),
      eventBus.on(LandlordEvents.PHASE, () => this.syncFromState()),
    )
  }

  private layout(): void {
    this.refreshSeats()
    this.renderHand(false)
    this.renderOuts()
  }

  private async onAction(action: GameAction): Promise<void> {
    switch (action.type) {
      case 'deal':
        audioManager.playEffect('deal', 'ddz-sfx')
        await this.animateDeal()
        break
      case 'play': {
        const payload = action.payload as { side?: SeatSide; cards?: ClientCard[]; data?: { isBomb?: boolean } }
        audioManager.playEffect(payload?.data?.isBomb ? 'bomb' : 'out', 'ddz-sfx')
        this.renderHand(false)
        this.renderOuts()
        await this.pulseOut(payload?.side || 'self')
        break
      }
      case 'bomb':
        audioManager.playEffect('bomb', 'ddz-sfx')
        await this.showTipTexture('bomb')
        this.renderHand(false)
        this.renderOuts()
        break
      case 'rocket':
        audioManager.playEffect('joker', 'ddz-sfx')
        await this.showTipTexture('rocket')
        this.renderHand(false)
        this.renderOuts()
        break
      case 'plane':
        audioManager.playEffect('plane', 'ddz-sfx')
        await this.showTipTexture('plane')
        this.renderHand(false)
        this.renderOuts()
        break
      case 'shunzi':
        await this.showTipTexture('shunzi')
        this.renderHand(false)
        this.renderOuts()
        break
      case 'liandui':
        await this.showTipTexture('liandui')
        this.renderHand(false)
        this.renderOuts()
        break
      case 'pass':
        // 口播已在 playDdzPlaySfx 里播「不出」；这里不再播 ddz-sfx pass（那是「不叫」）
        this.renderOuts()
        break
      case 'settle':
        audioManager.playEffect('win', 'ddz-sfx')
        break
      default:
        this.renderHand(false)
        this.renderOuts()
        break
    }
  }

  /** 重连 / 阶段变化后把手里的牌和桌面出牌画出来 */
  private syncFromState(): void {
    this.refreshSeats()
    if (landlordState.dealing) return
    this.renderHand(false)
    this.renderOuts()
  }

  private refreshSeats(): void {
    this.seatLabels.removeChildren().forEach((c) => c.destroy())
  }

  renderHand(fromDeal: boolean): void {
    this.handLayer.removeChildren().forEach((c) => c.destroy())
    // 发牌飞牌期间由 DealFx 画，这里先空着，避免挡按钮、叠两层
    if (landlordState.dealing && !fromDeal) return
    const hand = landlordState.hand
    const selected = new Set(landlordState.selectedCodes.map((c) => c.toUpperCase()))
    const n = hand.length
    const maxW = Math.max(HAND_CARD_W, this.app.screen.width - HAND_SIDE_MARGIN * 2)
    let step = HAND_STEP
    if (n > 1) {
      const natural = (n - 1) * HAND_STEP + HAND_CARD_W
      if (natural > maxW) {
        step = Math.max(40, (maxW - HAND_CARD_W) / (n - 1))
      }
    }
    const totalW = n > 0 ? (n - 1) * step + HAND_CARD_W : 0
    const startX = this.app.screen.width / 2 - totalW / 2
    const baseY = this.app.screen.height - HAND_CARD_H - HAND_BOTTOM_GAP

    hand.forEach((card, i) => {
      let sprite: Sprite
      try {
        sprite = new Sprite(getSheetTexture(cardAtlasFrame(card)))
      } catch {
        return
      }
      sprite.width = HAND_CARD_W
      sprite.height = HAND_CARD_H
      const code = String(card.code || '')
      const lift = selected.has(code.toUpperCase()) ? 36 : 0
      const tx = startX + i * step
      const ty = baseY - lift
      sprite.x = tx
      sprite.y = ty
      sprite.eventMode = 'static'
      sprite.cursor = 'pointer'
      sprite.on('pointertap', () => {
        toggleSelectCard(code)
        this.renderHand(false)
      })
      this.handLayer.addChild(sprite)
      if (fromDeal) {
        sprite.alpha = 0
        sprite.x = this.app.screen.width / 2
        sprite.y = this.app.screen.height / 2
        gsap.to(sprite, { alpha: 1, x: tx, y: ty, duration: 0.22, delay: i * 0.028, ease: 'power2.out' })
      }
    })
  }

  private renderBottom(): void {
    const cards = landlordState.bottom
    if (!cards.length) return
    const w = HAND_CARD_W * 0.72
    const h = HAND_CARD_H * 0.72
    const overlap = 36
    const totalW = (cards.length - 1) * overlap + w
    const startX = this.app.screen.width / 2 - totalW / 2
    cards.forEach((card, i) => {
      try {
        const sp = new Sprite(getSheetTexture(cardAtlasFrame(card)))
        sp.width = w
        sp.height = h
        sp.x = startX + i * overlap
        sp.y = 108
        this.outLayer.addChild(sp)
      } catch {
        /* skip */
      }
    })
  }

  private renderOuts(): void {
    this.outLayer.removeChildren().forEach((c) => c.destroy())
    const w = this.app.screen.width
    const h = this.app.screen.height
    const outScale = 0.7
    const outW = HAND_CARD_W * outScale
    const outH = HAND_CARD_H * outScale
    const outStep = Math.round(HAND_STEP * 0.55)
    /**
     * 出牌在桌心一带。
     * 左右不要贴座位：对家闹钟 / 剩牌在头像内侧，出牌再往里、往下一点，避免挡住。
     */
    const places: Record<SeatSide, { x: number; y: number; anchorX: number }> = {
      self: { x: w / 2, y: h * 0.28, anchorX: 0.5 },
      left: { x: Math.max(320, w * 0.34), y: h * 0.36, anchorX: 0.5 },
      right: { x: Math.min(w - 320, w * 0.66), y: h * 0.36, anchorX: 0.5 },
    }

    ;(['self', 'left', 'right'] as SeatSide[]).forEach((side) => {
      const cards = landlordState.outBySide[side] || []
      if (!cards.length) return
      const pos = places[side]
      const row = new Container()
      cards.forEach((card, i) => {
        try {
          const sp = new Sprite(getSheetTexture(cardAtlasFrame(card)))
          sp.width = outW
          sp.height = outH
          sp.x = i * outStep
          row.addChild(sp)
        } catch {
          /* skip */
        }
      })
      row.x = pos.x - (cards.length > 0 ? ((cards.length - 1) * outStep + outW) * pos.anchorX : 0)
      row.y = pos.y
      this.outLayer.addChild(row)
    })
    this.renderBottom()
  }

  private async animateDeal(): Promise<void> {
    this.renderHand(true)
    this.renderOuts()
    await sleep(280 + landlordState.hand.length * 28)
  }

  private async pulseOut(_side: SeatSide): Promise<void> {
    const child = this.outLayer.children[this.outLayer.children.length - 1]
    if (!child) return
    await new Promise<void>((resolve) => {
      gsap.fromTo(
        child,
        { alpha: 0.4 },
        {
          alpha: 1,
          duration: 0.2,
          onComplete: () => resolve(),
        },
      )
      setTimeout(() => resolve(), 220)
    })
  }

  private async showTipTexture(kind: string): Promise<void> {
    const map: Record<string, string> = {
      bomb: 'bomb/joker_bomb_8.png',
      rocket: 'wang_bomb/bomb_1_2.png',
      plane: 'plane/plane_3.png',
      liandui: 'liandui/liandui.png',
      shunzi: 'shunzi/shunzi.png',
    }
    const frame = map[kind]
    if (!frame) return
    try {
      const sp = new Sprite(getSheetTexture(frame))
      sp.anchor.set(0.5)
      sp.x = this.app.screen.width / 2
      sp.y = this.app.screen.height * 0.4
      sp.alpha = 0
      this.fxLayer.addChild(sp)
      await new Promise<void>((resolve) => {
        gsap.to(sp, {
          alpha: 1,
          duration: 0.15,
          yoyo: true,
          repeat: 1,
          onComplete: () => {
            sp.destroy()
            resolve()
          },
        })
      })
    } catch {
      /* 帧未就绪时跳过 */
    }
  }
}

function sleep(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms))
}

export async function mountLandlordPixi(
  host: HTMLElement,
  hooks?: { onContextLost?: () => void },
): Promise<{
  table: LandlordTableView
  destroy: () => void
}> {
  await preloadSheets(['cards'])
  // 与 App.vue 舞台一致，手牌 Y 才能对齐底栏遮罩
  const STAGE_W = 1280
  const STAGE_H = 720
  const width = host.clientWidth > 0 ? host.clientWidth : STAGE_W
  const height = host.clientHeight > 0 ? host.clientHeight : STAGE_H
  const app = await createPixiApp({ width, height }, hooks)
  host.innerHTML = ''
  host.appendChild(app.canvas)
  stylePixiCanvas(app.canvas as HTMLCanvasElement)
  const table = new LandlordTableView(app)
  return {
    table,
    destroy: () => {
      table.destroy()
      destroyPixiApp(app)
    },
  }
}
