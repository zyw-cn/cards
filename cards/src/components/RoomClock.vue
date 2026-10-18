<template>
  <div
    v-if="displaySec > 0"
    class="room-clock"
    :class="{
      'room-clock--stage': stage,
      'room-clock--compact': compact,
      'room-clock--urgent': urgent,
    }"
  >
    <div class="room-clock__shake">
      <img class="room-clock__img" :src="clockSrc" alt="" />
      <p class="room-clock__num">{{ displaySec }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    seconds: number | string
    clockSrc: string
    /** 桌心大闹钟 */
    stage?: boolean
    /** 头像旁 / 操作区小闹钟 */
    compact?: boolean
  }>(),
  { stage: false, compact: false },
)

const displaySec = computed(() => Math.max(0, Math.floor(Number(props.seconds) || 0)))
const urgent = computed(() => displaySec.value > 0 && displaySec.value <= 3)
</script>

<style scoped>
.room-clock {
  position: relative;
  width: 64px;
  height: 70px;
  margin: 0 auto 8px;
  pointer-events: none;
}

.room-clock--stage {
  width: 72px;
  height: 78px;
  margin: 0;
}

.room-clock--compact {
  width: 44px;
  height: 48px;
  margin: 0;
  flex-shrink: 0;
}

.room-clock--compact .room-clock__num {
  font-size: 16px;
}

.room-clock__shake {
  position: relative;
  width: 100%;
  height: 100%;
  transform-origin: 50% 8%;
}

.room-clock--urgent .room-clock__shake {
  animation: clock-ring 0.36s ease-in-out infinite;
}

.room-clock--urgent .room-clock__num {
  color: #ff3b3b;
  text-shadow:
    0 0 6px rgba(255, 80, 80, 0.85),
    0 1px 2px rgba(0, 0, 0, 0.55);
}

.room-clock__img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
  filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.45));
}

.room-clock--urgent .room-clock__img {
  filter:
    drop-shadow(0 2px 4px rgba(0, 0, 0, 0.45))
    drop-shadow(0 0 6px rgba(255, 70, 40, 0.55));
}

/* bull_clock / flower clock：数字落在蓝色圆盘中心 */
.room-clock__num {
  position: absolute;
  left: 0;
  right: 0;
  top: 18%;
  bottom: 8%;
  margin: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 22px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  line-height: 1;
  letter-spacing: -0.02em;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.55);
  pointer-events: none;
}

.room-clock--stage .room-clock__num {
  font-size: 26px;
}

@keyframes clock-ring {
  0%,
  100% {
    transform: rotate(0deg);
  }
  18% {
    transform: rotate(-16deg);
  }
  36% {
    transform: rotate(14deg);
  }
  54% {
    transform: rotate(-11deg);
  }
  72% {
    transform: rotate(8deg);
  }
  88% {
    transform: rotate(-4deg);
  }
}
</style>
