/** 斗牛音效门面：散文件在 public/assets/audio/niu-sfx */
import { audioManager } from '@/services/audioManager'

export function playNiuSfx(key: string): void {
  if (!key) return
  audioManager.playEffect(key, 'niu-sfx')
}

export function playNiuBgm(): void {
  audioManager.playPackBGM('niu-sfx')
}

export function stopNiuBgm(): void {
  audioManager.stopBGM()
}

/** 牌型报点：niu0…niu9 / niuniu / wuhua / wuxiao / zhadan */
export function playNiuHandType(typeKey: string): void {
  const k = String(typeKey || '').toLowerCase()
  if (!k) return
  playNiuSfx(k)
}
