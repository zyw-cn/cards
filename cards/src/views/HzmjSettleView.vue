<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import HzmjLuckyFlip from '@/components/hzmj/HzmjLuckyFlip.vue'
import HzmjResultPanel from '@/components/hzmj/HzmjResultPanel.vue'
import HzmjSettlePanel from '@/components/hzmj/HzmjSettlePanel.vue'
import { art } from '@/utils/art'
import { leaveToHallIntent } from '@/services/roomResume'
import { hzmjSession } from '@/services/hzmjSession'
import {
  closeHzmjResult,
  confirmHzmjSettlement,
  hzmjState,
} from '@/services/hzmjState'

const match = hzmjState
const starting = ref(false)
const luckyFlip = ref<{ tiles: number[]; revealed: number } | null>(null)
let luckyEpoch = 0

const btnOrange = art('ui', 'dizhu', 'btn_orange_long.png')
const btnGreen = art('ui', 'dizhu', 'btn_green_long.png')

const showSettle = computed(
  () => !!match.settleResult && !luckyFlip.value && !match.showResult,
)

const showActions = computed(
  () => showSettle.value || (!!match.settleResult && !luckyFlip.value && !match.showResult),
)

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => window.setTimeout(resolve, ms))
}

async function playLucky(tiles: number[]): Promise<void> {
  const epoch = ++luckyEpoch
  luckyFlip.value = { tiles, revealed: 0 }
  for (let i = 0; i < tiles.length; i++) {
    await delay(500)
    if (epoch !== luckyEpoch) return
    luckyFlip.value = { tiles, revealed: i + 1 }
  }
  await delay(800)
  if (epoch !== luckyEpoch) return
  luckyFlip.value = null
}

watch(
  () => match.settleResult,
  (result) => {
    if (!result) {
      luckyEpoch += 1
      luckyFlip.value = null
      return
    }
    const tiles = match.luckyTiles.filter((t) => t > 0)
    if (!tiles.length) {
      luckyFlip.value = null
      return
    }
    void playLucky(tiles)
  },
  { immediate: true },
)

async function onStartGame(): Promise<void> {
  if (starting.value || match.roomOver) return
  starting.value = true
  try {
    confirmHzmjSettlement()
    if (match.roomOver || match.showResult) return
    await hzmjSession.sendReady()
  } catch (err) {
    console.warn('[hzmj settle] start', err)
  } finally {
    starting.value = false
  }
}

function onConfirmRoomOver(): void {
  confirmHzmjSettlement()
}

function onHall(): void {
  leaveToHallIntent()
  void hzmjSession.leave()
  
}

function onCloseResult(): void {
  closeHzmjResult()
  onHall()
}

onUnmounted(() => {
  luckyEpoch += 1
})
</script>

<template>
  <div class="relative h-full w-full select-none overflow-hidden bg-black/70">
    <HzmjLuckyFlip v-if="luckyFlip" :tiles="luckyFlip.tiles" :revealed="luckyFlip.revealed" />

    <HzmjSettlePanel
      v-if="showSettle"
      hide-confirm
      :self-seat="match.myIndex"
      :win-seat="match.winSeat"
      :result="match.settleResult || ''"
      :win-op="match.winOp"
      :dealer="match.dealerIndex"
      :kings="match.kingTiles"
      :lucky="match.luckyTiles.filter((t) => t > 0)"
      :lucky-hit="match.luckyHit"
      :lucky-num="match.luckyNum"
      :df="match.df"
      :bank-beishu="match.bankBeishu"
      :from-seat="match.settleFromSeat"
      :hands="match.hands"
      :self-hand="match.hand"
      :ups="match.upTiles"
      :final-tile="match.finalTile"
      :scores="match.scoreDeltas"
      :seats="match.seats"
    />

    <HzmjResultPanel
      v-if="match.showResult && !luckyFlip"
      :seats="match.seats"
      :totals="match.totalScores"
      @confirm="onCloseResult"
    />

    <div
      v-if="showActions"
      class="absolute bottom-6 left-1/2 z-[140] flex -translate-x-1/2 items-center gap-5"
    >
      <button
        v-if="!match.roomOver"
        type="button"
        class="relative h-[56px] w-[200px] transition active:scale-95 disabled:opacity-60"
        :disabled="starting"
        @click="onStartGame"
      >
        <img :src="btnOrange" class="absolute inset-0 h-full w-full object-contain" alt="" />
        <span class="relative z-10 text-[22px] font-extrabold text-white">
          {{ starting ? '准备中…' : '开始游戏' }}
        </span>
      </button>
      <button
        v-else
        type="button"
        class="relative h-[56px] w-[200px] transition active:scale-95"
        @click="onConfirmRoomOver"
      >
        <img :src="btnOrange" class="absolute inset-0 h-full w-full object-contain" alt="" />
        <span class="relative z-10 text-[22px] font-extrabold text-white">确定</span>
      </button>
      <button
        type="button"
        class="relative h-[56px] w-[200px] transition active:scale-95"
        @click="onHall"
      >
        <img :src="btnGreen" class="absolute inset-0 h-full w-full object-contain" alt="" />
        <span class="relative z-10 text-[22px] font-extrabold text-white">返回大厅</span>
      </button>
    </div>

    <p
      v-if="!match.settleResult && !match.showResult && !luckyFlip"
      class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 text-white/70"
    >
      暂无结算数据
    </p>
  </div>
</template>
