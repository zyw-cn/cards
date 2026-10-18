/**
 * 素材清单：相对路径均相对 `assets/`（经 assetUrl 解析）。
 */

export type SheetId =
  | 'cards'
  | 'ddz-bomb'
  | 'ddz-plane'
  | 'ddz-spring'
  | 'ddz-liandui'
  | 'ddz-shunzi'
  | 'ddz-wang-bomb'

export type SheetDef =
  | {
      id: SheetId
      kind: 'atlas'
      json: string
      frameHint?: string
    }
  | {
      id: SheetId
      kind: 'image'
      image: string
      cacheKey?: string
    }

export type AudioPackId =
  | 'common-sfx'
  | 'ddz-sfx'
  | 'ddz-voice'
  | 'hzmj-sfx'
  | 'hzmj-voice-female'
  | 'hzmj-voice-male'
  | 'flower-sfx'
  | 'niu-sfx'

export interface AudioPackDef {
  id: AudioPackId
  dir: string
  spriteJson?: string
  bgmFile?: string
}

export const SHEETS: Record<SheetId, SheetDef> = {
  cards: {
    id: 'cards',
    kind: 'atlas',
    json: 'images/card/card.json',
    frameHint: '帧名=协议码',
  },
  'ddz-bomb': { id: 'ddz-bomb', kind: 'atlas', json: 'images/ddz/bomb/bomb.json' },
  'ddz-plane': { id: 'ddz-plane', kind: 'atlas', json: 'images/ddz/plane/plane.json' },
  'ddz-spring': { id: 'ddz-spring', kind: 'atlas', json: 'images/ddz/spring/spring.json' },
  'ddz-liandui': { id: 'ddz-liandui', kind: 'atlas', json: 'images/ddz/liandui/liandui.json' },
  'ddz-shunzi': { id: 'ddz-shunzi', kind: 'atlas', json: 'images/ddz/shunzi/shunzi.json' },
  'ddz-wang-bomb': {
    id: 'ddz-wang-bomb',
    kind: 'atlas',
    json: 'images/ddz/wang_bomb/wang_bomb.json',
  },
}

export const AUDIO_PACKS: Record<AudioPackId, AudioPackDef> = {
  'common-sfx': {
    id: 'common-sfx',
    dir: 'audio/common-sfx',
    bgmFile: 'bgm.mp3',
  },
  'ddz-sfx': {
    id: 'ddz-sfx',
    dir: 'audio/ddz-sfx',
    spriteJson: 'audio/ddz-sfx/ddz_sfx_audio_sprite_mp3.json',
    bgmFile: 'bgm.mp3',
  },
  'ddz-voice': {
    id: 'ddz-voice',
    dir: 'audio/ddz-voice',
    spriteJson: 'audio/ddz-voice/ddz_voice_audio_sprite_mp3.json',
  },
  'hzmj-sfx': {
    id: 'hzmj-sfx',
    dir: 'audio/hzmj-sfx',
    spriteJson: 'audio/hzmj-sfx/hzmj_sfx_audio_sprite_mp3.json',
    bgmFile: 'bgm.mp3',
  },
  'hzmj-voice-female': {
    id: 'hzmj-voice-female',
    dir: 'audio/hzmj-voice-female',
    spriteJson: 'audio/hzmj-voice-female/hzmj_female_audio_sprite_mp3.json',
  },
  'hzmj-voice-male': {
    id: 'hzmj-voice-male',
    dir: 'audio/hzmj-voice-male',
    spriteJson: 'audio/hzmj-voice-male/hzmj_voice_male_audio_sprite_mp3.json',
  },
  'flower-sfx': { id: 'flower-sfx', dir: 'audio/flower-sfx', bgmFile: 'bgm.mp3' },
  'niu-sfx': { id: 'niu-sfx', dir: 'audio/niu-sfx', bgmFile: 'bgm.mp3' },
}

export const DDZ_TABLE_SHEETS: SheetId[] = [
  'cards',
  'ddz-bomb',
  'ddz-plane',
  'ddz-spring',
  'ddz-liandui',
  'ddz-shunzi',
  'ddz-wang-bomb',
]

export const DDZ_TABLE_AUDIO: AudioPackId[] = ['common-sfx', 'ddz-sfx', 'ddz-voice']

export const HZMJ_TABLE_AUDIO: AudioPackId[] = [
  'common-sfx',
  'hzmj-sfx',
  'hzmj-voice-female',
  'hzmj-voice-male',
]

export const FLOWER_TABLE_AUDIO: AudioPackId[] = ['common-sfx', 'flower-sfx']
export const NIU_TABLE_AUDIO: AudioPackId[] = ['common-sfx', 'niu-sfx']
