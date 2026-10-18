/**
 * 大厅战绩 API（对齐 /api/record/list）
 */
import { http } from './http'

/** @param {{ gameType?: number, page?: number, pageSize?: number }} opts */
export async function fetchRecordList({ gameType = 0, page = 1, pageSize = 20 } = {}) {
  const res = await http.post('/api/record/list', {
    game_type: Number(gameType) || 0,
    page: Number(page) || 1,
    page_size: Number(pageSize) || 20,
  })
  if (res.code !== 0) {
    return { ok: false, msg: res.msg || '加载失败', list: [], page: 1, pageSize }
  }
  const data = res.data || {}
  return {
    ok: true,
    list: Array.isArray(data.list) ? data.list : [],
    page: Number(data.page) || page,
    pageSize: Number(data.page_size) || pageSize,
  }
}
