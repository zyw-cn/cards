import { reactive } from 'vue'

/**
 * WS / 网络 UI 状态（给断线遮罩用，不另起 Pinia）
 */
export const wsUi = reactive({
  ready: false,
  connecting: false,
  /** 底层是否会自动重连（非主动 disconnect） */
  willAutoReconnect: false,
  reconnectAttempts: 0,
  /** 重连后正在 Join 同步牌局 */
  roomResyncing: false,
  /** 浏览器 navigator.onLine */
  browserOnline: typeof navigator === 'undefined' ? true : navigator.onLine,
})

export function setWsRoomResyncing(on: boolean): void {
  wsUi.roomResyncing = !!on
}

export function syncBrowserOnline(): void {
  if (typeof navigator === 'undefined') return
  wsUi.browserOnline = navigator.onLine
}
