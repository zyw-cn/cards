<template>
  <div class="pointer-events-auto absolute inset-0 z-[130] flex items-center justify-center">
    <div class="absolute inset-0 bg-black/75" />
    <div class="relative z-[1] w-[640px] rounded-lg border-2 border-[#c9a227] bg-[rgba(26,10,12,0.96)] px-8 pb-24 pt-7">
      <img class="mx-auto mb-5 block w-[220px]" :src="hzmjResultTitle" alt="" draggable="false" />
      <div class="flex flex-col gap-3">
        <div
          v-for="row in rows"
          :key="row.seat"
          class="flex items-center justify-between rounded px-4 py-2.5"
          :class="row.isWinner ? 'bg-[rgba(255,220,120,0.2)]' : 'bg-white/5'"
        >
          <span class="max-w-[360px] truncate text-[22px] text-[#fff8e1]">{{ row.nickname }}</span>
          <span class="text-[24px] font-bold" :class="row.score > 0 ? 'text-[#ffeb3b]' : row.score < 0 ? 'text-[#90caf9]' : 'text-[#e1e1d6]'">
            {{ row.score > 0 ? `+${row.score}` : row.score }}
          </span>
        </div>
      </div>
      <button type="button" class="absolute bottom-4 left-1/2 -translate-x-1/2 border-0 bg-transparent p-0" @click="emit('confirm')">
        <img class="h-[72px] w-auto object-contain" :src="hzmjResultBtn" alt="确定" draggable="false" />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { hzmjResultBtn, hzmjResultTitle } from '@/logic/hzmj/hzmjAssets'
import type { HzmjSeatView } from '@/stores/hzmj'

const props = defineProps<{
  seats: HzmjSeatView[]
  totals: Record<number, number>
}>()

const emit = defineEmits<{ confirm: [] }>()

const rows = computed(() => {
  const scores = props.seats
    .map((p, seat) => ({
      seat,
      occupied: p.occupied,
      nickname: p.nickname || `座位${seat + 1}`,
      score: Number(props.totals[seat] ?? 0) || 0,
    }))
    .filter((r) => r.occupied)
  const max = scores.reduce((m, r) => Math.max(m, r.score), Number.NEGATIVE_INFINITY)
  const hasWinner = Number.isFinite(max) && scores.some((r) => r.score !== 0)
  return scores.map((r) => ({
    ...r,
    isWinner: hasWinner && r.score === max && r.score > 0,
  }))
})
</script>
