/**
 * 红中麻将 Pixi 牌桌：只读 hzmjState，消费 actionQueue。
 * 布局基准 1334×750；atlas 缺失时用程序化牌面。
 */
import gsap from 'gsap'
import {
  Application,
  Assets,
  Container,
  Rectangle,
  Sprite,
  Text,
  TextStyle,
  Texture,
} from 'pixi.js'
import type { ActionQueue, GameAction } from '@/core/actionQueue'
import { eventBus } from '@/core/eventBus'
import { HzmjEvents } from '@/logic/hzmj/HzmjCallback'
import { createPixiApp, destroyPixiApp, stylePixiCanvas } from '@/pixi/pixiApp'
import {
  DESIGN_H,
  DESIGN_W,
  HAND_SELF,
  discardCell,
  faceBox,
  layoutMelds,
  selfHandSlot,
  sideHandSlot,
  topHandSlot,
  toUiSeat,
  type TileCell,
} from '@/logic/hzmj/layout'
import { COMPASS } from '@/logic/hzmj/roomChrome'
import { getFaceTexture, getFrameTexture, loadHzmjTileAssets, type FrameKey } from '@/pixi/hzmj/tileAtlas'
import { kingMarkTexture, loadHzmjChrome, pointerTexture } from '@/pixi/hzmj/chrome'
import { HZMJ_ASSET_ROOT, hzmjAsset } from '@/logic/hzmj/hzmjAssets'
import { playHzmjSfx } from '@/logic/hzmj/hzmjAudio'
import { flashHzmjTip, hzmjState } from '@/services/hzmjState'
import { hzmjSession } from '@/services/hzmjSession'

type TileSprite = Container & { __tile?: number; __idx?: number }

function makeTileSprite(
  tile: number,
  tw: number,
  th: number,
  opts: {
    size?: 'big' | 'small'
    frame?: FrameKey
    face?: boolean
    interactive?: boolean
    onTap?: () => void
    marked?: boolean
    dimmed?: boolean
    king?: boolean
    flipX?: boolean
  } = {},
): TileSprite {
  const {
    size = 'big',
    frame = 'hand',
    face = true,
    interactive = false,
    onTap,
    marked = false,
    dimmed = false,
    king = false,
    flipX = false,
  } = opts
  const root = new Container() as TileSprite
  root.__tile = tile
  const isKing = king && tile > 0 && hzmjState.kingTiles.includes(tile)

  // 未亮手牌：左右用侧视立牌，对家用顶视。出牌/碰杠按槽位框绘制（左右 sideDesk）。
  const standBack = frame === 'sideHand' || frame === 'topHand' || frame === 'sideDown'
  const frameKey: FrameKey = face ? frame : standBack ? frame : 'back'
  const frameTex = getFrameTexture(frameKey)
  const frameSp = new Sprite(frameTex)
  frameSp.rotation = 0
  const srcW = frameTex.width || tw
  const srcH = frameTex.height || th
  // 左右牌按 cards 槽位铺满。自家/对家竖牌保持原图比例。
  const fillCell = frame === 'sideDesk' || frame === 'sideDown' || frame === 'sideHand' || frameKey === 'sideDown' || frameKey === 'sideHand'
  const portrait = !fillCell && (frame === 'desk' || frame === 'up' || srcH >= srcW)
  const fit = fillCell
    ? 1
    : portrait
      ? Math.min(tw / Math.min(srcW, srcH), th / Math.max(srcW, srcH))
      : Math.min(tw / srcW, th / srcH)
  const drawW = fillCell ? tw : (portrait ? Math.min(srcW, srcH) : srcW) * fit
  const drawH = fillCell ? th : (portrait ? Math.max(srcW, srcH) : srcH) * fit
  frameSp.width = drawW
  frameSp.height = drawH
  frameSp.x = (tw - drawW) / 2
  frameSp.y = (th - drawH) / 2
  if (flipX) {
    frameSp.scale.x = -Math.abs(frameSp.scale.x)
    frameSp.x = tw - frameSp.x
  }
  if (marked) frameSp.tint = 0xffff99
  else if (isKing) frameSp.tint = 0xffdcdc
  else if (dimmed) frameSp.tint = 0xb8b8b8
  root.addChild(frameSp)

  if (face && tile > 0) {
    const faceTex = getFaceTexture(tile, size)
    if (faceTex) {
      const faceSp = new Sprite(faceTex)
      const natW = faceTex.width || tw
      const natH = faceTex.height || th
      // 左右 sideDesk 牌身横躺，花色跟着转 90°，字头朝桌心。
      // 右家 flipX=false → 字头向左；左家 flipX=true → 字头向右。
      const sideFace = frame === 'sideDesk'
      if (sideFace) {
        const maxAlong = tw * 0.7
        const maxAcross = th * 0.36
        const scale = Math.min(maxAcross / natW, maxAlong / natH)
        faceSp.width = natW * scale
        faceSp.height = natH * scale
        faceSp.anchor.set(0.5)
        faceSp.rotation = flipX ? Math.PI / 2 : -Math.PI / 2
        faceSp.x = tw * (32.5 / 64)
        faceSp.y = th * (41.5 / 78)
      } else {
        const box = faceBox(frame)
        const maxW = tw * box.maxW
        const maxH = th * box.maxH
        const scale = Math.min(maxW / natW, maxH / natH)
        faceSp.width = natW * scale
        faceSp.height = natH * scale
        faceSp.anchor.set(0.5)
        faceSp.rotation = 0
        faceSp.x = tw * box.cx
        faceSp.y = th * box.cy
      }
      if (marked) faceSp.tint = 0xffff99
      else if (isKing) faceSp.tint = 0xffdcdc
      else if (dimmed) faceSp.tint = 0xb8b8b8
      root.addChild(faceSp)
    }
  }

  if (isKing) {
    const markTex = kingMarkTexture()
    if (markTex) {
      const mark = new Sprite(markTex)
      mark.width = tw * 0.32
      mark.height = tw * 0.32
      mark.x = frame === 'desk' ? 2 : 0
      mark.y = frame === 'desk' ? 1 : th * 0.06
      root.addChild(mark)
    }
  }

  if (interactive && onTap) {
    root.eventMode = 'static'
    root.cursor = 'pointer'
    root.on('pointertap', onTap)
  }
  return root
}

function placeTile(
  tile: number,
  cell: TileCell,
  opts: { face?: boolean; king?: boolean; interactive?: boolean; onTap?: () => void; marked?: boolean } = {},
): TileSprite {
  const sp = makeTileSprite(tile, cell.tw, cell.th, {
    size: cell.size,
    frame: cell.frame,
    flipX: cell.flipX,
    face: opts.face ?? tile > 0,
    king: opts.king,
    interactive: opts.interactive,
    onTap: opts.onTap,
    marked: opts.marked,
  })
  sp.x = cell.x
  sp.y = cell.y
  return sp
}

/**
 * 红中麻将桌面渲染器
 */
export class HzmjTableView {
  readonly root = new Container()
  private readonly discardLayer = new Container()
  private readonly upLayer = new Container()
  private readonly handLayer = new Container()
  private readonly fxLayer = new Container()
  private readonly hudLayer = new Container()
  private readonly app: Application
  private unsubs: Array<() => void> = []
  private queue: ActionQueue | null = null
  private centerText: Text | null = null
  private compass: Container | null = null
  private dirSprites: Sprite[] = []
  private pointer: Sprite | null = null
  private pointerTween: gsap.core.Tween | null = null
  private lastDiscard: { seat: number; index: number } | null = null
  private discardCounts = [0, 0, 0, 0]
  private discardReady = false
  /** 同一张牌 700ms 内再点算出牌，不等画面抬起，避免双击落空 */
  private lastHandTap: { idx: number; t: number } | null = null
  private discarding = false
  private dealPlaying = false

  constructor(app: Application) {
    this.app = app
    this.discardLayer.sortableChildren = true
    this.upLayer.sortableChildren = true
    this.handLayer.sortableChildren = true
    this.root.addChild(this.discardLayer, this.upLayer, this.handLayer, this.fxLayer, this.hudLayer)
    this.app.stage.addChild(this.root)
    this.buildCenterHud()
    this.bindEvents()
    this.refreshAll()
  }

  attachQueue(q: ActionQueue): void {
    this.queue = q
    q.setHandler((action) => this.onAction(action))
  }

  destroy(): void {
    this.unsubs.forEach((u) => u())
    this.unsubs = []
    this.queue?.setHandler(null)
    this.pointerTween?.kill()
    gsap.killTweensOf(this.fxLayer.children)
    this.root.destroy({ children: true })
  }

  private bindEvents(): void {
    this.unsubs.push(
      eventBus.on(HzmjEvents.STATE, () => this.refreshAll()),
      eventBus.on(HzmjEvents.START, () => {
        // 开局瞬间先清掉所有座位手牌，再走骰子/发牌
        this.dealPlaying = true
        this.clearLayer(this.handLayer)
        this.clearLayer(this.fxLayer)
      }),
      eventBus.on(HzmjEvents.OP, () => this.refreshAll()),
      eventBus.on(HzmjEvents.SETTLE, () => this.refreshAll()),
    )
  }

  private buildCenterHud(): void {
    // 罗盘由 Vue majiang.fire 层绘制；这里只留指针与占位
    const compass = new Container()
    compass.x = COMPASS.cx
    compass.y = COMPASS.cy
    this.compass = compass
    this.hudLayer.addChild(compass)

    const ptr = pointerTexture()
    if (ptr) {
      this.pointer = new Sprite(ptr)
      this.pointer.anchor.set(0.5, 1)
      this.pointer.visible = false
      this.hudLayer.addChild(this.pointer)
    }

    this.centerText = new Text({
      text: '',
      style: new TextStyle({
        fill: 0xffffff,
        fontSize: 36,
        fontWeight: 'bold',
        dropShadow: {
          color: 0x000000,
          distance: 2,
          blur: 0,
        },
      }),
    })
    this.centerText.anchor.set(0.5)
    this.centerText.x = COMPASS.cx
    this.centerText.y = COMPASS.cy
    this.centerText.visible = false
    this.hudLayer.addChild(this.centerText)
  }

  private async onAction(action: GameAction): Promise<void> {
    switch (action.type) {
      case 'hzmj_start':
        await this.animateDeal()
        this.refreshAll()
        break
      case 'hzmj_op': {
        const payload = action.payload as Record<string, unknown> | undefined
        const act = String(payload?.action || '').toLowerCase()
        if (act === 'discard') await this.pulseDiscardHint()
        else await this.flashOp(act)
        this.refreshAll()
        break
      }
      case 'hzmj_settle':
        this.refreshAll()
        break
      default:
        this.refreshAll()
    }
  }

  refreshAll(): void {
    this.noteDiscards()
    this.renderDiscards()
    this.renderUps()
    this.renderHands()
    this.renderCenter()
    this.placePointer()
  }

  private noteDiscards(): void {
    const s = hzmjState
    const phase = String(s.phase).toLowerCase()
    if (!phase.includes('play')) {
      this.lastDiscard = null
      this.discardCounts = [0, 0, 0, 0]
      this.discardReady = false
      return
    }
    if (!this.discardReady) {
      for (let seat = 0; seat < 4; seat++) {
        this.discardCounts[seat] = s.discards[seat]?.length || 0
      }
      this.discardReady = true
      return
    }
    for (let seat = 0; seat < 4; seat++) {
      const n = s.discards[seat]?.length || 0
      if (n > (this.discardCounts[seat] || 0)) {
        this.lastDiscard = { seat, index: n - 1 }
      }
      this.discardCounts[seat] = n
    }
  }

  private placePointer(): void {
    if (!this.pointer) return
    const mark = this.lastDiscard
    const s = hzmjState
    if (!mark || s.settleResult) {
      this.pointer.visible = false
      this.pointerTween?.pause()
      return
    }
    const ui = toUiSeat(mark.seat, s.myIndex, s.playerNum)
    const pos = discardCell(ui, mark.index)
    const baseX = pos.x + pos.tw / 2
    const baseY = pos.y - 4
    const moved = Math.abs(this.pointer.x - baseX) > 1
    this.pointer.visible = true
    this.pointer.x = baseX
    this.pointer.width = 28
    this.pointer.height = 36
    if (!this.pointerTween || moved) {
      this.pointerTween?.kill()
      this.pointer.y = baseY
      this.pointerTween = gsap.to(this.pointer, {
        y: baseY - 10,
        duration: 0.3,
        yoyo: true,
        repeat: -1,
        ease: 'sine.inOut',
      })
    } else {
      this.pointerTween.play()
    }
  }

  private renderCenter(): void {
    if (this.compass) this.compass.visible = false
    if (this.centerText) this.centerText.visible = false
  }

  private clearLayer(layer: Container): void {
    while (layer.children.length) {
      const c = layer.removeChildAt(0)
      c.destroy({ children: true })
    }
  }

  private renderDiscards(): void {
    this.clearLayer(this.discardLayer)
    const s = hzmjState
    const n = s.playerNum
    for (let seat = 0; seat < n; seat++) {
      const ui = toUiSeat(seat, s.myIndex, n)
      const pile = s.discards[seat] || []
      pile.forEach((tile, i) => {
        const pos = discardCell(ui, i)
        const sp = placeTile(tile, pos, { king: true, face: true })
        sp.rotation = 0
        sp.zIndex = pos.z != null ? pos.z : Math.round(pos.y) + i
        this.discardLayer.addChild(sp)
      })
    }
    this.discardLayer.sortableChildren = true
  }

  private renderUps(): void {
    this.clearLayer(this.upLayer)
    const s = hzmjState
    const n = s.playerNum
    for (let seat = 0; seat < n; seat++) {
      const ui = toUiSeat(seat, s.myIndex, n)
      const cells = layoutMelds(ui, s.upTiles[seat] || [])
      cells.forEach((cell) => {
        const sp = placeTile(cell.tile, cell, { king: true, face: cell.tile > 0 })
        sp.rotation = 0
        sp.zIndex = cell.z
        this.upLayer.addChild(sp)
      })
    }
  }

  private renderHands(): void {
    this.clearLayer(this.handLayer)
    if (this.dealPlaying || hzmjState.dealing) return
    const s = hzmjState
    const n = s.playerNum
    const phase = String(s.phase).toLowerCase()
    const playing = phase === 'play' || phase === 'playing' || phase === 'settle'

    for (let seat = 0; seat < n; seat++) {
      const ui = toUiSeat(seat, s.myIndex, n)
      if (ui === 0) {
        this.renderSelfHand(playing)
        continue
      }
      const count =
        phase === 'settle' && s.hands[seat]?.some((t) => t > 0)
          ? s.hands[seat].length
          : s.seats[seat]?.tileCount || s.hands[seat]?.length || 0
      if (!playing || count <= 0) continue

      const meldCount = (s.upTiles[seat] || []).length
      if (ui === 2) {
        for (let i = 0; i < count; i++) {
          const tile = s.hands[seat]?.[i] || 0
          const shown = phase === 'settle' && tile > 0
          const pos = topHandSlot(i, count, meldCount)
          const sp = placeTile(shown ? tile : 0, { ...pos, frame: shown ? 'desk' : 'topHand' }, {
            face: shown,
          })
          sp.zIndex = i
          this.handLayer.addChild(sp)
        }
      } else {
        const side = ui as 1 | 3
        for (let i = 0; i < count; i++) {
          const tile = s.hands[seat]?.[i] || 0
          const shown = phase === 'settle' && tile > 0
          const pos = sideHandSlot(side, i, count, meldCount)
          const sp = placeTile(shown ? tile : 0, { ...pos, frame: shown ? 'sideDesk' : 'sideHand' }, {
            face: shown,
          })
          sp.zIndex = i
          this.handLayer.addChild(sp)
        }
      }
    }
  }

  private renderSelfHand(playing: boolean): void {
    if (!playing) return
    const s = hzmjState
    const hand = s.hand
    const total = hand.length
    if (!total) return
    const isTurn = Number(s.turnIndex) === Number(s.myIndex)
    const canInteract = isTurn && !s.notifyOps.length && !s.pendingDiscard && !s.trusted
    // 14 张时最后一张视为摸牌
    const hasDraw = total % 3 === 2

    const meldCount = (s.upTiles[s.myIndex] || []).length
    hand.forEach((tile, i) => {
      const pos = selfHandSlot(i, total, hasDraw, meldCount)
      const selected = s.selectedTileIdx === i
      const sp = makeTileSprite(tile, pos.tw, pos.th, {
        size: 'big',
        frame: 'hand',
        face: true,
        interactive: canInteract,
        king: true,
        onTap: () => this.onSelfHandTap(i, tile),
        marked: selected,
      })
      sp.__idx = i
      sp.x = pos.x
      sp.y = pos.y
      if (selected) {
        const lift = HAND_SELF.selLift
        for (const child of sp.children) child.y -= lift
        sp.hitArea = new Rectangle(0, -lift, pos.tw, pos.th + lift)
        sp.zIndex = 20
      } else {
        sp.zIndex = i
      }
      this.handLayer.addChild(sp)
    })
  }

  private onSelfHandTap(index: number, tile: number): void {
    const s = hzmjState
    if (s.notifyOps.length || s.pendingDiscard) {
      flashHzmjTip('请先处理碰/杠/胡或点过')
      return
    }
    const now = performance.now()
    const again =
      s.selectedTileIdx === index ||
      (this.lastHandTap?.idx === index && now - this.lastHandTap.t < 700)
    if (again) {
      this.lastHandTap = null
      if (this.discarding) return
      this.discarding = true
      s.selectedTileIdx = null
      void hzmjSession.discard(tile).finally(() => {
        this.discarding = false
      })
      return
    }
    this.lastHandTap = { idx: index, t: now }
    s.selectedTileIdx = index
    playHzmjSfx('select')
  }

  private async animateDeal(): Promise<void> {
    this.dealPlaying = true
    hzmjState.dealing = true
    this.clearLayer(this.handLayer)
    this.clearLayer(this.fxLayer)
    const s = hzmjState
    const hand = [...s.hand]
    if (!hand.length) {
      this.dealPlaying = false
      s.dealing = false
      return
    }

    // 对齐 game/client：有骰子才等；抢庄模式无骰子直接发牌
    if (s.diceList.length >= 2) {
      await new Promise<void>((r) => setTimeout(r, 1800))
    }

    const hasDraw = hand.length % 3 === 2
    const total = hand.length
    // 对齐 DEAL_GROUP：3→6→9→12→13 逐组亮出
    const steps = [3, 6, 9, 12, total].filter((n, i, arr) => n <= total && (i === 0 || n > arr[i - 1]))
    const uniqueSteps = [...new Set(steps)]

    for (const reveal of uniqueSteps) {
      this.clearLayer(this.fxLayer)
      for (let i = 0; i < reveal; i++) {
        const slot = selfHandSlot(i, total, hasDraw)
        const back = new Sprite(getFrameTexture('back'))
        back.width = slot.tw
        back.height = slot.th
        back.anchor.set(0)
        back.x = slot.x
        back.y = slot.y
        this.fxLayer.addChild(back)
      }
      playHzmjSfx('deal')
      await new Promise<void>((r) => setTimeout(r, 200))
    }

    // 翻面停顿
    await new Promise<void>((r) => setTimeout(r, 500))
    this.clearLayer(this.fxLayer)
    this.dealPlaying = false
    s.dealing = false
    this.renderHands()
  }

  private async pulseDiscardHint(): Promise<void> {
    this.refreshAll()
  }

  /** 碰杠胡字用旧 GameRoomUI 图，音效仍走现有 sprite */
  private async flashOp(action: string): Promise<void> {
    const name =
      action === 'pong' || action === 'peng'
        ? 'pong'
        : action === 'kong' || action === 'gang'
          ? 'kong'
          : action === 'chow' || action === 'chi'
            ? 'chow'
            : action === 'win' || action === 'draw_win' || action === 'kong_win'
              ? 'win'
              : action === 'ting'
                ? 'ting'
                : ''
    if (!name) return
    let tex: unknown
    try {
      tex = await Assets.load(hzmjAsset(`${HZMJ_ASSET_ROOT}/ui/GameRoomUI/${name}_1.png`))
    } catch {
      return
    }
    if (!(tex instanceof Texture)) return
    const sp = new Sprite(tex)
    sp.anchor.set(0.5)
    sp.x = DESIGN_W / 2
    sp.y = DESIGN_H / 2 - 20
    this.fxLayer.addChild(sp)
    await new Promise<void>((resolve) => {
      gsap.fromTo(
        sp,
        { alpha: 0, scale: 0.55 },
        {
          alpha: 1,
          scale: 1,
          duration: 0.18,
          yoyo: true,
          repeat: 1,
          ease: 'power1.out',
          onComplete: () => {
            sp.destroy()
            resolve()
          },
        },
      )
    })
  }
}

/** 在宿主 DOM 上挂载 1334×750 Pixi Application */
export async function mountHzmjPixi(host: HTMLElement): Promise<{
  app: Application
  table: HzmjTableView
  destroy: () => void
}> {
  await loadHzmjTileAssets()
  await loadHzmjChrome()
  const app = await createPixiApp({ width: DESIGN_W, height: DESIGN_H })
  host.innerHTML = ''
  host.appendChild(app.canvas)
  stylePixiCanvas(app.canvas as HTMLCanvasElement)

  const table = new HzmjTableView(app)
  return {
    app,
    table,
    destroy: () => {
      table.destroy()
      destroyPixiApp(app)
    },
  }
}
