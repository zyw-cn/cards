import type { CSSProperties } from 'vue'
import cardsAtlas from '@/assets/images/card/card.json'
import { assetUrl } from '@/services/assetUrl'

type TpFrame = {
  frame: { x: number; y: number; w: number; h: number }
}

type ClassicSheet = {
  frames: Record<string, TpFrame>
  meta: { image: string; size?: { w: number; h: number } }
}

const atlas = cardsAtlas as ClassicSheet
const SHEET_W = atlas.meta.size?.w ?? 1972
const SHEET_H = atlas.meta.size?.h ?? 636
const FRAME_W = 116
const FRAME_H = 159

/** 运行时大图走 public/assets/images/card/card.png */
const sheetUrl = assetUrl('images/card/card.png')

/** DOM 手牌/出牌：雪碧图 background-position，帧名与协议一致（A14 / E16 / F55 / BACK） */
export function cssPokerCardStyle(
  frameKey: string,
  displayHeightPx: number,
): CSSProperties {
  const entry = atlas.frames[frameKey]
  const fr = entry?.frame ?? { x: 0, y: 0, w: FRAME_W, h: FRAME_H }
  const scale = displayHeightPx / fr.h
  return {
    width: `${fr.w * scale}px`,
    height: `${displayHeightPx}px`,
    backgroundImage: `url(${sheetUrl})`,
    backgroundRepeat: 'no-repeat',
    backgroundSize: `${SHEET_W * scale}px ${SHEET_H * scale}px`,
    backgroundPosition: `-${fr.x * scale}px -${fr.y * scale}px`,
  }
}
