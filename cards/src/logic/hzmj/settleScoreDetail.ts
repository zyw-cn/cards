/**
 * 红中麻将本局结算：按座位写清每人分数构成
 * （对齐 hyperf LuckyTiles + RoundState::scaleScoreDeltas）
 */

export type HzmjSeatScoreBreakdown = {
  /** 一行总述，如「付奖码分给赢家」 */
  title: string
  /** 分项明细（尽量短，方便每人一栏） */
  lines: string[]
}

export type HzmjSettleScoreDetail = {
  /** 全局共用公式（一行） */
  formula: string
  /** seat -> 该人明细 */
  bySeat: Record<number, HzmjSeatScoreBreakdown>
}

export type HzmjSettleScoreDetailInput = {
  result: string
  winOp: string
  luckyNum: number
  luckyHit: number
  luckyCount: number
  df: number
  bankBeishu: number
  winSeat: number
  fromSeat: number
  others: number
  winName: string
  fromName: string
  names: Record<number, string>
  scores: Record<number, number>
  seats: number[]
}

function fmt(n: number): string {
  return n > 0 ? `+${n}` : `${n}`
}

export function computeLuckyUnit(luckyNum: number, luckyHit: number): number {
  if (luckyNum === 1) return luckyHit + 2
  return 2 * (luckyHit + 1)
}

/** 公共算式短句：基数 → 每家应付（白话，不写难懂的代数缩写） */
function unitChain(
  cfg: number,
  hit: number,
  unit: number,
  df: number,
  bank: number,
  eachPay: number,
): string {
  // 多码：unit = 2*(hit+1)；一码全中：翻开点数+2
  const base =
    cfg === 1
      ? `奖码 ${hit}+2=${unit}`
      : `奖码 (${hit}+1)×2=${unit}`
  const mult: string[] = []
  if (df !== 1) mult.push(`×底分${df}`)
  if (bank !== 1) mult.push(`×叫分${bank}`)
  if (!mult.length) return `${base}，每家付 ${eachPay}`
  return `${base} ${mult.join(' ')}，每家付 ${eachPay}`
}

export function buildHzmjSettleScoreDetail(
  input: HzmjSettleScoreDetailInput,
): HzmjSettleScoreDetail {
  const bySeat: Record<number, HzmjSeatScoreBreakdown> = {}

  if (input.result === 'draw_end' || input.winSeat < 0) {
    for (const seat of input.seats) {
      bySeat[seat] = {
        title: '流局 · 本局 0',
        lines: ['无人胡牌，本局奖码分不计'],
      }
    }
    return { formula: '流局 · 本局不计奖码分', bySeat }
  }

  const df = input.df > 0 ? input.df : 1
  const bank = Math.max(1, input.bankBeishu || 1)
  const others = Math.max(1, input.others)
  const hit = Math.max(0, input.luckyHit)
  const cfg = Math.max(1, input.luckyNum || 2)
  const unit = computeLuckyUnit(cfg, hit)
  const eachPay = Math.round(unit * df * bank)
  const winTotal = eachPay * others
  const isKongWin = input.winOp === 'kong_win'
  const winLabel = isKongWin ? '抢杠胡' : '自摸胡'
  const chain = unitChain(cfg, hit, unit, df, bank, eachPay)
  const formula = isKongWin
    ? `${input.winName} ${winLabel} · 每家份额 ${eachPay} · ${input.fromName || '放杠者'}代付 ${others} 家`
    : `${input.winName} ${winLabel} · 每家付 ${eachPay} · 共 ${others} 家`

  for (const seat of input.seats) {
    const score = Number(input.scores[seat] ?? 0) || 0

    if (seat === input.winSeat) {
      bySeat[seat] = {
        title: `${winLabel} 赢 ${fmt(score || winTotal)}`,
        lines: isKongWin
          ? [
              chain,
              `来源：${input.fromName || '放杠者'}代付 ${others}×${eachPay}`,
              `合计 ${eachPay}×${others}=${fmt(score || winTotal)}`,
            ]
          : [
              chain,
              `来源：其余 ${others} 家各付 ${eachPay}`,
              `合计 ${eachPay}×${others}=${fmt(score || winTotal)}`,
            ],
      }
      continue
    }

    if (isKongWin && seat === input.fromSeat) {
      bySeat[seat] = {
        title: `被抢杠 输 ${fmt(score || -winTotal)}`,
        lines: [
          chain,
          `项目：代付全部 ${others} 家份额给 ${input.winName}`,
          `合计 -${eachPay}×${others}=${fmt(score || -winTotal)}`,
        ],
      }
      continue
    }

    if (isKongWin) {
      bySeat[seat] = {
        title: '本局 0',
        lines: [`不参与：抢杠胡由 ${input.fromName || '放杠者'} 独付`],
      }
      continue
    }

    bySeat[seat] = {
      title: `付奖码 输 ${fmt(score || -eachPay)}`,
      lines: [
        chain,
        `项目：付给 ${input.winName}（自摸奖码分）`,
        `合计 -${eachPay}=${fmt(score || -eachPay)}`,
      ],
    }
  }

  return { formula, bySeat }
}
