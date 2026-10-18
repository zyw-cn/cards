/**
 * 桌心罗盘、出牌指针、癞子角标。图用旧房间散图，音效不在这里。
 */
import { Assets, Texture } from 'pixi.js'
import {
  hzmjCompassBg,
  hzmjCompassDirs,
  hzmjKingMark,
  hzmjPointer,
} from '@/logic/hzmj/hzmjAssets'

const ALIAS = {
  bg: 'hzmj-compass-bg',
  dirs: ['hzmj-dir-0', 'hzmj-dir-1', 'hzmj-dir-2', 'hzmj-dir-3'],
  pointer: 'hzmj-pointer',
  king: 'hzmj-king-mark',
}

let loaded = false

async function loadOne(alias: string, src: string): Promise<void> {
  try {
    if (Assets.cache.has(alias)) return
    const tex = (await Assets.load(src)) as Texture
    if (tex instanceof Texture) {
      Assets.cache.set(alias, tex)
    }
  } catch (err) {
    console.warn('[hzmj] chrome load fail', alias, src, err)
  }
}

export async function loadHzmjChrome(): Promise<void> {
  if (loaded) return
  await Promise.all([
    loadOne(ALIAS.bg, hzmjCompassBg),
    ...hzmjCompassDirs.map((src, i) => loadOne(ALIAS.dirs[i], src)),
    loadOne(ALIAS.pointer, hzmjPointer),
    loadOne(ALIAS.king, hzmjKingMark),
  ])
  loaded = true
}

export function chromeTexture(alias: string): Texture | null {
  if (!Assets.cache.has(alias)) return null
  const tex = Assets.get(alias)
  return tex instanceof Texture ? tex : null
}

export function compassBgTexture(): Texture | null {
  return chromeTexture(ALIAS.bg)
}

export function compassDirTexture(wind: number): Texture | null {
  return chromeTexture(ALIAS.dirs[wind] || '')
}

export function pointerTexture(): Texture | null {
  return chromeTexture(ALIAS.pointer)
}

export function kingMarkTexture(): Texture | null {
  return chromeTexture(ALIAS.king)
}
