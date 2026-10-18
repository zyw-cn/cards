/** 2–10 人围桌坐标，DOM 头像与 Pixi 手牌共用。
 * 源布局 cards 1334×750；web-ui 舞台 1280×720，按比例换算。
 * 座位贴桌边；他人手牌在头像内侧，朝向桌心。
 * 自己头像在右下角（定庄轮转打这里）；自己手牌仍居中底沿。
 */
export const DESIGN_W = 1280
export const DESIGN_H = 720
const SX = DESIGN_W / 1334
const SY = DESIGN_H / 750

export interface RingSlot {
  x: number
  y: number
}

/** 自己头像：右下角桌沿（定庄高亮锚点，勿用对面） */
export function ringSelfAvatarSlot(): RingSlot {
  return { x: DESIGN_W - 72 * SX, y: DESIGN_H - 48 * SY }
}

/** rel 0 = 自己手牌区（底边居中，底边停在右下角个人信息之上）。其余沿上半椭圆贴桌边。 */
export function ringSlot(rel: number, total: number): RingSlot {
  if (rel <= 0) return { x: DESIGN_W / 2, y: 500 * SY }
  const others = Math.max(1, total - 1)
  const idx = Math.min(Math.max(rel - 1, 0), others - 1)
  const t = others === 1 ? 0.5 : idx / (others - 1)
  const angle = Math.PI * (1 - t)
  const cx = DESIGN_W / 2
  const cy = 400 * SY
  const rx = 560 * SX
  const ry = 265 * SY
  return {
    x: cx + Math.cos(angle) * rx,
    y: cy - Math.sin(angle) * ry,
  }
}

/**
 * 他人手牌：放在头像内侧（朝向桌心）。
 * 右侧头像的牌在其左边，左侧头像的牌在其右边，避免推出屏幕。
 * 牌心锚点居中，扇面半宽约 67，头像半宽 60。
 */
export function ringHandSlot(rel: number, total: number): RingSlot {
  const pos = ringSlot(rel, total)
  if (rel <= 0) return { x: pos.x, y: 500 * SY }
  const cx = DESIGN_W / 2
  const side = pos.x - cx
  if (Math.abs(side) > 80 * SX) {
    const inward = 140
    return {
      x: pos.x + (side > 0 ? -inward : inward),
      y: pos.y,
    }
  }
  return {
    x: pos.x,
    y: pos.y + 108,
  }
}

export function slotStyle(slot: RingSlot): Record<string, string> {
  return {
    left: `${slot.x}px`,
    top: `${slot.y}px`,
    transform: 'translate(-50%, -50%)',
  }
}
