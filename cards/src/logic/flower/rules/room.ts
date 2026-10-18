/**
 * 炸金花房间协议 / 金额档位
 * 对齐后端 App\Game\Zjh\ZjhConsts
 */
import type { RuleOption } from '@/config/ruleKv'

export const GAME_TYPE = 3
export const MODULE_CODE = 'Zjh'

export const PHASE = Object.freeze({
  WAIT: 'wait',
  BET: 'bet',
  SETTLE: 'settle',
})

export type FlowerPhase = (typeof PHASE)[keyof typeof PHASE]

export const MAX_PLAYERS = 10
export const MIN_PLAYERS = 2
export const CARDS_PER_PLAYER = 3

export const SELECT_POINTS = Object.freeze([0.5, 1, 2, 5, 10, 20, 50])
export const MAX_FOLLOW_OPTIONS = Object.freeze([5, 10, 20, 50])
export const DEFAULT_MAX_FOLLOW = 5

export const TIMEOUT = Object.freeze({
  WAIT_START: 60,
  TURN: 20,
})

export const WS_ACTION = Object.freeze({
  JOIN: 'Room.Join',
  START: 'Zjh.StartGame',
  LOOK: 'Zjh.Look',
  CALL: 'Zjh.Call',
  RAISE: 'Zjh.Raise',
  FOLD: 'Zjh.Fold',
  COMPARE: 'Zjh.Compare',
  LEAVE: 'Room.Leave',
  DISMISS: 'Room.Dismiss',
  APPLY_DISMISS: 'Room.ApplyDismiss',
  VOTE_DISMISS: 'Room.VoteDismiss',
})

export const flowerCreateRoomRules = Object.freeze({
  bottom_points: [
    { '0.5': '0.5金币' },
    { '1': '1金币' },
    { '2': '2金币' },
    { '3': '3金币' },
    { '4': '4金币' },
    { '5': '5金币' },
  ],
  pay_model: [{ '1': 'AA支付' }, { '2': '赢家支付' }, { '3': '我支付' }],
  fee: { '0.5': '0.5/局' },
  win: '首局随机定庄，之后赢家当庄；自己可随时看牌；底注与跟注入池，整池归赢家',
  win_rules: '炸弹>同花顺>普通顺子>金花>对子>单张；牌型相同则开牌的输',
  desc:
    '加注金额只能选固定档位；最大跟注限制看牌最高金额，闷牌最高为最大跟注的一半（取档位）。闷跟=当前闷注，看跟按档位进位（如闷2看5、闷4看10）。有人离开或超过60s未开局则自动解散（未开局不扣场地费）比牌选择弹出框对应的人',
  playNotes: [
    {
      title: '牌型',
      lines: ['炸弹 > 同花顺 > 普通顺子 > 金花 > 对子 > 单张。牌型相同则开牌的输。'],
    },
    {
      title: '流程',
      lines: [
        '首局随机定庄，之后赢家当庄。自己可随时看牌。',
        '比牌时在弹出框里选择要比较的人。两家弃牌后，剩下的一家直接赢，不必再比牌。',
        '结算按打底 + 闷牌 + 跟牌 + 加注 + 比牌。输家按自己交出的这些钱算，赢家按收到的这些钱算。',
      ],
    },
    {
      title: '跟注',
      lines: [
        '加注只能选固定档位。闷跟等于当前闷注，看牌跟注按档位进位（闷 2 看 5，闷 4 看 10）。',
        '最大跟注是看牌最高金额，闷牌最高是最大跟注的一半（取档位）。',
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

export const flowerRules = Object.freeze({
  maxFollowOptions: MAX_FOLLOW_OPTIONS.map((v) => ({ value: v, label: `${v}金币` })) as RuleOption[],
  selectPoints: SELECT_POINTS,
  seats: 6,
  minPlayers: MIN_PLAYERS,
  maxPlayers: MAX_PLAYERS,
  idleTip: flowerCreateRoomRules.desc,
})

export const flowerMinPlayers = MIN_PLAYERS
export const flowerMaxPlayers = MAX_PLAYERS

export function normAmount(n: unknown): number {
  const v = Number(n)
  if (!Number.isFinite(v)) return 0
  return Number(v.toFixed(2))
}

/** 闷牌封顶：档位中 ≤ maxFollow×0.5 的最大项 */
export function menMaxFromFollow(maxFollow: number, selectPoints: readonly number[] = SELECT_POINTS): number {
  const half = normAmount(Number(maxFollow) * 0.5)
  let best = 0
  for (const p of selectPoints) {
    const v = normAmount(p)
    if (v <= half && v > best) best = v
  }
  return best
}

/** 看跟：档位中 ≥ unit×2 的最小项 */
export function lookCallFromUnit(unit: number, selectPoints: readonly number[] = SELECT_POINTS): number {
  const raw = normAmount(Number(unit) * 2)
  const pts = [...selectPoints].map((p) => normAmount(p)).filter((p) => p > 0).sort((a, b) => a - b)
  for (const p of pts) {
    if (p >= raw) return p
  }
  return raw
}

export function resolveRaiseAmounts(opts: {
  looked?: boolean
  unit?: number
  maxFollow?: number
  menMax?: number
  lookMax?: number
  selectPoints?: readonly number[]
} = {}): number[] {
  const selectPoints = opts.selectPoints ?? SELECT_POINTS
  const lookCap = normAmount(opts.lookMax ?? opts.maxFollow ?? DEFAULT_MAX_FOLLOW)
  const menCap = normAmount(opts.menMax ?? menMaxFromFollow(lookCap, selectPoints))
  const curUnit = normAmount(opts.unit)
  const curCall = opts.looked ? lookCallFromUnit(curUnit, selectPoints) : curUnit
  const cap = opts.looked ? lookCap : menCap
  return selectPoints.map((p) => normAmount(p)).filter((p) => p > curCall && p <= cap)
}

export function callAmountFor(looked: boolean, unit: number, selectPoints: readonly number[] = SELECT_POINTS): number {
  return looked ? lookCallFromUnit(unit, selectPoints) : normAmount(unit)
}
