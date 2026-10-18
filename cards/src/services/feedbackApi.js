/**
 * 大厅反馈 API
 */
import { http } from './http'

export async function submitFeedback(content) {
  const res = await http.post('/api/feedback/submit', { content })
  if (res.code !== 0) {
    return { ok: false, msg: res.msg || '提交失败', id: 0 }
  }
  return { ok: true, id: Number(res.data?.id) || 0 }
}

export async function fetchFeedbackList(page = 1, pageSize = 30) {
  const res = await http.post('/api/feedback/list', { page, pageSize })
  if (res.code !== 0) {
    return { ok: false, msg: res.msg || '加载失败', list: [], unread: 0, total: 0 }
  }
  const data = res.data || {}
  return {
    ok: true,
    list: Array.isArray(data.list) ? data.list : [],
    unread: Number(data.unread) || 0,
    total: Number(data.total) || 0,
  }
}

export async function fetchFeedbackUnread() {
  const res = await http.post('/api/feedback/unread', {})
  if (res.code !== 0) return 0
  return Number(res.data?.unread) || 0
}

export async function markFeedbackReplyRead(id) {
  const res = await http.post('/api/feedback/read', { id })
  if (res.code !== 0) return { ok: false, msg: res.msg || '操作失败', unread: null }
  return { ok: true, unread: Number(res.data?.unread) || 0 }
}
