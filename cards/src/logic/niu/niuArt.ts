/**
 * 斗牛叫分 / 倍数 / 飘分切图（Vue DOM 用，来自 src/assets/images/common）
 * - points/coinsN.png：分数数字
 * - points/coins_dian.png：小数点
 * - bei/X-N.png：×N 倍数
 */
import bei1 from '@/assets/images/common/bei/X-1.png'
import bei2 from '@/assets/images/common/bei/X-2.png'
import bei3 from '@/assets/images/common/bei/X-3.png'
import bei4 from '@/assets/images/common/bei/X-4.png'
import bei5 from '@/assets/images/common/bei/X-5.png'
import bei6 from '@/assets/images/common/bei/X-6.png'
import bei7 from '@/assets/images/common/bei/X-7.png'
import bei8 from '@/assets/images/common/bei/X-8.png'
import bei9 from '@/assets/images/common/bei/X-9.png'
import point0 from '@/assets/images/common/points/coins0.png'
import point1 from '@/assets/images/common/points/coins1.png'
import point2 from '@/assets/images/common/points/coins2.png'
import point3 from '@/assets/images/common/points/coins3.png'
import point4 from '@/assets/images/common/points/coins4.png'
import point5 from '@/assets/images/common/points/coins5.png'
import point6 from '@/assets/images/common/points/coins6.png'
import point7 from '@/assets/images/common/points/coins7.png'
import point8 from '@/assets/images/common/points/coins8.png'
import point9 from '@/assets/images/common/points/coins9.png'
import pointDot from '@/assets/images/common/points/coins_dian.png'
import { art } from '@/utils/art'

/** 叫分提示：1分 / 2分 / 3分 */
const GRAB_TIPS: Record<number, string> = {
  1: art('ui', 'niuniu', 'tip_1.png'),
  2: art('ui', 'niuniu', 'tip_2.png'),
  3: art('ui', 'niuniu', 'tip_3.png'),
}

const POINTS: Record<number, string> = {
  0: point0,
  1: point1,
  2: point2,
  3: point3,
  4: point4,
  5: point5,
  6: point6,
  7: point7,
  8: point8,
  9: point9,
}

const BEI: Record<number, string> = {
  1: bei1,
  2: bei2,
  3: bei3,
  4: bei4,
  5: bei5,
  6: bei6,
  7: bei7,
  8: bei8,
  9: bei9,
}

/** 分数数字图（0~9）；越界回空串 */
export function niuPointSrc(n: number): string {
  const k = Math.floor(Number(n))
  return POINTS[k] || ''
}

/** 倍数图 ×1~×9；越界回空串 */
export function niuBeiSrc(n: number): string {
  const k = Math.floor(Number(n))
  return BEI[k] || ''
}

/** 叫分图 1分 / 2分 / 3分；其它分数回空串 */
export function niuGrabTipSrc(n: number): string {
  const k = Math.floor(Number(n))
  return GRAB_TIPS[k] || ''
}

/** 结算飘分：把数值拆成数字/小数点切图序列（不含正负号） */
export function niuScoreDigitSrcs(value: number): string[] {
  const n = Number(value)
  if (!Number.isFinite(n)) return []
  const abs = Math.abs(n)
  // 最多保留 2 位小数，去掉末尾 0
  let raw = abs.toFixed(2).replace(/\.?0+$/, '')
  if (raw === '') raw = '0'
  const out: string[] = []
  for (const ch of raw) {
    if (ch === '.') {
      out.push(pointDot)
      continue
    }
    const d = Number(ch)
    if (Number.isFinite(d) && POINTS[d]) out.push(POINTS[d])
  }
  return out
}
