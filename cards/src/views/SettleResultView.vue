<script setup>
import { computed, ref, watch } from 'vue'
import { art } from '../utils/art'
import { gameState } from '../services/gameState'
import { landlordState } from '../services/landlordState'
import { landlordSession } from '../services/landlordSession'
import { leaveToHallIntent } from '../services/roomResume'
import { prepareNextRoundLocally } from '../logic/landlord/settleResult'
import { seatAvatarSrc } from '../services/seatAvatar'

const props = defineProps({
  show: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])
const starting = ref(false)

watch(
  () => String(landlordState.phase || ''),
  (phase) => {
    const p = phase.toLowerCase()
    const inRound = p && p !== 'wait' && p !== 'waitready' && p !== 'end' && p !== 'settle'
    if (!inRound) return
    starting.value = false
    if (props.show) emit('close')
  },
)

const settle = (...parts) => art('dizhu_settle', 'slices', ...parts)
const dizhu = (...parts) => art('ui', 'dizhu', ...parts)

const result = computed(() => landlordState.result)

const springLabel = computed(() => {
  if (result.value?.springType === 'shutout') return '关牌'
  if (result.value?.springType === 'anti') return '反春天'
  return '春天'
})

const isPdkSettle = computed(() => Number(gameState.room.gameType) === 4)

const bannerImg = computed(() => {
  const r = result.value
  if (!r) return ''
  return r.selfWin ? settle('you_win.png') : settle('you_lose.png')
})

const bannerText = computed(() => {
  const r = result.value
  if (!r) return ''
  if (isPdkSettle.value) return r.selfWin ? '胜利' : '失败'
  if (r.selfWin) return r.winSide === 'landlord' ? '地主胜利' : '农民胜利'
  return r.selfRole === 'landlord' ? '地主失败' : '农民失败'
})

function avatarOf(key, uid = 0) {
  return seatAvatarSrc(key, uid)
}

function trimScore(n) {
  const x = Number(n)
  if (!Number.isFinite(x)) return '0'
  return Math.abs(x).toFixed(2).replace(/0+$/, '').replace(/\.$/, '')
}

/** 结算分数字切图（正数用黄字 y_*，负数/零用白字，保留小数） */
function scoreDigitSrcs(delta) {
  const n = Number(delta)
  if (!Number.isFinite(n)) return []
  const yellow = n > 0
  const prefix = yellow ? 'y_' : ''
  if (n === 0) return [settle('digits', '0.png')]
  const out = [settle('digits', yellow ? 'y_+.png' : '-.png')]
  for (const ch of trimScore(n)) {
    const name = ch === '.' ? `${prefix}dian.png` : `${prefix}${ch}.png`
    out.push(settle('digits', name))
  }
  return out
}

function signedScore(n) {
  const x = Number(n)
  if (!Number.isFinite(x) || x === 0) return '0'
  return `${x > 0 ? '+' : '-'}${trimScore(x)}`
}

function pdkRemainText(row) {
  const left = Number(row.remain) || 0
  return left > 0 ? `${left}张` : '出完'
}

function pdkFactorText(row) {
  const unit = trimScore(result.value?.unit)
  const left = Number(row.remain) || 0
  if (left >= 16) return `×${unit} ×2`
  if (left > 0) return `×${unit}`
  return '收取'
}

function pdkTone(n) {
  const x = Number(n)
  if (!Number.isFinite(x) || x === 0) return 'text-white/75'
  return x > 0 ? 'text-[#ffe27a]' : 'text-rose-300'
}

function rowFrame(row) {
  if (row.isSelf) return row.delta >= 0 ? settle('self_gold_frame.png') : settle('self_gray_frame.png')
  return settle('other_player_frame.png')
}

async function onStartGame() {
  if (starting.value || result.value?.roomOver) return
  starting.value = true
  try {
    prepareNextRoundLocally()
    emit('close')
    await landlordSession.sendReady()
  } catch (err) {
    console.warn('[settle] start', err)
  } finally {
    starting.value = false
  }
}

async function onHall() {
  leaveToHallIntent()
  emit('close')
  await landlordSession.leave()
  
}
</script>

<template>
  <div
    v-if="show && result"
    class="settle-stage absolute inset-0 z-[70] flex flex-col items-center justify-center bg-black/70 px-6"
  >
    <img
      :src="bannerImg"
      :alt="bannerText"
      class="relative z-20 mb-[-22px] h-[120px] w-auto object-contain drop-shadow-lg"
    />

    <div
      class="settle-board relative w-[78%] max-w-[920px] rounded-2xl border border-amber-200/25 bg-gradient-to-b from-[#2a3a5c]/95 to-[#1a2438]/98 px-6 pb-5 pt-10 shadow-2xl"
    >
      <h2 class="mb-2 text-center text-[26px] font-extrabold tracking-wide text-[#ffe9a0]">本局结算</h2>
      <div class="mb-4 flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-[15px] text-white/85">
        <span class="font-semibold text-[#ffe27a]">
          <template v-if="isPdkSettle">得分 = 剩余张数 × 底分 {{ trimScore(result.unit) }} + 炸弹</template>
          <template v-else>总倍数 ×{{ result.multiple || 1 }}</template>
        </span>
        <span v-if="!isPdkSettle && result.bidMultiple">叫抢 ×{{ result.bidMultiple }}</span>
        <span v-if="!isPdkSettle && result.bombCount">
          炸弹 {{ result.bombCount }}（×{{ Math.pow(2, Number(result.bombCount) || 0) }}）
        </span>
        <span v-if="result.spring">{{ springLabel }}{{ isPdkSettle ? '' : ' ×2' }}</span>
      </div>
      <p
        v-if="isPdkSettle && result.fangzouNote"
        class="mb-3 text-center text-[15px] leading-6 text-[#ffd0c8]"
      >
        {{ result.fangzouNote }}
      </p>

      <div
        v-if="isPdkSettle"
        class="mb-1 grid grid-cols-[minmax(0,1.6fr)_64px_88px_64px_108px] items-center gap-1 px-3 text-center text-[12px] tracking-wide text-[#ffe9a0]/70"
      >
        <span class="text-left">玩家</span>
        <span>剩余</span>
        <span>牌分</span>
        <span>炸弹</span>
        <span>得分</span>
      </div>
      <div
        v-for="row in result.rows"
        :key="row.absSeat"
        class="relative mb-2 min-h-[64px] items-center overflow-hidden rounded-xl px-3 py-2"
        :class="[
          isPdkSettle ? 'grid grid-cols-[minmax(0,1.6fr)_64px_88px_64px_108px] gap-1' : 'flex gap-3',
          row.isWinner ? 'bg-amber-400/15 ring-1 ring-amber-300/35' : 'bg-white/5',
          row.isSelf ? 'ring-2 ring-[#F6D37A]/70' : '',
        ]"
      >
        <img
          :src="rowFrame(row)"
          class="pointer-events-none absolute inset-0 h-full w-full object-fill opacity-35"
          alt=""
        />
        <div class="relative z-10 flex min-w-0 items-center gap-3">
          <div class="relative flex h-[50px] w-[50px] shrink-0 items-center justify-center">
            <img
              :src="row.isSelf ? settle('self_avatar_frame_74.png') : settle('other_avatar_frame_50.png')"
              class="absolute inset-0 h-full w-full object-contain"
              alt=""
            />
            <img
              :src="avatarOf(row.avatar, row.absSeat)"
              alt=""
              class="relative z-10 h-[38px] w-[38px] rounded-full object-cover"
            />
          </div>
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-white">
              <span class="truncate">{{ row.nickname }}</span>
              <span
                v-if="row.roleLabel && !isPdkSettle"
                class="rounded-full px-2 py-0.5 text-xs"
                :class="
                  row.isWinner || row.role === 'landlord'
                    ? 'bg-[#F0B429] text-[#3A2A08]'
                    : 'bg-black/45 text-white/90'
                "
              >
                {{ row.roleLabel }}
              </span>
              <img
                v-if="!isPdkSettle && row.farmerDouble > 1"
                :src="settle('double_sm.png')"
                class="h-5 w-auto object-contain"
                alt="加倍"
              />
              <span v-if="row.isSelf" class="text-xs text-white/60">我</span>
            </div>
            <div v-if="!isPdkSettle" class="mt-1 flex items-center gap-1">
              <img
                v-for="(src, i) in scoreDigitSrcs(row.delta)"
                :key="`${row.absSeat}-${i}`"
                :src="src"
                class="h-6 w-auto object-contain"
                alt=""
              />
            </div>
          </div>
        </div>
        <template v-if="isPdkSettle">
          <div class="relative z-10 text-center">
            <div class="text-[16px] font-extrabold text-white">{{ pdkRemainText(row) }}</div>
            <div v-if="Number(row.remain) >= 16" class="text-[11px] text-rose-200">关牌</div>
          </div>
          <div class="relative z-10 text-center">
            <div class="text-[16px] font-extrabold tabular-nums" :class="pdkTone(row.cardDelta)">
              {{ signedScore(row.cardDelta) }}
            </div>
            <div class="text-[11px] text-white/55">{{ pdkFactorText(row) }}</div>
            <div v-if="row.fangzouPayer" class="text-[11px] text-[#ffd0c8]">含包赔</div>
            <div v-else-if="row.fangzouCovered" class="text-[11px] text-[#ffd0c8]">已包赔</div>
          </div>
          <div class="relative z-10 text-center text-[16px] font-extrabold tabular-nums" :class="pdkTone(row.bombDelta)">
            {{ signedScore(row.bombDelta) }}
          </div>
          <div class="relative z-10 flex items-center justify-end gap-0.5">
            <img
              v-for="(src, i) in scoreDigitSrcs(row.delta)"
              :key="`${row.absSeat}-s-${i}`"
              :src="src"
              class="h-6 w-auto object-contain"
              alt=""
            />
          </div>
        </template>
      </div>
    </div>

    <div class="mt-5 flex items-center gap-5">
      <button
        v-if="!result.roomOver"
        type="button"
        class="relative h-[56px] w-[200px] transition active:scale-95 disabled:opacity-60"
        :disabled="starting"
        @click="onStartGame"
      >
        <img :src="dizhu('btn_green_long.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
        <span
          class="relative z-10 text-[22px] font-extrabold tracking-wide text-white"
          style="text-shadow: 0 2px 0 rgba(20, 60, 0, 0.35)"
        >
          {{ starting ? '准备中…' : '开始游戏' }}
        </span>
      </button>
      <button
        type="button"
        class="relative h-[56px] w-[180px] transition active:scale-95"
        @click="onHall"
      >
        <img :src="dizhu('btn_orange.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
        <span
          class="relative z-10 text-[20px] font-extrabold tracking-wide text-white"
          style="text-shadow: 0 2px 0 rgba(90, 40, 0, 0.35)"
        >
          返回大厅
        </span>
      </button>
    </div>
  </div>
</template>

<style scoped>
.settle-stage {
  animation: settle-in 0.35s ease-out;
}

@keyframes settle-in {
  from {
    opacity: 0;
    transform: scale(0.92);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}
</style>
