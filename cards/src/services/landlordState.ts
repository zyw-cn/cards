/** 同一份斗地主对局对象 */
export {
  landlordState,
  resetLandlordState,
  toggleSelectCard,
  clearSelected,
  selectedCards,
  setFarmerDouble,
  needMyDouble,
  flashHandTip,
  showChatBubble,
  clearChatBubbles,
  needMyDismissVote,
  applyDismissVote,
  clearDismissVote,
  useLandlordStore,
} from '@/stores/landlord'
export type {
  ActionBadge,
  SettleRow,
  SettleResultModel,
  LandlordSeatMap,
  LandlordMatchState,
} from '@/stores/landlord'
