/**
 * 斗牛 / 炸金花牌码 A1…D13（1=A … 13=K）→ 图集帧（A14=A，A15=2）。
 * 不依赖 Vue。
 */
export function faceCodeToAtlas(code: unknown): string {
  const raw = String(code ?? '').trim().toUpperCase()
  if (!raw || raw === 'BACK' || raw === '0') return 'BACK'
  const m = raw.match(/^([ABCD])(\d{1,2})$/)
  if (!m) return 'BACK'
  const face = Number(m[2])
  if (face === 1) return `${m[1]}14`
  if (face === 2) return `${m[1]}15`
  if (face >= 3 && face <= 13) return `${m[1]}${face}`
  return 'BACK'
}

export function isFaceCode(code: unknown): boolean {
  return faceCodeToAtlas(code) !== 'BACK'
}
