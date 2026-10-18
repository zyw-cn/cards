/**
 * 红中麻将：房间协议 / 创房规则
 * 对齐后端 App\Constants\GameType::HZMJ、App\Game\Hzmj
 */
export const GAME_TYPE = 7
export const MODULE_CODE = 'Hzmj'
export const MIN_PLAYERS = 2
export const MAX_PLAYERS = 4

export const PHASE = Object.freeze({
  WAIT_READY: 'waitready',
  GRAB: 'grab',
  PLAY: 'play',
  SETTLE: 'settle',
})

/** 后端 HzmjConsts::PHASE_* → 前端 phase */
export function mapServerPhase(raw: unknown): string {
  const p = String(raw || '').toLowerCase()
  if (p === 'playing' || p === 'play') return PHASE.PLAY
  if (p === 'grab' || p === 'grabbanker') return PHASE.GRAB
  if (p === 'waiting' || p === 'wait' || p === 'waitready') return PHASE.WAIT_READY
  if (p === 'settle' || p === 'end') return PHASE.SETTLE
  return p || PHASE.WAIT_READY
}

export type HzmjPhase = (typeof PHASE)[keyof typeof PHASE]

export const WS_ACTION = Object.freeze({
  JOIN: 'Room.Join',
  START: 'Hzmj.StartGame',
  GRAB: 'Hzmj.Grab',
  OP: 'Hzmj.Op',
  TRUST: 'Hzmj.Trust',
  UNTRUST: 'Hzmj.Untrust',
  LEAVE: 'Room.Leave',
  DISMISS: 'Room.Dismiss',
  APPLY_DISMISS: 'Room.ApplyDismiss',
  VOTE_DISMISS: 'Room.VoteDismiss',
})

export const TIMEOUT = Object.freeze({
  WAIT_START: 60,
  GRAB: 15,
  /** 出牌 / 响应（与后端 PLAY_TIMEOUT=15 对齐） */
  PLAY: 15,
  FAKE_COUNTDOWN: 15,
})

export const DEFAULT_ROUND = 8
export const DEFAULT_LUCKY = 2
export const DEFAULT_PLAYER_NUM = 4
export const DEFAULT_DF = 1

export const ROUND_OPTIONS = [
  { value: 8, label: '8局' },
  { value: 16, label: '16局' },
  { value: 24, label: '24局' },
]

export const LUCKY_OPTIONS = [1, 2, 3, 4, 6].map((n) => ({ value: n, label: String(n) }))

/** 创房底分 */
export const DF_OPTIONS = [1, 2, 3, 5, 10].map((n) => ({ value: n, label: String(n) }))

/** 局内抢庄倍数：0=不抢 */
export const GRAB_MULTIPLES = Object.freeze([0, 1, 2, 3])

export const PAY_MODES = [
  { value: 0, label: '房主支付' },
  { value: 1, label: 'AA支付' },
]

export const PLAYER_NUM_OPTIONS = [
  { value: 2, label: '2人' },
  { value: 3, label: '3人' },
  { value: 4, label: '4人' },
]

/** 创建房间表单选项 */
export const hzmjRules = Object.freeze({
  seats: DEFAULT_PLAYER_NUM,
  defaultJs: { zjs: DEFAULT_ROUND },
  payModes: PAY_MODES,
  roundOptions: ROUND_OPTIONS,
  luckyOptions: LUCKY_OPTIONS,
  dfOptions: DF_OPTIONS,
  grabMultiples: GRAB_MULTIPLES,
  playerNumOptions: PLAYER_NUM_OPTIONS,
  idleTip:
    '未开局房主可解散；等人/局间超 3 分钟未开始将自动解散并退费；牌局中返回大厅进入托管；中途解散须全员投票同意',
})
