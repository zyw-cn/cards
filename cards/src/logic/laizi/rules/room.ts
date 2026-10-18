/**
 * 癞子斗地主：房间协议 / 创房规则
 * 对齐后端 App\Constants\GameType::LAIZI_LANDLORD、App\Game\Lz\LzConsts
 */
export const GAME_TYPE = 5
export const MODULE_CODE = 'Lz'

export const PHASE = Object.freeze({
  GRAB: 'grab',
  DOUBLE: 'double',
  BID: 'bid',
  PLAY: 'play',
})

export type LaiziPhase = (typeof PHASE)[keyof typeof PHASE]

export const MAX_PLAYERS = 3
export const MIN_PLAYERS = 3

export const ASK_MODE = Object.freeze({
  ROTATE: 1,
  GRAB: 2,
  WINNER: 3,
})

export const DOUBLE_SCORES = Object.freeze([0, 2])

export const WS_ACTION = Object.freeze({
  JOIN: 'Room.Join',
  START: 'Lz.StartGame',
  BID: 'Lz.Bid',
  DOUBLE: 'Lz.Double',
  PLAY: 'Lz.Play',
  TRUST: 'Lz.Trust',
  UNTRUST: 'Lz.Untrust',
  LEAVE: 'Room.Leave',
  DISMISS: 'Room.Dismiss',
  APPLY_DISMISS: 'Room.ApplyDismiss',
  VOTE_DISMISS: 'Room.VoteDismiss',
})

export const TIMEOUT = Object.freeze({
  WAIT_START: 60,
  BID: 30,
  DOUBLE: 10,
  PLAY: 20,
  /** 本人管不住时本地闹钟；他人仍按 PLAY 显示，避免暴露能否管住（与经典斗地主对齐为 5） */
  PLAY_CANNOT_BEAT: 5,
})

export const laiziRules = Object.freeze({
  payModes: [
    { value: 1, label: 'AA支付', desc: '本局平台费总额由三人平分' },
    {
      value: 2,
      label: '赢家支付',
      desc: '本局平台费由胜利方承担（地主赢付全款；农民赢则两农民各付一半）',
    },
    { value: 3, label: '我支付', desc: '本局平台费总额由房主一人支付' },
  ],
  dfOptions: [
    { value: 0.5, label: '0.5' },
    { value: 1, label: '1' },
    { value: 2, label: '2' },
    { value: 5, label: '5' },
    { value: 10, label: '10' },
  ],
  askModes: [
    { value: ASK_MODE.ROTATE, label: '轮流地主' },
    { value: ASK_MODE.GRAB, label: '抢庄地主' },
    { value: ASK_MODE.WINNER, label: '胜者连庄' },
  ],
  payAmountOptions: [
    { value: 0.3, label: '0.3' },
    { value: 0.6, label: '0.6' },
    { value: 1.2, label: '1.2' },
    { value: 1.5, label: '1.5' },
    { value: 3, label: '3' },
  ],
  seats: MAX_PLAYERS,
  defaultJs: { fk: 1, zjs: 6 },
  idleTip: '任意玩家返回大厅将自动解散；长时间无人叫地主也将自动解散',
})
