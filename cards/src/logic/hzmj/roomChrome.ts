/**
 * Cocos majiang.fire 1280×720 坐标（Y 向上、原点在舞台中心 → CSS）。
 * 禁止再用 GameRoom2D info0~3 / prepare_btn 那套（那是 cards/game Vue 壳）。
 */
import { DESIGN_H, DESIGN_W } from '@/logic/hzmj/layout'

const OLD_W = 1280
const OLD_H = 720
export const SX = DESIGN_W / OLD_W
export const SY = DESIGN_H / OLD_H

/** Cocos 绝对坐标（左下原点）→ CSS */
export function cocosStyle(
  node: { x: number; y: number; w: number; h: number; ax?: number; ay?: number },
  parentH = OLD_H,
): Record<string, string> {
  const ax = node.ax ?? 0.5
  const ay = node.ay ?? 0.5
  const left = (node.x - ax * node.w) * SX
  const top = (parentH - node.y - (1 - ay) * node.h) * SY
  return {
    position: 'absolute',
    left: `${left}px`,
    top: `${top}px`,
    width: `${node.w * SX}px`,
    height: `${node.h * SY}px`,
  }
}

/** majiang.fire：子节点相对 Canvas 中心 (640,360) */
export function fromCenter(
  cx: number,
  cy: number,
  w: number,
  h: number,
  ax = 0.5,
  ay = 0.5,
): Record<string, string> {
  return cocosStyle({ x: DESIGN_W / 2 + cx, y: DESIGN_H / 2 + cy, w, h, ax, ay })
}

/**
 * 桌角：对齐 majiang.fire「背景-table_corner*」
 * 父节点 bg 锚点在舞台中心。
 */
function cornerCss(
  localX: number,
  localY: number,
  w: number,
  h: number,
  scaleX = 1,
  scaleY = 1,
): Record<string, string> {
  const style = fromCenter(localX, localY, w, h)
  if (scaleX !== 1 || scaleY !== 1) {
    style.transform = `scale(${scaleX}, ${scaleY})`
    style.transformOrigin = 'center center'
  }
  return style
}

export const TABLE_CORNERS = [
  { key: 1 as const, style: cornerCss(-558, 278, 164, 164) },
  { key: 3 as const, style: cornerCss(-632.5, 0, 15, 400) },
  { key: 2 as const, style: cornerCss(-558, -278, 164, 164) },
  { key: 1 as const, style: cornerCss(557.3, 278, 164, 164, -1, 1) },
  { key: 3 as const, style: cornerCss(631.8, 0, 15, 400, -1, 1) },
  { key: 2 as const, style: cornerCss(557.2, -277.9, 164, 164, -1, 1) },
  { key: 4 as const, style: cornerCss(0, 352.5, 1000, 15) },
  { key: 4 as const, style: cornerCss(0, -352.4, 1000, 15, 1, -1) },
]

export const DRAGON_BOX = fromCenter(0, 0, 507, 506)
export const GAME_TITLE_BOX = fromCenter(0, 189, 237, 58)
export const ROOM_NUMBERS_BOX = fromCenter(-546, 323, 187, 81)
export const SETTING1_BOX = fromCenter(579.9, 334.2, 120, 52)

/** timer @ (0,20)：CSS/Pixi Y 向下，中心略上移 */
export const COMPASS = {
  cx: DESIGN_W / 2,
  cy: DESIGN_H / 2 - 20 * SY,
  drawW: 129 * SX,
  drawH: 129 * SY,
  /** @deprecated Pixi 旧罗盘尺寸，Vue 已画切边罗盘 */
  w: 129 * SX,
  h: 129 * SY,
}

/**
 * searchlight 相对 timer（majiang.fire）：
 * 容器 searchlight @ (25,-126)；子节点 current/top/left/right 相对偏移。
 * 下列为相对罗盘中心的世界坐标（Cocos Y↑ → CSS Y↓）。
 */
export const COMPASS_BEAMS = [
  // ui0 自己（下）current：searchlight+(0,-1) → (25,-127)
  { w: 426 * SX, h: 203 * SY, x: 25 * SX, y: 127 * SY },
  // ui1 右 right：searchlight+(104,153) → (129,27)
  { w: 204 * SX, h: 425 * SY, x: 129 * SX, y: -27 * SY },
  // ui2 对家（上）top：searchlight+(0,255) → (25,129)
  { w: 422 * SX, h: 201 * SY, x: 25 * SX, y: -129 * SY },
  // ui3 左 left：searchlight+(-153,152) → (-128,26)
  { w: 204 * SX, h: 425 * SY, x: -128 * SX, y: -26 * SY },
] as const

/** @deprecated GameRoom2D 方位图，Pixi 勿再当主罗盘 */
export const COMPASS_DIRS = [
  { x: 0, y: 38.89 * SY, w: 114 * SX, h: 38 * SY },
  { x: 38.86 * SX, y: 0.77 * SY, w: 40 * SX, h: 108 * SY },
  { x: 1.16 * SX, y: -37.16 * SY, w: 114 * SX, h: 38 * SY },
  { x: -37.12 * SX, y: 0, w: 40 * SX, h: 108 * SY },
]
/**
 * mjplayer setPosition（相对中心）→ ui 0/1/2/3
 * 容器略放大以放下头像+昵称/金币；自己位偏左下，信息不压手牌。
 */
export const SEAT_BOX = [
  fromCenter(-570, -150, 140, 150), // self
  fromCenter(570, 50, 140, 150), // right
  fromCenter(400, 300, 140, 150), // top
  fromCenter(-570, 50, 140, 150), // left
]

/** readybtn @ (0,-153) 263×109 */
export const PREPARE_BOX = fromCenter(0, -153, 263, 109)

/**
 * top 菜单簇 @ (334,315)；leave/trust/setting 相对 top
 * world = top + local
 */
export const MENU_LEAVE_BOX = fromCenter(334 + 240, 315 - 30, 92, 34)
export const MENU_TRUST_BOX = fromCenter(334 + 240, 315 - 90, 92, 40)
export const MENU_SETTING_BOX = fromCenter(334 + 239, 315 - 150, 90, 38)

/** 余牌：贴近罗盘左侧，非 GameRoom2D room_info_panel */
export const ROOM_TABLE_INFO = fromCenter(-200, 20, 120, 36)

export const KING_BOX = cocosStyle({ x: 19.2, y: 712.8, w: 80, h: 150, ax: 0, ay: 1 })
export const OP_BOX = cocosStyle({ x: 742.4, y: 133.2, w: 300, h: 100, ax: 0, ay: 0 })
