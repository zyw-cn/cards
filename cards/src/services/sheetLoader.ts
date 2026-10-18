import { Assets, Rectangle, Sprite, Texture } from 'pixi.js'
import { SHEETS, type SheetId } from '../config/assetManifest'
import { assetUrl } from './assetUrl'
import { loadLocalTexture, readText } from './readLocal'

type TpFrame = {
  frame: { x: number; y: number; w: number; h: number }
  rotated?: boolean
  trimmed?: boolean
  spriteSourceSize?: { x: number; y: number; w: number; h: number }
  sourceSize?: { w: number; h: number }
}

type ClassicSheet = {
  frames: Record<string, TpFrame>
  meta: { image: string; size?: { w: number; h: number }; scale?: string | number }
}

const loaded = new Set<SheetId>()
const loading = new Map<SheetId, Promise<void>>()
const frameOwners = new Map<string, SheetId>()

/**
 * 预加载雪碧图或单图。JSON atlas / 单张 PNG 均经 assetUrl。
 */
export async function preloadSheets(ids: SheetId[]): Promise<void> {
  await Promise.all(ids.map((id) => preloadSheet(id)))
}

export async function preloadSheet(id: SheetId): Promise<void> {
  if (loaded.has(id)) return
  const pending = loading.get(id)
  if (pending) return pending

  const def = SHEETS[id]
  if (!def) {
    throw new Error(`[sheetLoader] 未知 sheet: ${id}`)
  }

  const task = (async () => {
    if (def.kind === 'image') {
      await loadSingleImage(id, def.image, def.cacheKey || id)
    } else {
      await loadAtlas(id, def.json)
    }
    loaded.add(id)
    loading.delete(id)
  })().catch((err) => {
    loading.delete(id)
    throw err
  })

  loading.set(id, task)
  return task
}

export function isSheetLoaded(id: SheetId): boolean {
  return loaded.has(id)
}

export function getSheetTexture(frameKey: string): Texture {
  const tex = Assets.get<Texture>(frameKey) || Assets.cache.get(frameKey)
  if (!tex) {
    throw new Error(`[sheetLoader] 帧未加载: ${frameKey}`)
  }
  return tex as Texture
}

export function pokerCardFrame(cardId: number | string): string {
  return String(cardId)
}

export function getPokerCardTexture(cardId: number | string): Texture {
  return getSheetTexture(pokerCardFrame(cardId))
}

/** 创建扑克精灵（调用方负责 addChild / destroy） */
export function createPokerCardSprite(cardId: number | string): Sprite {
  return new Sprite(getPokerCardTexture(cardId))
}

export function unloadSheets(ids: SheetId[]): void {
  for (const id of ids) {
    if (!loaded.has(id)) continue
    for (const [frameKey, owner] of [...frameOwners.entries()]) {
      if (owner !== id) continue
      Assets.cache.remove(frameKey)
      frameOwners.delete(frameKey)
    }
    loaded.delete(id)
  }
}

async function loadSingleImage(id: SheetId, imageRel: string, cacheKey: string): Promise<void> {
  const tex = await loadLocalTexture(assetUrl(imageRel))
  Assets.cache.set(cacheKey, tex)
  frameOwners.set(cacheKey, id)
}

async function loadAtlas(id: SheetId, jsonRel: string): Promise<void> {
  const jsonUrl = assetUrl(jsonRel)
  const raw = JSON.parse(await readText(jsonUrl)) as Record<string, unknown>
  const classic = normalizeAtlas(raw, jsonRel)

  const imageRel = resolveImageRel(jsonRel, classic.meta.image)
  const base = await loadLocalTexture(assetUrl(imageRel))

  for (const [frameKey, f] of Object.entries(classic.frames)) {
    const { x, y, w, h } = f.frame
    const frame = new Rectangle(x, y, w, h)
    const frameTex = new Texture({
      source: base.source,
      frame,
      orig: new Rectangle(0, 0, w, h),
    })
    frameTex.updateUvs()
    Assets.cache.set(frameKey, frameTex)
    frameOwners.set(frameKey, id)
  }
}

function normalizeAtlas(raw: Record<string, unknown>, jsonRel: string): ClassicSheet {
  if (raw.frames && typeof raw.frames === 'object' && !Array.isArray(raw.frames)) {
    const meta = (raw.meta || {}) as ClassicSheet['meta']
    if (!meta.image) {
      meta.image = guessPngName(jsonRel)
    }
    return { frames: raw.frames as ClassicSheet['frames'], meta }
  }

  const textures = raw.textures as Array<{
    image: string
    size?: { w: number; h: number }
    scale?: number
    frames: Array<TpFrame & { filename: string }>
  }> | undefined

  if (Array.isArray(textures) && textures[0]) {
    const page = textures[0]
    const frames: ClassicSheet['frames'] = {}
    for (const f of page.frames) {
      const { filename, ...rest } = f
      frames[filename] = rest
    }
    let image = page.image
    if (image && !/\.(png|jpg|jpeg|webp)$/i.test(image)) {
      image = `${image}.png`
    }
    return {
      frames,
      meta: {
        image: image || guessPngName(jsonRel),
        size: page.size,
        scale: page.scale,
      },
    }
  }

  throw new Error(`[sheetLoader] 无法识别 atlas 格式: ${jsonRel}`)
}

function guessPngName(jsonRel: string): string {
  const base = jsonRel.split('/').pop() || 'sheet.json'
  return base.replace(/\.json$/i, '.png')
}

function resolveImageRel(jsonRel: string, imageName: string): string {
  if (imageName.includes('/')) {
    return normalizeAssetPath(imageName)
  }
  const dir = jsonRel.includes('/') ? jsonRel.slice(0, jsonRel.lastIndexOf('/')) : ''
  return dir ? `${dir}/${imageName}` : imageName
}

function normalizeAssetPath(p: string): string {
  return p.replace(/^\/+/, '').replace(/^assets\//, '')
}
