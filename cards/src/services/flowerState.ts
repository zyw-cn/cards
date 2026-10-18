/** 同一份炸金花对局对象。声明在 Pinia store，这里只再导出。 */
export {
  flowerState,
  setFlowerCountdown,
  resetFlowerState,
  flowerMe,
  flashFlowerTip,
  applyFlowerDismissVote,
  clearFlowerDismiss,
  needMyFlowerDismissVote,
  backs,
  useFlowerStore,
} from '@/stores/flower'
export type { FlowerPlayer, FlowerMatchState } from '@/stores/flower'
