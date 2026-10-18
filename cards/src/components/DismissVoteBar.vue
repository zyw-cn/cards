<script setup>
/**
 * 全玩法统一的解散投票操作条（同意 / 不同意 + 等待文案）
 * tip 图由各房间按座位自行摆放
 */
import { art } from '@/utils/art'
import {
  DISMISS_VOTE_AGREE,
  DISMISS_VOTE_REJECT,
  dismissWaitText,
  isDismissApplicant,
} from '@/logic/shared/dismiss'

const props = defineProps({
  show: { type: Boolean, required: true },
  showVoteBtns: { type: Boolean, default: false },
  dismissFromSeat: { type: Number, default: 0 },
  myIndex: { type: Number, default: 0 },
  dismissLeft: { type: Number, default: 0 },
  /** 外层定位 class，默认斗地主桌心下方 */
  barClass: {
    type: String,
    default: 'absolute bottom-[281px] left-1/2 z-50 flex -translate-x-1/2 flex-col items-center gap-2',
  },
})

defineEmits(['vote'])

const btnOrange = art('ui', 'dizhu', 'btn_orange.png')
const btnGreen = art('ui', 'dizhu', 'btn_green.png')

function waitLabel() {
  return dismissWaitText(
    isDismissApplicant({
      dismissOpen: true,
      dismissFromSeat: props.dismissFromSeat,
      dismissStates: [],
      dismissLeft: props.dismissLeft,
      myIndex: props.myIndex,
    }),
    props.dismissLeft,
  )
}
</script>

<template>
  <div v-if="show" :class="barClass">
    <div v-if="showVoteBtns" class="pointer-events-auto flex items-center gap-3">
      <button
        type="button"
        class="relative h-[56px] w-[140px] transition active:scale-95"
        @click="$emit('vote', 2)"
      >
        <img :src="btnGreen" class="absolute inset-0 h-full w-full object-contain" alt="" />
        <span
          class="relative z-10 text-[18px] font-extrabold tracking-wide text-white"
          style="text-shadow: 0 2px 0 rgba(20, 60, 0, 0.35)"
        >
          {{ DISMISS_VOTE_REJECT }}
        </span>
      </button>
      <button
        type="button"
        class="relative h-[56px] w-[140px] transition active:scale-95"
        @click="$emit('vote', 1)"
      >
        <img :src="btnOrange" class="absolute inset-0 h-full w-full object-contain" alt="" />
        <span
          class="relative z-10 text-[18px] font-extrabold tracking-wide text-white"
          style="text-shadow: 0 2px 0 rgba(120, 50, 0, 0.35)"
        >
          {{ DISMISS_VOTE_AGREE }}
        </span>
      </button>
    </div>
    <p v-else class="text-[14px] text-white/80 drop-shadow">{{ waitLabel() }}</p>
  </div>
</template>
