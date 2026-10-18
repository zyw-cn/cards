/**
 * 小图：src/assets/art，打进包。
 * 大图：只在 public/artres 的背景、立绘、牌桌。配了 VITE_CDN_URL 时走 CDN，不打进生产包。
 * 音频不走这里，由 assetUrl 指向 CDN 的 assets/audio。
 */
import { gameState } from '@/services/gameState'
import { isLocalAssetMode } from '@/services/assetUrl'

const bundledModules = import.meta.glob('../assets/art/**/*.{png,jpg,jpeg,webp,gif}', {
  eager: true,
  query: '?url',
  import: 'default',
})

/** @type {Record<string, string>} rel → hashed URL */
const bundled = Object.create(null)
for (const [file, url] of Object.entries(bundledModules)) {
  const rel = file.replace(/^.*\/assets\/art\//, '')
  if (rel) bundled[rel] = url
}

function publicBase() {
  const base = import.meta.env.BASE_URL || '/'
  return base.endsWith('/') ? base : `${base}/`
}

function withSlash(url) {
  if (!url) return ''
  return url.endsWith('/') ? url : `${url}/`
}

function encodeRel(rel) {
  return rel
    .split('/')
    .filter(Boolean)
    .map((part) => encodeURIComponent(part.replace(/^#/, '')))
    .join('/')
}

/** 大图：本机 public/artres，或 CDN 上的 artres/ */
function publicArtUrl(rel) {
  const encoded = encodeRel(rel)
  if (isLocalAssetMode()) {
    return `${publicBase()}artres/${encoded}`
  }
  return `${withSlash(gameState.cdnUrl)}artres/${encoded}`
}

export function art(...parts) {
  const rel = parts
    .join('/')
    .split('/')
    .filter(Boolean)
    .join('/')
  if (!rel) return publicArtUrl('')
  if (bundled[rel]) return bundled[rel]
  return publicArtUrl(rel)
}

/** 调试/盘点：已打进包的小图数量 */
export function artBundledCount() {
  return Object.keys(bundled).length
}
