<template>
  <div class="pointer-events-auto absolute inset-0 z-[80] flex items-center justify-center">
    <div class="absolute inset-0 bg-black/50" />
    <div class="relative w-[min(92%,520px)] rounded-xl border-2 border-[rgba(255,220,140,0.55)] bg-gradient-to-b from-[#2a5a8a] to-[#1a3a5c] px-6 pb-5 pt-7 text-center text-[#fff8e7]">
      <img class="mx-auto mb-3.5 block h-9 w-auto object-contain" :src="hzmjCloseTitle" alt="" draggable="false" />
      <p class="mb-1.5 text-base">玩家 {{ fromName }} 申请解散房间，是否同意？</p>
      <p class="mb-4 text-sm opacity-90">({{ leftSec }}秒后默认同意)</p>
      <div class="mb-4 grid grid-cols-2 gap-x-3.5 gap-y-2.5">
        <div v-for="p in players" :key="p.seat" class="relative flex min-h-10 items-center justify-between gap-2 px-2.5 py-1.5">
          <img class="pointer-events-none absolute inset-0 h-full w-full object-fill opacity-85" :src="hzmjCloseFrame" alt="" draggable="false" />
          <span class="relative z-[1] flex-1 truncate text-left text-sm">{{ p.nickname }}</span>
          <img class="relative z-[1] h-7 w-7 object-contain" :src="stateIcon(p.state)" alt="" draggable="false" />
        </div>
      </div>
      <div v-if="canVote" class="flex justify-center gap-7">
        <button type="button" class="border-0 bg-transparent p-0" @click="emit('vote', 1 as const)">
          <img class="block h-12 w-auto" :src="hzmjAgreeBtn" alt="同意" draggable="false" />
        </button>
        <button type="button" class="border-0 bg-transparent p-0" @click="emit('vote', 2 as const)">
          <img class="block h-12 w-auto" :src="hzmjRefuseBtn" alt="拒绝" draggable="false" />
        </button>
      </div>
      <p v-else class="text-[15px]">等待其他玩家选择</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import {
  hzmjAgreeBtn,
  hzmjCloseAgree,
  hzmjCloseFrame,
  hzmjCloseRefuse,
  hzmjCloseTitle,
  hzmjCloseUnselect,
  hzmjRefuseBtn,
} from '@/logic/hzmj/hzmjAssets'
import type { HzmjSeatView } from '@/stores/hzmj'

const props = defineProps<{
  fromSeat: number
  left: number
  states: number[]
  selfSeat: number
  seats: HzmjSeatView[]
}>()

const emit = defineEmits<{ vote: [v: 1 | 2] }>()

const fromName = computed(() => {
  const name = props.seats[props.fromSeat]?.nickname || `座位${props.fromSeat + 1}`
  return name.length > 6 ? `${name.slice(0, 6)}…` : name
})
const leftSec = computed(() => Math.max(0, Math.floor(Number(props.left) || 0)))
const players = computed(() =>
  props.seats
    .map((p, seat) => ({
      seat,
      occupied: p.occupied,
      nickname: p.nickname || `玩家${seat + 1}`,
      state: Number(props.states[seat] ?? 0) || 0,
    }))
    .filter((p) => p.occupied),
)
const canVote = computed(() => Number(props.states[props.selfSeat] ?? 0) === 0)

function stateIcon(state: number): string {
  if (state === 1) return hzmjCloseAgree
  if (state === 2) return hzmjCloseRefuse
  return hzmjCloseUnselect
}
</script>
