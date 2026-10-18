/**
 * 红中麻将牌面：优先 artres/ui/mahjong；缺失时用程序化字牌。
 */
import { Assets, Texture } from 'pixi.js'
import { HZMJ_ART_FRAMES, hzmjFace } from '@/logic/hzmj/hzmjAssets'
import { tileColor, tileLabel } from '@/logic/hzmj/tile'

export type TileFaceSize = 'big' | 'small'
export type FrameKey = 'hand' | 'up' | 'desk' | 'sideHand' | 'sideDesk' | 'sideDown' | 'topHand' | 'back'

let mode: 'unknown' | 'loose' | 'procedural' = 'unknown'
const proceduralFaces = new Map<string, Texture>()
const proceduralFrames = new Map<string, Texture>()
const looseFaces = new Map<string, Texture>()
const looseFrames = new Map<string, Texture>()

const TILE_IDS = [
  1, 2, 3, 4, 5, 6, 7, 8, 9,
  31, 32, 33, 34, 35, 36, 37, 38, 39,
  51, 52, 53, 54, 55, 56, 57, 58, 59,
  71, 72, 73, 74, 75, 76, 77,
  91, 92, 93, 94, 95, 96, 97, 98,
]

function faceKey(size: TileFaceSize, tile: number): string {
  return `${size}_${tile}`
}

function roundRect(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  r: number,
): void {
  const rr = Math.min(r, w / 2, h / 2)
  ctx.beginPath()
  ctx.moveTo(x + rr, y)
  ctx.arcTo(x + w, y, x + w, y + h, rr)
  ctx.arcTo(x + w, y + h, x, y + h, rr)
  ctx.arcTo(x, y + h, x, y, rr)
  ctx.arcTo(x, y, x + w, y, rr)
  ctx.closePath()
}

function makeFaceTexture(tile: number, size: TileFaceSize): Texture {
  const key = faceKey(size, tile)
  const cached = proceduralFaces.get(key)
  if (cached) return cached
  const w = size === 'big' ? 64 : 40
  const h = size === 'big' ? 88 : 56
  const canvas = document.createElement('canvas')
  canvas.width = w
  canvas.height = h
  const ctx = canvas.getContext('2d')!
  ctx.clearRect(0, 0, w, h)
  ctx.fillStyle = tileColor(tile)
  ctx.font = `bold ${size === 'big' ? 22 : 14}px "PingFang SC","Microsoft YaHei",sans-serif`
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText(tileLabel(tile), w / 2, h / 2)
  const tex = Texture.from(canvas)
  proceduralFaces.set(key, tex)
  return tex
}

function makeFrameTexture(frameKey: FrameKey): Texture {
  const cached = proceduralFrames.get(frameKey)
  if (cached) return cached
  const sizes: Record<FrameKey, { w: number; h: number }> = {
    hand: { w: 78, h: 118 },
    up: { w: 46, h: 72 },
    desk: { w: 46, h: 72 },
    sideHand: { w: 24, h: 63 },
    sideDesk: { w: 49, h: 49 },
    sideDown: { w: 52, h: 49 },
    topHand: { w: 41, h: 62 },
    back: { w: 46, h: 72 },
  }
  const { w, h } = sizes[frameKey] || sizes.desk
  const canvas = document.createElement('canvas')
  canvas.width = w
  canvas.height = h
  const ctx = canvas.getContext('2d')!
  const isBack = frameKey === 'back' || frameKey === 'sideDown'
  roundRect(ctx, 1, 1, w - 2, h - 2, 6)
  if (isBack) {
    const g = ctx.createLinearGradient(0, 0, w, h)
    g.addColorStop(0, '#1a6b4a')
    g.addColorStop(1, '#0d4a32')
    ctx.fillStyle = g
    ctx.fill()
  } else {
    const g = ctx.createLinearGradient(0, 0, 0, h)
    g.addColorStop(0, '#f7f2e6')
    g.addColorStop(1, '#d8c9a4')
    ctx.fillStyle = g
    ctx.fill()
    roundRect(ctx, w * 0.12, h * 0.18, w * 0.76, h * 0.62, 4)
    ctx.fillStyle = '#fffef8'
    ctx.fill()
  }
  const tex = Texture.from(canvas)
  proceduralFrames.set(frameKey, tex)
  return tex
}

export function getFaceTexture(tile: number, size: TileFaceSize = 'big'): Texture | null {
  if (!Number.isFinite(tile) || tile <= 0) return null
  return looseFaces.get(faceKey(size, tile)) || makeFaceTexture(tile, size)
}

export function getFrameTexture(frameKey: FrameKey | string): Texture {
  const key = String(frameKey || 'desk') as FrameKey
  return looseFrames.get(key) || makeFrameTexture(key)
}

export function hzmjTileLoadMode(): typeof mode {
  return mode
}

async function loadLooseTiles(): Promise<boolean> {
  const jobs: Array<Promise<void>> = []
  for (const id of TILE_IDS) {
    const src = hzmjFace(id, 'big')
    jobs.push(
      Assets.load(src)
        .then((tex) => {
          if (tex) {
            looseFaces.set(faceKey('big', id), tex as Texture)
            looseFaces.set(faceKey('small', id), tex as Texture)
          }
        })
        .catch(() => undefined),
    )
  }
  for (const [frame, src] of Object.entries(HZMJ_ART_FRAMES) as Array<[FrameKey, string]>) {
    jobs.push(
      Assets.load(src)
        .then((tex) => {
          if (tex) looseFrames.set(frame, tex as Texture)
        })
        .catch(() => undefined),
    )
  }
  await Promise.all(jobs)
  return looseFaces.has(faceKey('big', 75)) && looseFrames.has('hand')
}

export async function loadHzmjTileAssets(): Promise<'loose' | 'procedural'> {
  if (mode === 'loose' || mode === 'procedural') return mode
  const ok = await loadLooseTiles()
  mode = ok ? 'loose' : 'procedural'
  return mode
}
