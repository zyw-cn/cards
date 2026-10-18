<template>
  <div class="pointer-events-auto absolute inset-0 z-[120] flex items-center justify-center">
    <div class="absolute inset-0 bg-black/70" />
    <div class="relative z-[1] h-[540px] w-[1240px] max-w-[96%]">
      <div class="absolute inset-0 rounded-sm bg-[#1a0a0c]" />
      <img class="pointer-events-none absolute inset-0 h-full w-full object-fill" :src="bgSrc" alt="" draggable="false" />
      <img class="pointer-events-none absolute left-1/2 top-[-36px] z-[2] w-[380px] -translate-x-1/2" :src="titleSrc" alt="" draggable="false" />
      <img
        v-if="lucky.length"
        class="pointer-events-none absolute right-10 top-2 z-[2] w-[60px]"
        :src="hzmjMobao"
        alt=""
        draggable="false"
      />

      <p
        v-if="scoreDetail?.formula"
        class="absolute left-8 right-8 top-[44px] z-[2] line-clamp-2 text-center text-[13px] font-semibold leading-snug text-[#ffe082]/95"
      >
        {{ scoreDetail.formula }}
      </p>

      <div
        class="absolute bottom-[88px] left-3 right-3 z-[1] flex flex-col justify-evenly gap-1 overflow-hidden"
        :style="{ top: scoreDetail?.formula ? '78px' : '52px' }"
      >
        <div
          v-for="row in rows"
          :key="row.seat"
          class="flex min-h-0 flex-1 flex-col justify-center gap-0.5 rounded px-2 py-1"
          :class="row.isWinner ? 'bg-[rgba(255,220,120,0.16)]' : ''"
        >
          <div class="flex min-h-0 items-center gap-2">
            <!-- 昵称 -->
            <div class="flex w-[100px] shrink-0 items-center gap-1 overflow-hidden text-white">
              <img v-if="row.isDealer" class="w-5 shrink-0" :src="hzmjDealerImg" alt="" draggable="false" />
              <img v-if="row.isWinner" class="w-7 shrink-0" :src="winOpImg" alt="" draggable="false" />
              <span class="truncate text-[15px] font-bold">{{ row.nickname }}</span>
            </div>

            <!-- 牌面：可横向滚动，避免裁切 -->
            <div class="flex min-w-0 flex-1 items-end gap-1 overflow-x-auto overflow-y-hidden [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
              <div
                v-for="(meld, mi) in row.ups"
                :key="`u-${mi}`"
                class="flex shrink-0 items-end border-r border-white/20 pr-1.5"
              >
                <div v-for="(t, ti) in meld" :key="ti" class="relative -mr-1 h-9 w-[28px] shrink-0">
                  <img class="block h-9 w-[28px]" :src="hzmjFrame('hand')" alt="" draggable="false" />
                  <img v-if="t > 0" class="absolute left-[11%] top-[9%] h-[70%] w-[78%] object-contain" :src="hzmjFace(t, 'big')" alt="" draggable="false" />
                </div>
              </div>
              <div class="flex shrink-0 items-end">
                <div
                  v-for="(t, ti) in row.hand"
                  :key="ti"
                  class="relative -mr-1 h-9 w-[28px] shrink-0"
                  :class="row.isWinner && ti === row.hand.length - 1 ? 'ml-1' : ''"
                >
                  <img class="block h-9 w-[28px]" :src="hzmjFrame('hand')" alt="" draggable="false" />
                  <img class="absolute left-[11%] top-[9%] h-[70%] w-[78%] object-contain" :src="hzmjFace(t, 'big')" alt="" draggable="false" />
                  <img
                    v-if="kings.includes(t)"
                    class="absolute left-0.5 top-0.5 w-[30%]"
                    :src="hzmjKingMark"
                    alt=""
                    draggable="false"
                  />
                </div>
              </div>
              <div v-if="row.isWinner && lucky.length" class="ml-1 flex shrink-0 items-end border-l border-[#ffdc78]/35 pl-1.5">
                <div v-for="(t, i) in lucky" :key="i" class="relative -mr-1 h-9 w-[28px]">
                  <img class="block h-9 w-[28px]" :src="hzmjFrame('hand')" alt="" draggable="false" />
                  <img class="absolute left-[11%] top-[9%] h-[70%] w-[78%] object-contain" :src="hzmjFace(t, 'big')" alt="" draggable="false" />
                </div>
              </div>
            </div>

            <!-- 分数 -->
            <div
              class="w-[76px] shrink-0 text-right text-[26px] font-bold tabular-nums"
              :class="row.score > 0 ? 'text-[#ffeb3b]' : row.score < 0 ? 'text-[#90caf9]' : 'text-[#e1e1d6]'"
            >
              {{ row.score > 0 ? `+${row.score}` : row.score }}
            </div>
          </div>

          <!-- 分项：整行展示，允许换行 -->
          <div
            v-if="row.breakdown"
            class="min-w-0 pl-[100px] pr-[76px] text-[12px] leading-[15px] text-[#f0e6c8]"
          >
            <p class="font-extrabold text-[#ffe082]">{{ row.breakdown.title }}</p>
            <p
              v-for="(line, i) in row.breakdown.lines"
              :key="i"
              class="text-white/80"
            >
              {{ line }}
            </p>
          </div>
        </div>
      </div>

      <button
        v-if="!hideConfirm"
        type="button"
        class="absolute bottom-1 left-1/2 z-[3] h-[96px] w-[220px] -translate-x-1/2 border-0 bg-transparent p-0 transition active:scale-95"
        @click="emit('confirm')"
      >
        <img class="block h-full w-full object-contain" :src="hzmjSettleConfirm" alt="确定" draggable="false" />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import {
  hzmjDealerImg,
  hzmjDrawTitle,
  hzmjDrawWin,
  hzmjFace,
  hzmjFailTitle,
  hzmjFrame,
  hzmjGiveWin,
  hzmjKingMark,
  hzmjMobao,
  hzmjSettleConfirm,
  hzmjSettleFailBg,
  hzmjSettleWinBg,
  hzmjWinTitle,
} from '@/logic/hzmj/hzmjAssets'
import { buildHzmjSettleScoreDetail } from '@/logic/hzmj/settleScoreDetail'
import type { HzmjSeatView } from '@/stores/hzmj'

const props = defineProps<{
  selfSeat: number
  winSeat: number
  result: string
  winOp: string
  dealer: number
  kings: number[]
  lucky: number[]
  luckyHit?: number
  luckyNum?: number
  df?: number
  bankBeishu?: number
  fromSeat?: number
  hands: number[][]
  selfHand: number[]
  ups: number[][][]
  finalTile: number
  scores: Record<number, number>
  seats: HzmjSeatView[]
  hideConfirm?: boolean
}>()

const emit = defineEmits<{ confirm: [] }>()

const isDraw = computed(() => props.result === 'draw_end' || props.winSeat < 0)
const isWin = computed(() => !isDraw.value && props.winSeat === props.selfSeat)
const bgSrc = computed(() => (isWin.value ? hzmjSettleWinBg : hzmjSettleFailBg))
const titleSrc = computed(() => {
  if (isDraw.value) return hzmjDrawTitle
  return isWin.value ? hzmjWinTitle : hzmjFailTitle
})
const winOpImg = computed(() => (props.winOp === 'kong_win' ? hzmjGiveWin : hzmjDrawWin))

const scoreDetail = computed(() => {
  const occupiedSeats = props.seats
    .map((p, seat) => ({ p, seat }))
    .filter(({ p }) => p.occupied)
    .map(({ seat }) => seat)
  const others = Math.max(0, occupiedSeats.length - 1)
  const names: Record<number, string> = {}
  for (const seat of occupiedSeats) {
    names[seat] = props.seats[seat]?.nickname || `座位${seat + 1}`
  }
  const winName = names[props.winSeat] || `座位${props.winSeat + 1}`
  const from = Number(props.fromSeat)
  const fromName =
    Number.isFinite(from) && from >= 0 ? names[from] || `座位${from + 1}` : ''

  return buildHzmjSettleScoreDetail({
    result: props.result,
    winOp: props.winOp,
    luckyNum: Number(props.luckyNum) || 2,
    luckyHit: Number(props.luckyHit) || 0,
    luckyCount: props.lucky.length,
    df: Number(props.df) || 1,
    bankBeishu: Number(props.bankBeishu) || 1,
    winSeat: props.winSeat,
    fromSeat: Number.isFinite(from) ? from : -1,
    others,
    winName,
    fromName,
    names,
    scores: props.scores,
    seats: occupiedSeats,
  })
})

function buildHand(seat: number, winner: boolean): number[] {
  const src = seat === props.selfSeat && props.selfHand.length ? props.selfHand : props.hands[seat] || []
  let hand = src.filter((t) => t > 0)
  const ft = Number(props.finalTile) || 0
  if (!winner || !(ft > 0)) return hand
  const idx = hand.lastIndexOf(ft)
  if (idx >= 0) hand = [...hand.slice(0, idx), ...hand.slice(idx + 1), ft]
  else hand = [...hand, ft]
  return hand
}

const rows = computed(() =>
  props.seats
    .map((p, seat) => ({ p, seat }))
    .filter(({ p }) => p.occupied)
    .map(({ p, seat }) => {
      const winner = !isDraw.value && props.winSeat === seat
      return {
        seat,
        nickname: p.nickname || `座位${seat + 1}`,
        isDealer: props.dealer === seat,
        isWinner: winner,
        ups: (props.ups[seat] || []).filter((m) => Array.isArray(m) && m.length),
        hand: buildHand(seat, winner),
        score: Number(props.scores[seat] ?? 0) || 0,
        breakdown: scoreDetail.value?.bySeat?.[seat] || null,
      }
    }),
)
</script>
