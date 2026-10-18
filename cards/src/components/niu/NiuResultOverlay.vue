<template>
  <div v-if="show" class="result" :class="{ 'result--peek': peeking }">
    <div class="result__mask" />
    <div class="result__stage">
      <img
        class="result__banner"
        :class="{ lose: !selfWin }"
        :src="selfWin ? youWin : youLose"
        :alt="selfWin ? '你赢啦' : '败'"
      />

      <div class="result__board">
        <div class="result__head">本局结算</div>
        <div class="result__cols">
          <span>玩家</span>
          <span>牌型</span>
          <span>输赢</span>
        </div>
        <div class="result__list">
          <div
            v-for="row in rows"
            :key="row.id"
            class="result__row"
            :class="{ win: row.delta > 0, lose: row.delta < 0, self: row.isSelf }"
          >
            <div class="result__who">
              <div class="result__avatar-wrap">
                <img class="result__avatar" :src="avatarOf(row)" alt="" />
                <span v-if="row.isBanker" class="result__banker">庄</span>
              </div>
              <div class="result__name">
                <span class="result__nick">{{ row.nickname }}</span>
                <span v-if="row.isSelf" class="result__tag">我</span>
                <span v-if="row.isBigWinner" class="result__tag result__tag--win">大赢家</span>
              </div>
            </div>
            <div class="result__hand">
              <img
                v-if="row.badge"
                class="result__niu"
                :src="row.badge"
                :alt="row.niuName"
              />
              <span v-else-if="row.niuName" class="result__niu-text">{{ row.niuName }}</span>
              <span v-else class="result__niu-text">—</span>
            </div>
            <div class="result__score-col">
              <div
                class="result__score"
                :class="{ plus: row.delta > 0, minus: row.delta < 0 }"
              >
                {{ row.delta > 0 ? '+' : '' }}{{ row.delta }}
              </div>
              <div v-if="row.platformFee > 0" class="result__fee">
                场地费 -{{ formatFee(row.platformFee) }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="result__actions">
        <button
          type="button"
          class="result__peek"
          @pointerdown.prevent="startPeek"
          @contextmenu.prevent
        >
          {{ peeking ? '松手看结算' : '按住看牌' }}
        </button>
        <button
          type="button"
          class="result__again"
          :style="{ backgroundImage: `url(${btnOrange})` }"
          @click="$emit('again')"
        >
          再来一局
        </button>
        <button
          type="button"
          class="result__hall"
          :style="{ backgroundImage: `url(${btnBlue})` }"
          @click="$emit('hall')"
        >
          返回大厅
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import { niuUi } from '@/logic/niu/niuUi'
import { assetUrl } from '@/services/assetUrl'
import { seatAvatarSrc } from '@/services/seatAvatar'

export type NiuResultRow = {
  id: number
  nickname: string
  avatar: string
  delta: number
  niuName: string
  badge: string
  isSelf: boolean
  isBanker: boolean
  isBigWinner: boolean
  platformFee: number
}

defineProps<{
  show: boolean
  selfWin: boolean
  rows: NiuResultRow[]
}>()

defineEmits<{ again: []; hall: [] }>()

const youWin = assetUrl('images/niu/winner.png')
const youLose = assetUrl('images/niu/comLoser.png')
const btnOrange = niuUi.btnOrange
const btnBlue = niuUi.btnBlue
const peeking = ref(false)
let stopPeek: (() => void) | null = null

/** 按住收起结算，露出桌上手牌；松手再把结算弹回来 */
function startPeek(ev: PointerEvent): void {
  if (ev.button != null && ev.button !== 0) return
  stopPeek?.()
  peeking.value = true
  const end = () => {
    peeking.value = false
    window.removeEventListener('pointerup', end)
    window.removeEventListener('pointercancel', end)
    window.removeEventListener('blur', end)
    if (stopPeek === end) stopPeek = null
  }
  stopPeek = end
  window.addEventListener('pointerup', end)
  window.addEventListener('pointercancel', end)
  window.addEventListener('blur', end)
}

onBeforeUnmount(() => stopPeek?.())

function avatarOf(row: NiuResultRow): string {
  return seatAvatarSrc(row.avatar, row.id)
}

function formatFee(v: number): string {
  const n = Number(v) || 0
  return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/0+$/, '').replace(/\.$/, '')
}
</script>

<style scoped>
.result {
  position: absolute;
  inset: 0;
  z-index: 120;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: auto;
}

.result--peek {
  pointer-events: none;
  align-items: flex-end;
  justify-content: center;
  padding-bottom: 28px;
}

.result--peek .result__mask,
.result--peek .result__banner,
.result--peek .result__board,
.result--peek .result__again,
.result--peek .result__hall {
  display: none;
}

.result--peek .result__stage {
  width: auto;
  max-width: none;
  animation: none;
  pointer-events: none;
}

.result--peek .result__actions {
  margin-top: 0;
  pointer-events: none;
}

.result--peek .result__peek {
  pointer-events: auto;
}

.result__mask {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.65);
}

.result__stage {
  position: relative;
  z-index: 1;
  width: 720px;
  max-width: 92%;
  display: flex;
  flex-direction: column;
  align-items: center;
  animation: result-in 0.35s ease-out;
}

@keyframes result-in {
  from {
    opacity: 0;
    transform: scale(0.88);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}

.result__banner {
  height: 56px;
  margin-bottom: 8px;
  z-index: 2;
  filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.5));
}

.result__banner.lose {
  height: 72px;
}

.result__board {
  position: relative;
  width: 100%;
  max-height: 460px;
  display: flex;
  flex-direction: column;
  padding: 14px 16px 10px;
  box-sizing: border-box;
  border-radius: 16px;
  border: 3px solid #f0d78a;
  background: linear-gradient(180deg, #8a6730 0%, #4a3414 42px, #2c2112 42px);
  box-shadow: 0 16px 40px rgba(0, 0, 0, 0.45);
}

.result__head {
  text-align: center;
  color: #ffe08a;
  font-size: 22px;
  font-weight: 800;
  letter-spacing: 0.24em;
  text-shadow: 0 1px 3px #000;
}

.result__cols {
  display: grid;
  grid-template-columns: minmax(0, 1.5fr) 120px 120px;
  gap: 8px;
  margin: 10px 4px 6px;
  padding: 0 8px;
  color: rgba(255, 224, 138, 0.72);
  font-size: 13px;
  font-weight: 700;
}
.result__cols span:nth-child(2),
.result__cols span:nth-child(3) {
  text-align: right;
}

.result__list {
  overflow: auto;
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding-right: 2px;
}

.result__row {
  display: grid;
  grid-template-columns: minmax(0, 1.5fr) 120px 120px;
  align-items: center;
  gap: 8px;
  min-height: 56px;
  padding: 6px 8px;
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.22);
}

.result__row.win {
  background: rgba(255, 200, 80, 0.14);
}

.result__row.lose {
  background: rgba(80, 100, 140, 0.12);
}

.result__row.self {
  box-shadow: inset 0 0 0 1px rgba(255, 220, 120, 0.4);
}

.result__who {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.result__avatar-wrap {
  position: relative;
  width: 44px;
  height: 44px;
  flex-shrink: 0;
}

.result__avatar {
  width: 100%;
  height: 100%;
  border-radius: 8px;
  object-fit: cover;
  border: 1px solid rgba(255, 220, 140, 0.45);
}

.result__banker {
  position: absolute;
  right: -6px;
  top: -6px;
  z-index: 2;
  min-width: 20px;
  height: 20px;
  line-height: 20px;
  text-align: center;
  border-radius: 999px;
  background: linear-gradient(180deg, #ffe08a, #e0a040);
  color: #5a2a00;
  font-size: 10px;
  font-weight: 800;
}

.result__name {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
  color: #fff;
  font-size: 16px;
  font-weight: 700;
  text-shadow: 0 1px 2px #000;
}

.result__tag {
  flex-shrink: 0;
  padding: 0 6px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  font-size: 12px;
  line-height: 18px;
}

.result__tag--win {
  background: rgba(255, 196, 70, 0.22);
  color: #ffe08a;
  border: 1px solid rgba(255, 214, 110, 0.7);
}

.result__hand {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  min-width: 0;
}

.result__nick {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.result__niu {
  height: 26px;
  max-width: 112px;
  width: auto;
  object-fit: contain;
  flex-shrink: 0;
}

.result__niu-text {
  flex-shrink: 0;
  padding: 1px 7px;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.45);
  border: 1px solid rgba(255, 214, 110, 0.7);
  color: #ffe08a;
  font-size: 12px;
  font-weight: 800;
}

.result__score-col {
  flex-shrink: 0;
  text-align: right;
}

.result__score {
  font-size: 20px;
  font-weight: 800;
  min-width: 64px;
  text-align: right;
  text-shadow: 0 1px 2px #000;
  color: rgba(255, 255, 255, 0.85);
}

.result__score.plus {
  color: rgb(234, 171, 55);
  text-shadow: rgb(120, 42, 0) 1px 2px 2px;
}

.result__score.minus {
  color: #fff;
  text-shadow: rgb(2, 32, 85) 1px 2px 2px;
}

.result__fee {
  margin-top: 2px;
  color: #ffb4a2;
  font-size: 11px;
  font-weight: 700;
}

.result__actions {
  margin-top: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
}

.result__again,
.result__hall,
.result__peek {
  min-width: 148px;
  height: 46px;
  border: 0;
  color: #fff;
  font-size: 17px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-shadow: 0 1px 2px #000;
  cursor: pointer;
  background-color: transparent;
  background-repeat: no-repeat;
  background-position: center;
  background-size: 100% 100%;
}

.result__peek {
  background-color: #6b4a1e;
  background-image: linear-gradient(180deg, #e0b15a, #8a5a18);
  border-radius: 8px;
  touch-action: none;
  user-select: none;
}
</style>
