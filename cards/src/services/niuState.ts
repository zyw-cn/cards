/** 同一份斗牛对局对象。声明在 Pinia store，这里只再导出。 */
export {
  niuState,
  resetNiuState,
  niuMe,
  flashNiuTip,
  applyNiuDismissVote,
  clearNiuDismiss,
  needMyNiuDismissVote,
  backs,
  maskSelfHand,
  emptyPlayer,
  useNiuStore,
} from '@/stores/niu'
export type { NiuPlayer, NiuMatchState } from '@/stores/niu'
