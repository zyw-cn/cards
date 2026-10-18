/** 炸金花音效门面：散文件在 public/assets/audio/flower-sfx */
import { audioManager } from '@/services/audioManager'

export function playFlowerSfx(key: string): void {
  if (!key) return
  audioManager.playEffect(key, 'flower-sfx')
}

export function playFlowerBgm(): void {
  audioManager.playPackBGM('flower-sfx')
}

export function stopFlowerBgm(): void {
  audioManager.stopBGM()
}
