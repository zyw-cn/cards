/**
 * 大厅站内信 API
 * scope: public=全员公告 | private=私信 | all=两者
 */
import { http } from './http'

export async function fetchLetterList(page = 1, pageSize = 20, scope = 'all') {
  const res = await http.post('/api/letter/list', { page, pageSize, scope })
  if (res.code !== 0) {
    return { ok: false, msg: res.msg || '加载失败', list: [], total: 0 }
  }
  const data = res.data || {}
  return {
    ok: true,
    list: Array.isArray(data.list) ? data.list : [],
    total: Number(data.total) || 0,
    page: Number(data.page) || page,
    pageSize: Number(data.pageSize) || pageSize,
    scope: data.scope || scope,
  }
}
