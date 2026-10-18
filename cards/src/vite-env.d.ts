/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_CDN_URL: string
  /** 推荐：与 game 一致 */
  readonly VITE_API_BASE_URL: string
  readonly VITE_WS_BASE_URL: string
  /** 兼容旧名 */
  readonly VITE_API_BASE?: string
  readonly VITE_WS_URL?: string
  readonly VITE_WS_DEBUG?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<object, object, unknown>
  export default component
}

declare module '*.png' {
  const src: string
  export default src
}
