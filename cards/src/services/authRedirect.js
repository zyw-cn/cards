import { clearSession } from './session'
import { goLogin } from './gameState'
import { wsService } from './gameSocket'
import { setSuppressAutoResume } from './roomResume'

let redirecting = false

/**
 * Token 失效 / 主动退出：清会话并切登录屏（无 vue-router）。
 */
export function forceLogin() {
  setSuppressAutoResume(true)
  clearSession()

  try {
    wsService.disconnect()
  } catch {
    /* ignore */
  }

  if (redirecting) return
  redirecting = true
  try {
    goLogin()
  } finally {
    redirecting = false
  }
}
