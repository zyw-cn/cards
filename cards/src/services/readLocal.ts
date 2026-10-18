/**
 * HBuilder 本地包是 file://。安卓 WebView 的 fetch 不能读这种地址。
 * 运行在 H5+ 里时，用 plus.io 读 _www 下的文件。
 */
import { Assets, Texture } from 'pixi.js'

type PlusFileReader = {
  result: string | ArrayBuffer | null
  onloadend: (() => void) | null
  onerror: (() => void) | null
  readAsText: (file: unknown, encoding?: string) => void
  readAsDataURL: (file: unknown) => void
}

type PlusIo = {
  resolveLocalFileSystemURL: (
    url: string,
    ok: (entry: { file: (ok: (file: unknown) => void, err?: (e: unknown) => void) => void }) => void,
    err?: (e: unknown) => void,
  ) => void
  FileReader: new () => PlusFileReader
}

type PlusRuntime = { io: PlusIo }

function getPlus(): PlusRuntime | null {
  const plus = (globalThis as { plus?: PlusRuntime }).plus
  return plus?.io ? plus : null
}

export function isFilePage(): boolean {
  return typeof location !== 'undefined' && location.protocol === 'file:'
}

/** file:// 页面等 plusready；普通浏览器立刻返回 null */
function whenPlus(): Promise<PlusRuntime | null> {
  const ready = getPlus()
  if (ready) return Promise.resolve(ready)
  if (!isFilePage()) return Promise.resolve(null)
  return new Promise((resolve) => {
    const finish = () => {
      document.removeEventListener('plusready', onReady)
      window.clearTimeout(timer)
      resolve(getPlus())
    }
    const onReady = () => finish()
    document.addEventListener('plusready', onReady)
    const timer = window.setTimeout(finish, 3000)
  })
}

/** ./assets/a.json 或 file://.../www/assets/a.json → _www/assets/a.json */
export function toWwwPath(url: string): string {
  if (url.startsWith('_www/') || url.startsWith('_doc/') || url.startsWith('_downloads/')) {
    return url
  }
  let rel = url
  if (url.startsWith('file:')) {
    const path = decodeURIComponent(url.replace(/^file:\/\//, ''))
    const mark = '/www/'
    const at = path.indexOf(mark)
    rel = at >= 0 ? path.slice(at + mark.length) : path
  } else {
    try {
      const abs = new URL(url, location.href)
      const mark = '/www/'
      const at = abs.pathname.indexOf(mark)
      rel = at >= 0 ? decodeURIComponent(abs.pathname.slice(at + mark.length)) : abs.pathname
    } catch {
      rel = url
    }
  }
  rel = rel.replace(/^\.\//, '').replace(/^\/+/, '')
  if (rel.startsWith('www/')) rel = rel.slice(4)
  return `_www/${rel}`
}

function failMessage(err: unknown, path: string): string {
  if (err && typeof err === 'object' && 'message' in err && (err as { message?: unknown }).message) {
    return `无法读取 ${path}: ${String((err as { message: unknown }).message)}`
  }
  return `无法读取 ${path}`
}

function readPlusFile(path: string, asDataUrl: boolean): Promise<string> {
  return whenPlus().then(
    (plus) =>
      new Promise((resolve, reject) => {
        if (!plus) {
          reject(new Error(`plus.io 不可用: ${path}`))
          return
        }
        plus.io.resolveLocalFileSystemURL(
          path,
          (entry) => {
            entry.file((file) => {
              const reader = new plus.io.FileReader()
              reader.onloadend = () => {
                const text = typeof reader.result === 'string' ? reader.result : ''
                if (!text) {
                  reject(new Error(`无法读取 ${path}`))
                  return
                }
                resolve(text)
              }
              reader.onerror = () => reject(new Error(`无法读取 ${path}`))
              if (asDataUrl) reader.readAsDataURL(file)
              else reader.readAsText(file, 'utf-8')
            }, (err) => reject(new Error(failMessage(err, path))))
          },
          (err) => reject(new Error(failMessage(err, path))),
        )
      }),
  )
}

async function fetchText(url: string): Promise<string> {
  const res = await fetch(url)
  if (!res.ok) throw new Error(`无法加载 ${url}`)
  return res.text()
}

export async function readText(url: string): Promise<string> {
  const plus = await whenPlus()
  if (!plus) return fetchText(url)
  return readPlusFile(toWwwPath(url), false)
}

/** 图片、音频等二进制。H5+ 下得到 data URL，浏览器仍用原地址。 */
export async function localAssetSrc(url: string): Promise<string> {
  const plus = await whenPlus()
  if (!plus) return url
  return readPlusFile(toWwwPath(url), true)
}

export async function loadLocalTexture(url: string): Promise<Texture> {
  const plus = await whenPlus()
  if (!plus) return Assets.load<Texture>(url)
  const dataUrl = await readPlusFile(toWwwPath(url), true)
  const image = await new Promise<HTMLImageElement>((resolve, reject) => {
    const img = new Image()
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error(`无法读取 ${url}`))
    img.src = dataUrl
  })
  return Texture.from(image)
}
