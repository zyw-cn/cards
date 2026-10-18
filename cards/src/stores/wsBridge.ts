/**
 * WebSocket 帧入口：先写用户资料，再交给玩法回调分发。
 * web-ui 无 Pinia game store，这里只做资料同步。
 */
import { applyUserProfile, gameState } from '@/services/gameState'

export function acceptWsPayload(
  _kind: 'notice' | 'message',
  msg: { code?: number; message?: string; data?: unknown },
): void {
  const data = msg.data
  if (!data || typeof data !== 'object') return
  const row = data as Record<string, unknown>
  const uid = Number(row.uid ?? row.playerId ?? row.player_id ?? 0)
  const self = gameState.user.uid
  if (typeof row.token === 'string' && row.token) {
    applyUserProfile(row)
    return
  }
  if (uid > 0 && (self === 0 || uid === self)) {
    applyUserProfile(row)
  }
}
