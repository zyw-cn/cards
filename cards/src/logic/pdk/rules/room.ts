/**
 * 跑得快：房间协议 / 创房规则
 * 对齐后端 App\Constants\GameType::PAODEKUAI、App\Game\Pdk\PdkConsts
 */
export const GAME_TYPE = 4
export const MODULE_CODE = 'Pdk'

/** 阶段：出牌（已取消加倍） */
export const PHASE = Object.freeze({
  PLAY: 'play',
})

export type PdkPhase = (typeof PHASE)[keyof typeof PHASE]

export const MAX_PLAYERS = 3
export const MIN_PLAYERS = 3
export const HAND_SIZE = 16

/** @deprecated 已取消加倍 */
export const DOUBLE_SCORES = Object.freeze([0, 2])

/** WS 路由 */
export const WS_ACTION = Object.freeze({
  JOIN: 'Room.Join',
  START: 'Pdk.StartGame',
  PLAY: 'Pdk.Play',
  TRUST: 'Pdk.Trust',
  UNTRUST: 'Pdk.Untrust',
  LEAVE: 'Room.Leave',
  DISMISS: 'Room.Dismiss',
  APPLY_DISMISS: 'Room.ApplyDismiss',
  VOTE_DISMISS: 'Room.VoteDismiss',
})

/** 超时秒数（与后端挂表对齐，前端倒计时展示用） */
export const TIMEOUT = Object.freeze({
  WAIT_START: 60,
  PLAY: 20,
})

/**
 * 创建房间表单选项（写入 room.rule JSON）
 * bomb_multiple：炸弹固定分，每位对手付该分值
 */
export const pdkRules = Object.freeze({
  payModes: [
    { value: 1, label: 'AA支付', desc: '本局平台费总额由三人平分' },
    { value: 2, label: '赢家支付', desc: '本局平台费由胜利方承担' },
    { value: 3, label: '我支付', desc: '本局平台费总额由房主一人支付' },
  ],
  dfOptions: [
    { value: 0.5, label: '0.5' },
    { value: 1, label: '1' },
    { value: 2, label: '2' },
    { value: 5, label: '5' },
    { value: 10, label: '10' },
  ],
  bombMultipleOptions: [
    { value: 1, label: '1分' },
    { value: 2, label: '2分' },
    { value: 5, label: '5分' },
    { value: 10, label: '10分' },
    { value: 20, label: '20分' },
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
  idleTip:
    '光山跑得快，三人 16 张，持红桃3者先出（不必带出红桃3）；能管必须管。三带一随时可出；三带二必须是一对，不能带两张零牌；三不带和飞机不带只能最后一手。飞机平常必须带同样张数的单或对，不带牌只能最后一手甩尾，不是炸弹。四带二单、四带两对不算炸弹，没有四带一和四带三。顺子至少5张；下家只剩 1 张时，出非最大单算放走，下家若赢则由放走者包赔其他人的剩牌分；下家剩 2 张不限制。炸弹/三个A 当场结算：每位对手付固定炸弹分。任意玩家返回大厅将自动解散',
})
