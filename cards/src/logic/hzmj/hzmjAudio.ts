/**
 * 红中麻将音效 / 语音（文件已在 public/assets/audio，只接 audioManager）
 * 效果：hzmj-sfx sprite key（deal/select/tileout/pong/…）
 * 语音：hzmj-voice-{female|male} → sound_{woman|man}_{tile|op}
 */
import { audioManager } from '@/services/audioManager'
import type { AudioPackId } from '@/config/assetManifest'

const OP_VOICE = new Set(['pong', 'kong', 'chow', 'win', 'draw_win', 'ting', 'buhua'])

export function hzmjVoicePack(sex?: number): AudioPackId {
  return Number(sex) === 1 ? 'hzmj-voice-male' : 'hzmj-voice-female'
}

export function hzmjVoicePrefix(sex?: number): string {
  return Number(sex) === 1 ? 'sound_man' : 'sound_woman'
}

/** 短效果：deal / select / tileout / pong / kong / chow / win / … */
export function playHzmjSfx(key: string): void {
  if (!key) return
  audioManager.playEffect(key, 'hzmj-sfx')
}

/** 出牌语音 + 出牌音效 */
export function playHzmjDiscard(tile: number, sex = 0): void {
  playHzmjSfx('tileout')
  const id = Number(tile)
  if (!Number.isFinite(id) || id <= 0) return
  audioManager.playEffect(`${hzmjVoicePrefix(sex)}_${id}`, hzmjVoicePack(sex))
}

/** 碰/杠/胡等操作语音 + 对应效果音 */
export function playHzmjOp(op: string, sex = 0): void {
  const key = String(op || '').trim().toLowerCase()
  if (!key) return
  if (OP_VOICE.has(key)) {
    playHzmjSfx(key === 'draw_win' ? 'draw_win' : key)
    audioManager.playEffect(`${hzmjVoicePrefix(sex)}_${key}`, hzmjVoicePack(sex))
    return
  }
  playHzmjSfx(key)
}

export function playHzmjBgm(): void {
  audioManager.playPackBGM('hzmj-sfx')
}

export function stopHzmjBgm(): void {
  audioManager.stopBGM()
}
