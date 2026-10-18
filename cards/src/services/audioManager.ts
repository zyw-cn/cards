import { Howl, Howler } from 'howler'
import { AUDIO_PACKS, type AudioPackId } from '../config/assetManifest'
import { assetUrl } from './assetUrl'
import { localAssetSrc, readText } from './readLocal'

/** 默认 10 个 HTML5 Audio；发牌连打 / BGM 会耗尽并打出 pool exhausted */
Howler.html5PoolSize = 32
/** 关闭 Howler 自带解锁，避免与手势门闩抢跑、重复 resume 报警告 */
Howler.autoUnlock = false

const MUTE_STORAGE_KEY = 'qipai_audio_muted'
const BGM_MUTE_STORAGE_KEY = 'qipai_bgm_muted'

function readMutedPref(): boolean {
  try {
    return localStorage.getItem(MUTE_STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

function writeMutedPref(muted: boolean): void {
  try {
    localStorage.setItem(MUTE_STORAGE_KEY, muted ? '1' : '0')
  } catch {
    /* ignore */
  }
}

function readBgmMutedPref(): boolean {
  try {
    return localStorage.getItem(BGM_MUTE_STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

function writeBgmMutedPref(muted: boolean): void {
  try {
    localStorage.setItem(BGM_MUTE_STORAGE_KEY, muted ? '1' : '0')
  } catch {
    /* ignore */
  }
}

/** 跨热更新仍能停掉已经在播的循环背景音 */
const BGM_REGISTRY = '__qipaiBgmAudios'

function bgmRegistry(): Set<HTMLAudioElement> {
  const host = window as unknown as Record<string, Set<HTMLAudioElement> | undefined>
  let set = host[BGM_REGISTRY]
  if (!set) {
    set = new Set()
    host[BGM_REGISTRY] = set
  }
  return set
}

function silenceHtmlAudio(audio: HTMLAudioElement): void {
  audio.loop = false
  audio.muted = true
  audio.volume = 0
  audio.onplaying = null
  try {
    audio.pause()
  } catch {
    /* ignore */
  }
  try {
    audio.currentTime = 0
  } catch {
    /* ignore */
  }
  try {
    audio.src = ''
    audio.removeAttribute('src')
    audio.load()
  } catch {
    /* ignore */
  }
}

/** 是否已有用户手势；未解锁前禁止创建 / resume AudioContext */
let audioUnlocked = false
const unlockWaiters: Array<() => void> = []

function notifyAudioUnlocked(): void {
  const q = unlockWaiters.splice(0)
  q.forEach((fn) => fn())
}

/** 等首次点击/按键后再加载 WebAudio，避免 Chrome autoplay 警告 */
function whenAudioUnlocked(): Promise<void> {
  if (audioUnlocked) return Promise.resolve()
  return new Promise((resolve) => {
    unlockWaiters.push(resolve)
  })
}

function unlockHowler(): void {
  if (!audioUnlocked) return
  try {
    const ctx = Howler.ctx
    if (ctx && ctx.state === 'suspended') void ctx.resume()
  } catch {
    /* 浏览器未建 AudioContext */
  }
}

function markAudioUnlocked(): void {
  if (audioUnlocked) {
    unlockHowler()
    return
  }
  audioUnlocked = true
  try {
    Howler.mute(readMutedPref())
  } catch {
    /* ignore */
  }
  unlockHowler()
  notifyAudioUnlocked()
}

if (typeof window !== 'undefined') {
  const once = () => {
    markAudioUnlocked()
    window.removeEventListener('pointerdown', once)
    window.removeEventListener('touchstart', once)
    window.removeEventListener('keydown', once)
  }
  window.addEventListener('pointerdown', once, { passive: true })
  window.addEventListener('touchstart', once, { passive: true })
  window.addEventListener('keydown', once)
}

type SpriteMap = Record<string, [number, number] | [number, number, boolean]>

interface HowlerSpriteJson {
  resources: string[]
  spritemap: Record<string, { start: number; end: number; loop?: boolean }>
}

interface LoadedPack {
  id: AudioPackId
  howl: Howl | null
  /** spritemap key → 已在 howl 内 */
  spriteKeys: Set<string>
}

function normalizeKey(fileName: string): string {
  return fileName.trim().replace(/\.(mp3|ogg|m4a|wav)$/i, '')
}

function toHowlerSprite(map: HowlerSpriteJson['spritemap']): SpriteMap {
  const out: SpriteMap = {}
  for (const [key, seg] of Object.entries(map)) {
    const startMs = Math.round(seg.start)
    const durationMs = Math.max(0, Math.round(seg.end - seg.start))
    out[key] = seg.loop ? [startMs, durationMs, true] : [startMs, durationMs]
  }
  return out
}

class AudioManager {
  private bgm: Howl | null = null
  /** 背景音乐不用 Howler 循环，避免播放锁把 stop 吞掉后一直响 */
  private bgmAudio: HTMLAudioElement | null = null
  private currentBgmKey = ''
  /** 当前场景要播的背景音乐；关掉后再打开会播这一首 */
  private desiredBgm: { fileName: string; packId?: AudioPackId } | null = null
  /** play() 被自动播放策略拒绝后，挂到下一次点击上再播 */
  private bgmKick: (() => void) | null = null
  private readonly effectCache = new Map<string, Howl>()
  private readonly packs = new Map<AudioPackId, LoadedPack>()
  private readonly loadingPacks = new Map<AudioPackId, Promise<void>>()
  /** 播放时按序查找的 pack（后加载的靠前） */
  private searchOrder: AudioPackId[] = []
  private effectVolume = 1
  private bgmVolume = 0.45
  private muted = readMutedPref()
  /** 仅背景音乐；音效仍可播 */
  private bgmMuted = readBgmMutedPref()
  /** 同 key 最短间隔，避免发牌把 HTML5 池打爆 */
  private readonly lastEffectAt = new Map<string, number>()
  private readonly effectMinGapMs = 80

  constructor() {
    // 勿在此调用 Howler.mute：会创建 AudioContext，触发 Chrome autoplay 警告
  }

  isMuted(): boolean {
    return this.muted
  }

  isBgmMuted(): boolean {
    return this.bgmMuted
  }

  setMuted(muted: boolean): void {
    this.muted = !!muted
    writeMutedPref(this.muted)
    if (audioUnlocked) {
      try {
        Howler.mute(this.muted)
      } catch {
        /* ignore */
      }
    }
  }

  setBgmMuted(muted: boolean): void {
    this.bgmMuted = !!muted
    writeBgmMutedPref(this.bgmMuted)
    if (this.bgmMuted) {
      this.clearBgmKick()
      this.stopBGM()
    } else {
      this.startDesiredBgm()
    }
  }

  toggleMuted(): boolean {
    this.setMuted(!this.muted)
    return this.muted
  }

  toggleBgmMuted(): boolean {
    this.setBgmMuted(!this.bgmMuted)
    return this.bgmMuted
  }

  /** 业务侧也可在明确手势里再调一次（如点开始游戏） */
  unlock(): void {
    markAudioUnlocked()
  }

  async preloadPack(id: AudioPackId): Promise<void> {
    if (this.packs.has(id)) return
    const pending = this.loadingPacks.get(id)
    if (pending) return pending

    const def = AUDIO_PACKS[id]
    if (!def) throw new Error(`[audioManager] 未知音效包: ${id}`)

    const task = (async () => {
      // 等用户手势后再 new Howl(WebAudio)，否则会报 AudioContext was not allowed to start
      await whenAudioUnlocked()

      if (!def.spriteJson) {
        this.packs.set(id, { id, howl: null, spriteKeys: new Set() })
        this.bumpSearch(id)
        this.loadingPacks.delete(id)
        return
      }

      const jsonUrl = assetUrl(def.spriteJson)
      const data = JSON.parse(await readText(jsonUrl)) as HowlerSpriteJson
      const resourceName = data.resources?.[0]
      if (!resourceName || !data.spritemap) {
        throw new Error(`[audioManager] sprite json 格式无效: ${jsonUrl}`)
      }

      const mp3Url = await localAssetSrc(assetUrl(`${def.dir}/${resourceName}`))
      const sprite = toHowlerSprite(data.spritemap)
      const howl = new Howl({
        src: [mp3Url],
        sprite,
        html5: false,
        preload: true,
        pool: 8,
        volume: this.effectVolume,
      })

      await new Promise<void>((resolve, reject) => {
        if (howl.state() === 'loaded') {
          resolve()
          return
        }
        howl.once('load', () => resolve())
        howl.once('loaderror', (_id, err) => reject(err))
      })

      this.packs.set(id, {
        id,
        howl,
        spriteKeys: new Set(Object.keys(data.spritemap)),
      })
      this.bumpSearch(id)
      this.loadingPacks.delete(id)
    })().catch((err) => {
      this.loadingPacks.delete(id)
      throw err
    })

    this.loadingPacks.set(id, task)
    return task
  }

  async preloadPacks(ids: AudioPackId[]): Promise<void> {
    await Promise.all(ids.map((id) => this.preloadPack(id)))
  }

  playBGM(fileName: string, packId?: AudioPackId): Howl | null {
    this.desiredBgm = { fileName, packId }
    if (this.bgmMuted) {
      this.stopBGM()
      return null
    }
    this.startDesiredBgm()
    return null
  }

  /** 音乐开着且已经选过曲目时，把手势解锁后的播放补上 */
  private startDesiredBgm(): void {
    const desired = this.desiredBgm
    if (!desired || this.bgmMuted) return
    if (!audioUnlocked) {
      this.armBgmOnNextGesture()
      return
    }
    unlockHowler()
    const key = normalizeKey(desired.fileName)
    const cacheKey = desired.packId ? `${desired.packId}:${key}` : key
    if (this.bgmAudio && this.currentBgmKey === cacheKey && this.isBgmAudible(this.bgmAudio)) {
      return
    }

    const src = desired.packId
      ? assetUrl(`${AUDIO_PACKS[desired.packId].dir}/${this.withExt(desired.fileName)}`)
      : assetUrl(`audio/${this.withExt(desired.fileName)}`)

    this.stopHtmlBgm()
    const audio = new Audio()
    audio.loop = true
    audio.muted = false
    audio.volume = this.bgmVolume
    audio.preload = 'auto'
    audio.src = src
    this.bgmAudio = audio
    this.currentBgmKey = cacheKey
    bgmRegistry().add(audio)
    const pending = audio.play()
    if (pending) {
      void pending.catch(() => {
        if (this.bgmAudio !== audio || this.bgmMuted) return
        this.armBgmOnNextGesture()
      })
    }
  }

  /** 浏览器拒绝自动播放时，下一次点击再播。同一次手势里直接 play() 的不会走到这里。 */
  private armBgmOnNextGesture(): void {
    if (this.bgmKick || this.bgmMuted) return
    const kick = () => {
      this.bgmKick = null
      window.removeEventListener('pointerdown', kick)
      if (!this.bgmMuted) this.startDesiredBgm()
    }
    this.bgmKick = kick
    window.addEventListener('pointerdown', kick)
  }

  private clearBgmKick(): void {
    if (!this.bgmKick) return
    window.removeEventListener('pointerdown', this.bgmKick)
    this.bgmKick = null
  }

  private isBgmAudible(audio: HTMLAudioElement): boolean {
    return !audio.paused && !audio.muted && audio.volume > 0 && !!audio.currentSrc && !audio.ended
  }

  /** 播放包内 BGM 文件（如 ddz-sfx/bgm.mp3） */
  playPackBGM(packId: AudioPackId): Howl | null {
    const def = AUDIO_PACKS[packId]
    if (!def?.bgmFile) return null
    return this.playBGM(def.bgmFile, packId)
  }

  stopBGM(): void {
    this.stopHtmlBgm()
    this.bgm = null
    this.currentBgmKey = ''
    this.stopHowlerBgm()
  }

  private stopHtmlBgm(): void {
    this.bgmAudio = null
    this.currentBgmKey = ''
    const set = bgmRegistry()
    const playing = [...set]
    set.clear()
    for (const audio of playing) silenceHtmlAudio(audio)
  }

  /** 关掉音乐时，把 Howler 里仍在循环的 bgm 一并停掉 */
  private stopHowlerBgm(): void {
    const howls = (Howler as unknown as { _howls?: Howl[] })._howls ?? []
    for (const howl of [...howls]) {
      const raw = howl as unknown as { _src?: string; _loop?: boolean }
      const src = String(raw._src || '')
      if (/bgm\.mp3/i.test(src)) this.silenceHowl(howl)
    }
  }

  /**
   * Howler 在 html5 播放锁期间只把 stop 放进队列，unload 后又把节点弄丢，循环音乐会继续响。
   * 这里直接暂停底层音频再卸载。
   */
  private silenceHowl(howl: Howl): void {
    const raw = howl as unknown as {
      _sounds?: Array<{
        _paused?: boolean
        _ended?: boolean
        _node?: HTMLMediaElement & { bufferSource?: { stop?: (when?: number) => void } }
      }>
      _queue?: unknown[]
      _playLock?: boolean
    }
    raw._queue = []
    raw._playLock = false
    for (const sound of raw._sounds ?? []) {
      sound._paused = true
      sound._ended = true
      const node = sound._node
      if (!node) continue
      if (typeof node.pause === 'function') {
        node.loop = false
        node.muted = true
        node.volume = 0
        try {
          node.pause()
        } catch {
          /* ignore */
        }
        try {
          node.removeAttribute('src')
          node.load()
        } catch {
          /* ignore */
        }
      }
      try {
        node.bufferSource?.stop?.(0)
      } catch {
        /* ignore */
      }
    }
    try {
      howl.volume(0)
      howl.stop()
      howl.unload()
    } catch {
      /* ignore */
    }
  }

  /**
   * 播放短音效。优先已加载 pack 的 spritemap key，否则回落散文件。
   * @param fileName 逻辑名，如 `deal` / `bomb`
   * @param packId 可选，限定某个包；省略则按 searchOrder 查找
   */
  playEffect(fileName: string, packId?: AudioPackId): number {
    if (this.muted) return -1
    if (this.bgmMuted && normalizeKey(fileName) === 'bgm') return -1
    if (!audioUnlocked) return -1
    const key = normalizeKey(fileName)
    unlockHowler()
    if (!this.allowEffect(packId ? `${packId}:${key}` : key)) return -1

    if (packId) {
      const pack = this.packs.get(packId)
      if (pack?.howl && pack.spriteKeys.has(key)) {
        return pack.howl.play(key)
      }
      return this.playLoose(packId, key)
    }

    for (const id of this.searchOrder) {
      const pack = this.packs.get(id)
      if (pack?.howl && pack.spriteKeys.has(key)) {
        return pack.howl.play(key)
      }
    }

    // 无 sprite：尝试 common-sfx / 第一个已知包散文件
    const fallbackPack: AudioPackId = this.searchOrder[0] || 'common-sfx'
    return this.playLoose(fallbackPack, key)
  }

  setBgmVolume(volume: number): void {
    this.bgmVolume = Math.max(0, Math.min(1, volume))
    this.bgm?.volume(this.bgmVolume)
    if (this.bgmAudio) this.bgmAudio.volume = this.bgmVolume
  }

  setEffectVolume(volume: number): void {
    this.effectVolume = Math.max(0, Math.min(1, volume))
    this.effectCache.forEach((sound) => sound.volume(this.effectVolume))
    this.packs.forEach((pack) => pack.howl?.volume(this.effectVolume))
  }

  unloadEffects(): void {
    this.effectCache.forEach((sound) => sound.unload())
    this.effectCache.clear()
  }

  unloadPack(id: AudioPackId): void {
    const pack = this.packs.get(id)
    if (!pack) return
    pack.howl?.unload()
    this.packs.delete(id)
    this.searchOrder = this.searchOrder.filter((x) => x !== id)
  }

  unloadPacks(ids: AudioPackId[]): void {
    ids.forEach((id) => this.unloadPack(id))
  }

  private bumpSearch(id: AudioPackId): void {
    this.searchOrder = [id, ...this.searchOrder.filter((x) => x !== id)]
  }

  private withExt(fileName: string): string {
    const t = fileName.trim()
    if (/\.(mp3|ogg|m4a|wav)$/i.test(t)) return t
    return `${t}.mp3`
  }

  private allowEffect(stamp: string): boolean {
    const now = Date.now()
    const prev = this.lastEffectAt.get(stamp) || 0
    if (now - prev < this.effectMinGapMs) return false
    this.lastEffectAt.set(stamp, now)
    return true
  }

  private playLoose(packId: AudioPackId, key: string): number {
    const def = AUDIO_PACKS[packId]
    const cacheKey = `${packId}:${key}`
    let sound = this.effectCache.get(cacheKey)
    if (!sound) {
      sound = new Howl({
        src: [assetUrl(`${def.dir}/${key}.mp3`)],
        html5: true,
        loop: false,
        preload: true,
        volume: this.effectVolume,
      })
      this.effectCache.set(cacheKey, sound)
    }
    return sound.play()
  }
}

export const audioManager = new AudioManager()
