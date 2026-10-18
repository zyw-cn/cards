/**
 * 牌桌 tip 图映射（图片在 src/assets/images/common/tip，本文件单独维护引用）
 */
import tipReady from '@/assets/images/common/tip/tip_ready.png'
import tipPass from '@/assets/images/common/tip/tip_pass.png'
import tipCallLandlord from '@/assets/images/common/tip/tip_call_landlord.png'
import tipGrabLandlord from '@/assets/images/common/tip/tip_grab_landlord.png'
import tipNoCall from '@/assets/images/common/tip/tip_no_call.png'
import tipNoGrab from '@/assets/images/common/tip/tip_no_grab.png'
import tipDouble from '@/assets/images/common/tip/tip_double.png'
import tipNoDouble from '@/assets/images/common/tip/tip_no_double.png'
import tipApplyDismiss from '@/assets/images/common/tip/apply_dismiss_tip.png'
import tipAgreeDismiss from '@/assets/images/common/tip/tongyijiesan_tip.png'
import tipRejectDismiss from '@/assets/images/common/tip/tip_reject_dismiss.png'
/** 三家都不叫地主 + 系统重新发牌（合并一张） */
import tipCxfp from '@/assets/images/common/tip/cxfp.png'
import tipTuoguan from '@/assets/images/common/tip/tuoguan_tip.png'
import tipFzbp from '@/assets/images/common/tip/fzbp.png'
import tipChuPaiBuFuHe from '@/assets/images/common/tip/cpbfhgz_tip.png'
import tipDaBuGuoShangJia from '@/assets/images/common/tip/dbgsjp_tip.png'

export const tipImgs = {
  /** 全玩法统一准备 tip */
  ready: tipReady,
  pass: tipPass,
  call: tipCallLandlord,
  rob: tipGrabLandlord,
  noBid: tipNoCall,
  noRob: tipNoGrab,
  double: tipDouble,
  noDouble: tipNoDouble,
  applyDismiss: tipApplyDismiss,
  agreeDismiss: tipAgreeDismiss,
  rejectDismiss: tipRejectDismiss,
  /** 三家都不叫地主，系统重新发牌 */
  cxfp: tipCxfp,
  /** @deprecated 同 cxfp，兼容旧 centerTip key */
  allNoCall: tipCxfp,
  /** @deprecated 同 cxfp */
  redeal: tipCxfp,
  tuoguan: tipTuoguan,
  /** 下家剩一张勿放走 */
  fzbp: tipFzbp,
  /** 出牌不符合规则 */
  chuPaiBuFuHe: tipChuPaiBuFuHe,
  /** 打不过上家 */
  daBuGuoShangJia: tipDaBuGuoShangJia,
} as const

export type TipImgKey = keyof typeof tipImgs
