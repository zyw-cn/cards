/** 斗地主：房间协议常量（对齐后端 Dou） */
export const GAME_TYPE = 1
export const MODULE_CODE = 'Dou'

export const PHASE = Object.freeze({
  WAIT_READY: 'waitready',
  GRAB: 'grab',
  DOUBLE: 'double',
  BID: 'bid',
  PLAY: 'play',
  SETTLE: 'settle',
})

export type LandlordPhase = (typeof PHASE)[keyof typeof PHASE]

export const MAX_PLAYERS = 3
export const MIN_PLAYERS = 3

export const ASK_MODE = Object.freeze({
  ROTATE: 1,
  GRAB: 2,
  WINNER: 3,
})

export const WS_ACTION = Object.freeze({
  JOIN: 'Room.Join',
  START: 'Dou.StartGame',
  BID: 'Dou.Bid',
  DOUBLE: 'Dou.Double',
  PLAY: 'Dou.Play',
  TRUST: 'Dou.Trust',
  UNTRUST: 'Dou.Untrust',
  LEAVE: 'Room.Leave',
  DISMISS: 'Room.Dismiss',
  APPLY_DISMISS: 'Room.ApplyDismiss',
  VOTE_DISMISS: 'Room.VoteDismiss',
})

export const TIMEOUT = Object.freeze({
  WAIT_START: 60,
  /** 叫地主 / 抢地主（全桌可见） */
  BID: 10,
  DOUBLE: 10,
  PLAY: 20,
  /** 本人管不住时本地闹钟（他人仍按 PLAY 显示，避免暴露能否管住） */
  PLAY_CANNOT_BEAT: 5,
})
