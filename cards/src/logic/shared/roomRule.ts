/** 解析房间 rule JSON 字符串 */
export function parseRoomRule(rule: unknown): Record<string, unknown> | null {
  if (!rule) return null
  if (typeof rule === 'object') return rule as Record<string, unknown>
  try {
    const data = JSON.parse(String(rule))
    return data && typeof data === 'object' ? (data as Record<string, unknown>) : null
  } catch {
    return null
  }
}

const ASK_MODE_TEXT: Record<number, string> = {
  1: '轮流地主',
  2: '抢庄地主',
  3: '胜者连庄',
}

export interface NormalizedRoomRule {
  payMode: number
  payModeText: string
  payModeDesc: string
  payAmount: number
  df: number
  askMode: number
  askModeText: string
  bombMultiple: number
  zjs: number
  seats: number
  entryPoints: number
}

/**
 * 将后台下发的 rule / ruleObj 规范化为桌面展示字段
 */
export function normalizeRoomRule(
  rule: unknown,
  ruleObj?: unknown,
): NormalizedRoomRule {
  const parsed =
    ruleObj && typeof ruleObj === 'object'
      ? (ruleObj as Record<string, unknown>)
      : parseRoomRule(rule)

  if (!parsed) {
    return {
      payMode: 0,
      payModeText: '房卡',
      payModeDesc: '',
      payAmount: 0,
      df: 1,
      askMode: 2,
      askModeText: '抢庄地主',
      bombMultiple: 10,
      zjs: 6,
      seats: 3,
      entryPoints: 5,
    }
  }

  const askRaw = parsed.ask_mode ?? parsed.askMode
  let askMode = askRaw != null && askRaw !== '' ? Number(askRaw) : 2
  if (![1, 2, 3].includes(askMode)) askMode = 2

  const df = parsed.df != null ? Number(parsed.df) : 1
  const entry = df * 5

  const payRaw = Number(parsed.pay_amount ?? parsed.payAmount ?? 0)
  const payAmount = Number.isFinite(payRaw) && payRaw > 0 ? payRaw : 0
  const payModeText = String(parsed.pay_mode_text || parsed.payModeText || '房卡')

  return {
    payMode: Number(parsed.pay_mode ?? parsed.payMode) || 0,
    payModeText,
    payModeDesc: String(parsed.pay_mode_desc || parsed.payModeDesc || ''),
    payAmount,
    df,
    askMode,
    askModeText: String(
      parsed.ask_mode_text || parsed.askModeText || ASK_MODE_TEXT[askMode] || '轮流地主',
    ),
    bombMultiple: Number(parsed.bomb_multiple ?? parsed.bombMultiple) || 10,
    zjs: Number(parsed.zjs ?? parsed.rounds ?? parsed.total_play_count) || 6,
    seats: Number(parsed.seats) || 3,
    entryPoints: entry,
  }
}
