/** 同一份红中麻将对局对象 */
export {
  hzmjState,
  resetHzmjState,
  setMyHand,
  formatHand,
  flashHzmjTip,
  applyHzmjDismissVote,
  clearHzmjDismiss,
  needMyHzmjDismissVote,
  confirmHzmjSettlement,
  clearPreviousRoundDesk,
  closeHzmjResult,
} from '@/stores/hzmj'
export type { HzmjSeatView, HzmjPendingDiscard, HzmjMatchState } from '@/stores/hzmj'
