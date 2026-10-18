import avatar1 from '@/assets/images/common/head/avatar_1.png'
import avatar2 from '@/assets/images/common/head/avatar_2.png'
import avatar3 from '@/assets/images/common/head/avatar_3.png'
import avatar4 from '@/assets/images/common/head/avatar_4.png'
import avatar5 from '@/assets/images/common/head/avatar_5.png'
import avatar6 from '@/assets/images/common/head/avatar_6.png'
import avatar7 from '@/assets/images/common/head/avatar_7.png'
import avatar8 from '@/assets/images/common/head/avatar_8.png'
import avatar9 from '@/assets/images/common/head/avatar_9.png'
import avatar10 from '@/assets/images/common/head/avatar_10.png'
import avatar11 from '@/assets/images/common/head/avatar_11.png'
import avatar12 from '@/assets/images/common/head/avatar_12.png'
import avatar13 from '@/assets/images/common/head/avatar_13.png'
import avatar14 from '@/assets/images/common/head/avatar_14.png'
import avatar15 from '@/assets/images/common/head/avatar_15.png'
import avatar16 from '@/assets/images/common/head/avatar_16.png'
import avatar17 from '@/assets/images/common/head/avatar17.png'
import avatar18 from '@/assets/images/common/head/avatar_18.png'
import { defaultAvatarKey, pickAvatarRaw, resolveAvatarUrl } from '@/services/avatarUrl'

const LOCAL: Record<string, string> = {
  avatar_1: avatar1,
  avatar_2: avatar2,
  avatar_3: avatar3,
  avatar_4: avatar4,
  avatar_5: avatar5,
  avatar_6: avatar6,
  avatar_7: avatar7,
  avatar_8: avatar8,
  avatar_9: avatar9,
  avatar_10: avatar10,
  avatar_11: avatar11,
  avatar_12: avatar12,
  avatar_13: avatar13,
  avatar_14: avatar14,
  avatar_15: avatar15,
  avatar_16: avatar16,
  avatar17: avatar17,
  avatar_17: avatar17,
  avatar_18: avatar18,
}

/** 解析为 <img :src>：优先 avatar_url 完整地址，空才用本地默认头 */
export function seatAvatarSrc(raw: unknown, uid = 0): string {
  const resolved = resolveAvatarUrl(raw, '', uid)
  if (LOCAL[resolved]) return LOCAL[resolved]
  if (/^https?:\/\//i.test(resolved) || resolved.startsWith('//') || resolved.startsWith('/') || resolved.startsWith('data:')) {
    return resolved
  }
  return LOCAL[defaultAvatarKey(uid)] || avatar1
}

/** 从玩家对象取头像（优先 avatar_url） */
export function playerAvatarSrc(player: Record<string, unknown> | null | undefined, uid = 0): string {
  const id = Number(uid || player?.player_id || player?.playerId || player?.uid || player?.id || 0) || 0
  return seatAvatarSrc(pickAvatarRaw(player), id)
}
