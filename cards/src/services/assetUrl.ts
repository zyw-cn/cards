import { gameState } from './gameState'

function publicBase() {
  const base = import.meta.env.BASE_URL || '/'
  return base.endsWith('/') ? base : `${base}/`
}

export function isLocalAssetMode() {
  const cdn = (gameState.cdnUrl || '').trim().toLowerCase()
  if (!cdn) return true
  if (cdn === 'local' || cdn === 'local/') return true
  if (cdn.includes('example.com')) return true
  return false
}

export function normalizeAssetRel(relPath) {
  let rel = relPath.trim().replace(/^\/+/, '')
  if (rel.startsWith('assets/')) {
    rel = rel.slice('assets/'.length)
  }
  return rel
}

/**
 * 音频等：可走 VITE_CDN_URL；未配置则走本机 public/assets（APK 相对路径）
 */
export function assetUrl(relPath) {
  const rel = normalizeAssetRel(relPath)
  if (!rel) {
    return isLocalAssetMode() ? `${publicBase()}assets/` : withSlash(gameState.cdnUrl) + 'assets/'
  }

  if (isLocalAssetMode()) {
    return `${publicBase()}assets/${rel}`
  }

  return `${withSlash(gameState.cdnUrl)}assets/${rel}`
}

function withSlash(url) {
  if (!url) return ''
  return url.endsWith('/') ? url : `${url}/`
}
