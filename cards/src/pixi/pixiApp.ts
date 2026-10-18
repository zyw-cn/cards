/**
 * Pixi Application 统一创建 / 销毁。
 * 多实例时禁止 destroy(true,true)，否则会清掉全局 TexturePool，导致下一局 context lost。
 */
import { Application } from 'pixi.js'

export type PixiInitSize = { width: number; height: number }

export function pixiResolution(): number {
  return Math.min(typeof window !== 'undefined' ? window.devicePixelRatio || 1 : 1, 2)
}

/** 牌桌 / 特效通用 init 参数 */
export function pixiInitOptions(size: PixiInitSize) {
  return {
    width: size.width,
    height: size.height,
    backgroundAlpha: 0,
    /** 关抗锯齿，降低 GPU / 多 context 压力 */
    antialias: false,
    resolution: pixiResolution(),
    autoDensity: true,
    powerPreference: 'high-performance' as const,
    /** 优先 WebGL，避免部分环境 WebGPU adapter unavailable */
    preference: 'webgl' as const,
    failIfMajorPerformanceCaveat: false,
  }
}

export async function createPixiApp(
  size: PixiInitSize,
  hooks?: { onContextLost?: () => void },
): Promise<Application> {
  const app = new Application()
  await app.init(pixiInitOptions(size))
  const canvas = app.canvas as HTMLCanvasElement
  canvas.addEventListener(
    'webglcontextlost',
    (ev) => {
      ev.preventDefault()
      console.warn('[pixi] WebGL context lost')
      hooks?.onContextLost?.()
    },
    false,
  )
  canvas.addEventListener(
    'webglcontextrestored',
    () => {
      console.warn('[pixi] WebGL context restored')
      hooks?.onContextLost?.()
    },
    false,
  )
  return app
}

/**
 * 安全销毁：停 ticker，卸 canvas，释放本 context，但保留 Assets 全局纹理。
 */
export function destroyPixiApp(app: Application | null | undefined): void {
  if (!app) return
  try {
    app.ticker?.stop()
    app.stop?.()
  } catch {
    /* ignore */
  }
  try {
    app.destroy(
      { removeView: true, releaseGlobalResources: false },
      {
        children: true,
        texture: false,
        textureSource: false,
        context: true,
      },
    )
  } catch (err) {
    console.warn('[pixi] destroy failed', err)
  }
}

export function stylePixiCanvas(
  canvas: HTMLCanvasElement,
  opts?: { pointerEvents?: string; absolute?: boolean; zIndex?: string },
): void {
  canvas.style.width = '100%'
  canvas.style.height = '100%'
  canvas.style.display = 'block'
  canvas.style.objectFit = 'fill'
  canvas.style.pointerEvents = opts?.pointerEvents ?? 'auto'
  if (opts?.absolute) {
    canvas.style.position = 'absolute'
    canvas.style.left = '0'
    canvas.style.top = '0'
    if (opts.zIndex) canvas.style.zIndex = opts.zIndex
  }
}
