<script setup>
import ModalShell from './ModalShell.vue'
import { closePlayerDetail, playerDetail } from '../services/playerDetail'
</script>

<template>
  <ModalShell :open="playerDetail.open" width="420px" min-height="280px" @close="closePlayerDetail">
    <img :src="playerDetail.avatar" class="mb-4 h-16 w-16 rounded-xl object-cover" alt="" />
    <div class="flex w-full flex-col gap-3 text-[16px] text-[#5a3a18]">
      <div class="flex items-center justify-between gap-4">
        <span class="shrink-0 text-[#8a6848]">昵称</span>
        <span class="truncate font-semibold">{{ playerDetail.nickname || '玩家' }}</span>
      </div>
      <div class="flex items-center justify-between gap-4">
        <span class="shrink-0 text-[#8a6848]">玩家ID</span>
        <span class="font-semibold tabular-nums">{{ playerDetail.uid || '—' }}</span>
      </div>
      <div class="flex items-center justify-between gap-4">
        <span class="shrink-0 text-[#8a6848]">金豆</span>
        <span class="font-semibold tabular-nums text-[#c47a12]">{{ playerDetail.goldText }}</span>
      </div>
      <div class="flex items-center justify-between gap-4">
        <span class="shrink-0 text-[#8a6848]">房卡</span>
        <span class="font-semibold tabular-nums">{{ playerDetail.cardsText }}</span>
      </div>
    </div>
  </ModalShell>
</template>
