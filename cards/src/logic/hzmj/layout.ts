/**
 * 红中麻将座位布局。web-ui 舞台基准 1280×720。
 */

export const DESIGN_W = 1280
export const DESIGN_H = 720

const OLD_W = 1280
const OLD_H = 720
const SX = DESIGN_W / OLD_W
const SY = DESIGN_H / OLD_H

export function toUiSeat(serverSeat: number, selfSeat: number, playerNum: number): number {
  const n = Math.max(2, Math.min(4, Number(playerNum) || 4))
  const self = Number(selfSeat) || 0
  const seat = Number(serverSeat) || 0
  const rel = ((seat - self) % n + n) % n
  if (n === 2) return rel === 0 ? 0 : 2
  if (n === 3) {
    if (rel === 0) return 0
    if (rel === 1) return 1
    return 3
  }
  return rel
}

function px(n: number): number {
  return n * SX
}
function py(n: number): number {
  return n * SY
}

/** 花色在框内白底的中心与最大框（相对牌宽高） */
export interface FaceBox {
  cx: number
  cy: number
  maxW: number
  maxH: number
}

const FACE: Record<string, FaceBox> = {
  /** faces≈79×111 透明牌面，贴满 self_hand 78×118，勿缩成小牌叠在框上 */
  hand: { cx: 0.5, cy: 0.5, maxW: 0.98, maxH: 0.94 },
  up: { cx: 0.5, cy: 0.405, maxW: 0.56, maxH: 0.6 },
  desk: { cx: 26.5 / 52, cy: 27 / 68, maxW: 0.5, maxH: 0.55 },
  sideDesk: { cx: 32.5 / 64, cy: 41.5 / 78, maxW: 0.42, maxH: 0.48 },
  topHand: { cx: 0.5, cy: 0.42, maxW: 0.55, maxH: 0.55 },
}

export function faceBox(frame: string): FaceBox {
  return FACE[frame] || FACE.hand
}

export const HAND_SELF = Object.freeze({
  /** 对齐 artres `self_hand.png` 78×118；勿再强拉成 GameRoom2D 的 92×122 */
  tileW: px(78),
  tileH: py(118),
  /** 重叠步进，14 张需落在左侧头像与右侧手牌之间 */
  step: px(70),
  drawGap: px(16),
  selLift: py(24),
  /**
   * majiang.fire 无斗地主底栏；顶边约 720-118-6。
   * 左留头像，右避开 sideHand（x≈1076）。
   */
  y: py(596),
  x0: px(118),
})

export const HAND_SIDE = Object.freeze({
  /** 对齐 cards：左右手牌 64×78，步进 32 */
  tileW: px(64),
  tileH: py(78),
  step: py(32),
})

export const HAND_TOP = Object.freeze({
  tileW: px(52),
  tileH: py(68),
  step: px(42),
})

export const DISCARD = Object.freeze({
  tileW: px(52),
  tileH: py(68),
  cols: 10,
})

interface Slot {
  x: number
  y: number
  ax: number
  ay: number
}

function worldTopLeft(x: number, y: number, w: number, h: number, ax: number, ay: number) {
  return {
    x: px(x - ax * w),
    y: py(OLD_H - y - (1 - ay) * h),
  }
}

function panelBL(p: { x: number; y: number; w: number; h: number; ax: number; ay: number }) {
  return { left: p.x - p.ax * p.w, bottom: p.y - p.ay * p.h }
}

const GAME_INFO = { x: 638.72, y: 395.424, w: 520, h: 520, ax: 0.5, ay: 0.5 }

function localToWorld(local: { x: number; y: number; w: number; h: number; ax: number; ay: number }) {
  const pbl = panelBL(GAME_INFO)
  return {
    x: pbl.left + local.x,
    y: pbl.bottom + local.y,
    w: local.w,
    h: local.h,
    ax: local.ax,
    ay: local.ay,
  }
}

function buildSlots(opt: {
  cols: number
  count: number
  x0: number
  y0: number
  ax: number
  ay: number
  stepAlongX: number
  stepAlongY: number
  stepRowX: number
  stepRowY: number
}): Slot[] {
  const slots: Slot[] = []
  for (let i = 0; i < opt.count; i++) {
    const along = i % opt.cols
    const row = Math.floor(i / opt.cols)
    slots.push({
      x: opt.x0 + along * opt.stepAlongX + row * opt.stepRowX,
      y: opt.y0 + along * opt.stepAlongY + row * opt.stepRowY,
      ax: opt.ax,
      ay: opt.ay,
    })
  }
  return slots
}

const DISCARD_PANELS = [
  { x: 260, y: 160, w: 382, h: 200, ax: 0.5, ay: 1 },
  { x: 486.0003, y: 261.196, w: 200, h: 320, ax: 0, ay: 0.5 },
  { x: 337, y: 340, w: 382, h: 200, ax: 0.5, ay: 0 },
  { x: 25.9985, y: 280.8, w: 200, h: 320, ax: 1, ay: 0.54 },
]

/**
 * 弃牌槽对齐 cards / Cocos。
 * 自家、对家：desk 52×68 横排。左右：sideDesk 64×78，沿 Y 叠放（步进 37）。
 */
const DISCARD_SLOTS = [
  buildSlots({
    cols: 10,
    count: 20,
    x0: -35,
    y0: 154,
    ax: 0,
    ay: 1,
    stepAlongX: 41,
    stepAlongY: 0,
    stepRowX: 0,
    stepRowY: 52,
  }),
  buildSlots({
    cols: 10,
    count: 20,
    x0: 108,
    y0: -63,
    ax: 0,
    ay: 0,
    stepAlongX: 0,
    stepAlongY: 37,
    stepRowX: -53.216,
    stepRowY: 0,
  }),
  buildSlots({
    cols: 10,
    count: 20,
    x0: 309,
    y0: 15.7721,
    ax: 1,
    ay: 0,
    stepAlongX: -41,
    stepAlongY: 0,
    stepRowX: 0,
    stepRowY: -52,
  }),
  buildSlots({
    cols: 10,
    count: 20,
    x0: 83,
    y0: 360,
    ax: 1,
    ay: 1,
    stepAlongX: 0,
    stepAlongY: -37,
    stepRowX: 53.666,
    stepRowY: 0,
  }),
]

export interface TileCell {
  x: number
  y: number
  tw: number
  th: number
  frame: 'hand' | 'up' | 'desk' | 'sideHand' | 'sideDesk' | 'sideDown' | 'topHand' | 'back'
  size: 'big' | 'small'
  flipX: boolean
  /** 可选绘制层级（弃牌左右座用） */
  z?: number
}

export function discardCell(uiSeat: number, index: number): TileCell {
  const ui = uiSeat === 1 || uiSeat === 2 || uiSeat === 3 ? uiSeat : 0
  const panel = localToWorld(DISCARD_PANELS[ui])
  const bl = panelBL(panel)
  const side = ui === 1 || ui === 3
  const tw = side ? 64 : 52
  const th = side ? 78 : 68
  const slots = DISCARD_SLOTS[ui]
  const slot = slots[Math.min(Math.max(0, index), slots.length - 1)]
  const tl = worldTopLeft(bl.left + slot.x, bl.bottom + slot.y, tw, th, slot.ax, slot.ay)
  return {
    x: tl.x,
    y: tl.y,
    tw: px(tw),
    th: py(th),
    frame: side ? 'sideDesk' : 'desk',
    size: 'small',
    flipX: ui === 3,
    z: Math.round(tl.y) + index,
  }
}

/** 兼容旧调用：左上角 */
export function discardCellPos(uiSeat: number, index: number): { x: number; y: number } {
  const cell = discardCell(uiSeat, index)
  return { x: cell.x, y: cell.y }
}

export function selfHandSlot(index: number, total: number, hasDrawExtra = false, meldCount = 0): TileCell {
  const { tileW, tileH, step, y, x0, drawGap } = HAND_SELF
  const n = Math.max(1, total)
  const lastIsDraw = hasDrawExtra && n >= 2
  const meldStride = step * 3 + px(8)
  const base = x0 + meldCount * meldStride
  // 摸牌：在正常步进后再加 drawGap，勿写成 (n-2)*step+drawGap（会与倒数第二张几乎叠成两张）
  const x = lastIsDraw && index === n - 1
    ? base + (n - 1) * step + drawGap
    : base + index * step
  return {
    x,
    y,
    tw: tileW,
    th: tileH,
    frame: 'hand',
    size: 'big',
    flipX: false,
  }
}

export function sideHandSlot(uiSeat: 1 | 3, index: number, total: number, meldCount = 0): TileCell {
  const { tileW, tileH, step } = HAND_SIDE
  const span = Math.max(0, total - 1) * step + tileH
  const panelH = py(503)
  const panelTop = uiSeat === 1 ? py(70.484) : py(96.5)
  const meldShift = uiSeat === 1 ? py([0, 128, 241, 354, 452][meldCount] || 0) : -meldCount * py(42 * 2 + 10)
  const y0 = panelTop + Math.max(0, (panelH - span) / 2) + meldShift
  const x = uiSeat === 1 ? px(1076.4) : px(140.8)
  return {
    x,
    y: y0 + index * step,
    tw: tileW,
    th: tileH,
    frame: 'sideHand',
    size: 'small',
    flipX: uiSeat === 3,
  }
}

export function topHandSlot(index: number, total: number, meldCount = 0): TileCell {
  const { tileW, tileH, step } = HAND_TOP
  const span = Math.max(0, total - 1) * step + tileW
  const panelLeft = px(227.132) - meldCount * px((84 * 3 + 12) * 0.5)
  const panelW = px(710)
  return {
    x: panelLeft + (panelW - span) / 2 + index * step,
    y: py(57.024),
    tw: tileW,
    th: tileH,
    frame: 'topHand',
    size: 'small',
    flipX: false,
  }
}

export interface MeldCell extends TileCell {
  tile: number
  z: number
}

/** 副露：三张一排，第四张（杠）叠在中间 */
export function layoutMelds(uiSeat: number, melds: number[][]): MeldCell[] {
  const ui = uiSeat === 1 || uiSeat === 2 || uiSeat === 3 ? uiSeat : 0
  const out: MeldCell[] = []
  if (ui === 0) {
    const tw = HAND_SELF.tileW
    const th = HAND_SELF.tileH
    const step = HAND_SELF.step
    const gap = px(10)
    let x = HAND_SELF.x0
    const y = HAND_SELF.y
    melds.forEach((meld) => {
      const base = meld.slice(0, 3)
      base.forEach((tile, j) => {
        out.push(meldCell(tile, x + j * step, y, tw, th, 'up', 'big', false, j))
      })
      if (meld.length > 3) {
        out.push(meldCell(meld[3], x + step, y - py(20), tw, th, 'up', 'big', false, 4))
      }
      x += base.length * step + gap
    })
    return out
  }
  if (ui === 2) {
    const tw = px(52)
    const th = py(68)
    const step = px(42)
    const gap = px(10)
    let x = px(227.132 + 710) - tw
    const y = py(57.024)
    melds.forEach((meld) => {
      const base = meld.slice(0, 3)
      base.forEach((tile, j) => {
        out.push(meldCell(tile, x - j * step, y, tw, th, 'desk', 'small', false, j))
      })
      if (meld.length > 3) {
        out.push(meldCell(meld[3], x - step, y - py(13), tw, th, 'desk', 'small', false, 4))
      }
      x -= base.length * step + gap
    })
    return out
  }
  const tw = px(64)
  const th = py(78)
  const step = py(37)
  const gap = py(8)
  // 右家外缘贴面板右边 1126.4。牌宽 64、面板宽 50，不能再用面板左缘，否则整组往头像偏 14px。
  const x = ui === 1 ? px(1126.4 - 64) : px(140.8)
  let y = ui === 1 ? py(70.484) : py(96.5 + 503) - th
  const dir = ui === 1 ? 1 : -1
  melds.forEach((meld) => {
    const base = meld.slice(0, 3)
    base.forEach((tile, j) => {
      const ty = y + dir * j * step
      // 屏上更靠下的牌盖住上一张底边，左家不能按序号叠（序号向上，会盖反）
      out.push(meldCell(tile, x, ty, tw, th, 'sideDesk', 'small', ui === 3, Math.round(ty)))
    })
    if (meld.length > 3) {
      const ty = y + dir * step - py(12)
      out.push(meldCell(meld[3], x, ty, tw, th, 'sideDesk', 'small', ui === 3, Math.round(ty) + 80))
    }
    // 末张占满牌高再留缝，避免下一组碰压进上一组花色
    y += dir * ((base.length - 1) * step + th + gap)
  })
  return out
}

function meldCell(
  tile: number,
  x: number,
  y: number,
  tw: number,
  th: number,
  frame: TileCell['frame'],
  size: TileCell['size'],
  flipX: boolean,
  z: number,
): MeldCell {
  const shown = tile > 0
  const hidden: TileCell['frame'] = frame === 'sideDesk' ? 'sideDown' : 'back'
  return { tile, x, y, tw, th, frame: shown ? frame : hidden, size, flipX, z }
}

export function upOrigin(uiSeat: number): { x: number; y: number } {
  const first = layoutMelds(uiSeat, [[1]])[0]
  return { x: first?.x || 0, y: first?.y || 0 }
}
