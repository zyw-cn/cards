import { gameState } from '@/services/gameState'

/**
 * 后台推送优先 avatar_url（完整 CDN/HTTP 地址）。
 * avatar 多为相对路径，仅作次选。
 */
export function pickAvatarRaw(source, fallback = '') {
  if (!source || typeof source !== 'object') return fallback
  const keys = ['avatar_url', 'avatarUrl', 'head_img', 'headimgurl', 'headImg', 'head', 'avatar']
  for (const key of keys) {
    const text = String(source[key] ?? '').trim()
    if (text) return text
  }
  return typeof fallback === 'string' ? fallback : ''
}

/** 无远程头像时按 uid 稳定分配本地头键（仅兜底，有 avatar_url 时不用） */
export function defaultAvatarKey(uid = 0) {
  const n = Math.abs(Number(uid) || 0)
  const idx = n > 0 ? ((n - 1) % 18) + 1 : 1
  return idx === 17 ? 'avatar17' : `avatar_${idx}`
}

export function isLocalAvatarKey(raw) {
  const a = String(raw ?? '').trim()
  return a === 'avatar17' || /^avatar_\d+$/.test(a)
}

/**
 * 收成可展示的地址：优先完整 avatar_url / http(s)；
 * 相对路径拼 apiBase；空则回落 uid 本地键（由 seatAvatarSrc 映射资源）。
 */
export function resolveAvatarUrl(raw, fallback = '', uid = 0) {
  const a = String(raw ?? '').trim()
  if (!a) {
    const fb = typeof fallback === 'string' ? fallback.trim() : ''
    if (fb) return resolveAvatarUrl(fb, '', uid)
    return uid > 0 ? defaultAvatarKey(uid) : ''
  }
  if (isLocalAvatarKey(a)) return a
  if (/^https?:\/\//i.test(a) || a.startsWith('//') || a.startsWith('data:') || a.startsWith('blob:')) {
    return a
  }

  const base = String(gameState.apiBase || '').replace(/\/+$/, '')
  if (a.startsWith('/')) {
    if (!base) return a
    try {
      return new URL(a, `${base}/`).href
    } catch {
      return a
    }
  }
  if (base) return `${base}/${a.replace(/^\/+/, '')}`
  return `/${a}`
}
