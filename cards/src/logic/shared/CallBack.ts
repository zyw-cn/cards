/** 房间级推送 callback 名（与后端对齐） */
export const CallBack = Object.freeze({
  JOIN_ROOM: 'joinroom',
  ROOM_READY: 'roomready',
  WAIT_START: 'waitstart',
  START_GAME: 'startgame',
  BID: 'bid',
  DOUBLE: 'double',
  PLAY: 'play',
  GRAB: 'grab',
  BET: 'bet',
  SHOW: 'show',
  LOOK: 'look',
  CALL: 'call',
  RAISE: 'raise',
  FOLD: 'fold',
  COMPARE: 'compare',
  SETTLE: 'settle',
  TRUST: 'trust',
  UNTRUST: 'untrust',
  OP: 'op',
  ROOM_DISSOLVE: 'roomdissolve',
  CHAT: 'chat',
  APPLY_DISMISS: 'applydismiss',
  VOTE_DISMISS: 'votedismiss',
  CANCEL_DISMISS: 'canceldismiss',
})

export type CallBackKey = (typeof CallBack)[keyof typeof CallBack]
