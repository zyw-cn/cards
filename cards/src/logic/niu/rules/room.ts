/**
 * 斗牛房间协议 / 创房选项
 * 对齐后端 App\Constants\GameType::NIU、App\Game\Niu\NiuConsts
 */
import type { RuleOption } from '@/config/ruleKv'

export const GAME_TYPE = 2
export const MODULE_CODE = 'Niu'

export const PHASE = Object.freeze({
  WAIT: 'wait',
  GRAB: 'grab',
  BET: 'bet',
  SHOW: 'show',
  SETTLE: 'settle',
})

export type NiuPhase = (typeof PHASE)[keyof typeof PHASE]

export const MAX_PLAYERS = 10
export const MIN_PLAYERS = 2
export const CARDS_PER_PLAYER = 5
/** 发牌后本人默认亮开的张数（其余牌背，点「看牌」后翻完） */
export const SELF_MING_FACE = 2

/** 0=不抢，1/2/3/5=叫分倍数（最高当庄，并列随机） */
export const GRAB_MULTIPLES = Object.freeze([0, 1, 2, 3, 5])
/** 闲家下注倍数 */
export const BET_MULTIPLES = Object.freeze([1, 2, 3, 5])

export const TIMEOUT = Object.freeze({
  WAIT_START: 60,
  GRAB: 10,
  BET: 15,
  SHOW: 10,
})

export const WS_ACTION = Object.freeze({
  JOIN: 'Room.Join',
  START: 'Niu.StartGame',
  GRAB: 'Niu.Grab',
  BET: 'Niu.Bet',
  SHOW: 'Niu.Show',
  LEAVE: 'Room.Leave',
  DISMISS: 'Room.Dismiss',
  APPLY_DISMISS: 'Room.ApplyDismiss',
  VOTE_DISMISS: 'Room.VoteDismiss',
})

export const createRoomModes = Object.freeze([
  {
    id: 30,
    name: '自由抢庄',
    key: 'free',
    dfOptions: ['1', '2', '3', '5', '10'],
    gzOptions: ['牛牛×3 牛九×2 牛八×2', '牛牛×4 牛九×3 牛八×2 牛七×2'],
    pxOptions: ['五花牛（5倍）', '炸弹牛（6倍）', '五小牛（8倍）'],
    szOptions: [] as string[],
    jsOptions: [
      { label: '12局', fk: 2 },
      { label: '24局', fk: 4 },
    ],
  },
  {
    id: 31,
    name: '轮流坐庄',
    key: 'ming',
    dfOptions: ['1', '2', '3', '5', '10'],
    gzOptions: ['牛牛×4 牛九×3 牛八×2 球球', '牛牛×4 牛九×3 牛八×2 牛七×2'],
    pxOptions: ['五花牛（5倍）', '炸弹牛（6倍）', '五小牛（8倍）'],
    szOptions: [] as string[],
  },
  {
    id: 32,
    name: '赢家坐庄',
    key: 'tongbi',
    dfOptions: ['1', '2', '3', '5', '10'],
    gzOptions: ['牛牛×3 牛九×2 牛八×2', '牛牛×4 牛九×3 牛八×2 牛七×2'],
    pxOptions: ['五花牛（5倍）', '炸弹牛（6倍）', '五小牛（8倍）'],
    szOptions: [] as string[],
  },
])

export const niuCreateRoomRules = Object.freeze({
  bottom_points: [
    { '1': '1金币' },
    { '2': '2金币' },
    { '3': '3金币' },
    { '5': '5金币' },
    { '10': '10金币' },
  ],
  pay_model: [{ '1': 'AA支付' }, { '2': '赢家支付' }, { '3': '我支付' }],
  win: '牛牛×4 牛九×3 牛八×2 五花牛x5 炸弹牛x6倍 五小牛x8',
  desc: '房间达到指定人数发牌，超过60s达不到人数自动解散不扣除场地费',
  fee: { '0.5': '0.5/局' },
  playNotes: [
    {
      title: '牌型',
      lines: ['牛牛×4，牛九×3，牛八×2。五花牛×5，炸弹牛×6，五小牛×8。'],
    },
    {
      title: '流程',
      lines: [
        '人满后发牌。每人 5 张，自己先看 2 张正面、3 张背面，看牌后再全部翻开。',
        '叫分可选不抢、1分、2分、3分。倒计时结束没有叫分，视为不抢。',
        '定庄后闲家下注，到时开牌。庄家和每位闲家按牌型和倍数结算。',
      ],
    },
    {
      title: '解散说明',
      lines: [
        '未开局：仅房主可解散房间。',
        '等人开局或局间等待：超过 3 分钟未开始，将自动解散房间并退费（未开局退整张房卡；已打过局按剩余局数折算金币返还房主）。',
        '进入牌局后：返回大厅不解散，将自动托管（约 2 秒后代打）并保留座位。',
        '进入牌局后：不允许单方面解散，须全员同意方可解散。',
      ],
    },
  ],
})

export const niuRules = Object.freeze({
  seats: 6,
  minPlayers: MIN_PLAYERS,
  maxPlayers: MAX_PLAYERS,
  grabMultiples: GRAB_MULTIPLES,
  betMultiples: BET_MULTIPLES,
  payModes: [
    { value: '1', label: 'AA支付' },
    { value: '2', label: '赢家支付' },
    { value: '3', label: '我支付' },
  ] as RuleOption[],
  idleTip: niuCreateRoomRules.desc,
})

export const niuMinPlayers = MIN_PLAYERS
export const niuMaxPlayers = MAX_PLAYERS
