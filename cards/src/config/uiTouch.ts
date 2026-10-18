/**
 * 手机横屏补偿：舞台仍是 1280×720 contain，不能整体再放大（高度会裁切）。
 * 交互/字号在设计坐标里按此系数加大，约抵消 scale≈0.55 时的偏小感。
 */
export const UI_TOUCH = 1.2

/** 设计像素 × 触控系数，四舍五入 */
export function uiPx(design: number): number {
  return Math.round(design * UI_TOUCH)
}
