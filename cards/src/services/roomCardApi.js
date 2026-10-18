import { http } from './http'
import { applyUserProfile } from './session'

/**
 * 启用中的房卡类型
 * @returns {Promise<Array<{id:number,name:string,price:string,round_count:number}>>}
 */
export async function fetchRoomCardTypes() {
  const res = await http.post('/api/room-card/types', {})
  if (res.code !== 0) throw new Error(res.msg || '获取房卡类型失败')
  const list = res.data?.list
  return Array.isArray(list) ? list : []
}

/**
 * 我的房卡（并刷新大厅总张数）
 * @returns {Promise<{list: Array, total: number}>}
 */
export async function fetchMyRoomCards() {
  const res = await http.post('/api/room-card/mine', {})
  if (res.code !== 0) throw new Error(res.msg || '获取房卡失败')
  const list = Array.isArray(res.data?.list) ? res.data.list : []
  const total = Number(res.data?.total ?? 0)
  if (Number.isFinite(total) && total >= 0) {
    applyUserProfile({ cards: total, room_cards: total })
  }
  return { list, total: Number.isFinite(total) ? total : 0 }
}

/**
 * 大厅商城购卡说明（h_setting.SHOP_ROOM_CARD_TIP）
 * @returns {Promise<string>}
 */
export async function fetchShopRoomCardTip() {
  const res = await http.post('/api/room-card/shop-tip', {})
  if (res.code !== 0) throw new Error(res.msg || '获取购卡说明失败')
  return String(res.data?.tip ?? '')
}
