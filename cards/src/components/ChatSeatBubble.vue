<script setup>
import { art } from '@/utils/art'

defineProps({
  text: { type: String, default: '' },
  /** left：尾巴指向左侧头像；right：镜像后尾巴指向右侧头像；self：自己头像右侧 */
  side: { type: String, default: 'left' },
})

const capL = art('room', 'chat', 'dialog_box1.png')
const capM = art('room', 'chat', 'diaglog_box2.png')
const capR = art('room', 'chat', 'dialog_box3.png')
</script>

<template>
  <div v-if="text" class="seat-chat" :class="`seat-chat--${side}`">
    <span class="seat-chat__cap seat-chat__cap--l" :style="{ backgroundImage: `url(${capL})` }" />
    <span class="seat-chat__body" :style="{ backgroundImage: `url(${capM})` }">{{ text }}</span>
    <span class="seat-chat__cap seat-chat__cap--r" :style="{ backgroundImage: `url(${capR})` }" />
  </div>
</template>

<style scoped>
.seat-chat {
  position: absolute;
  z-index: 80;
  display: flex;
  align-items: stretch;
  width: max-content;
  max-width: 220px;
  min-height: 40px;
  pointer-events: none;
  filter: drop-shadow(0 2px 2px rgba(0, 0, 0, 0.35));
}

.seat-chat__cap {
  flex: 0 0 auto;
  background-repeat: no-repeat;
  background-size: 100% 100%;
}

.seat-chat__cap--l {
  width: 26px;
}

.seat-chat__cap--r {
  width: 18px;
}

.seat-chat__body {
  display: flex;
  align-items: center;
  flex: 1 1 auto;
  min-width: 72px;
  max-width: 168px;
  background-repeat: no-repeat;
  background-size: 100% 100%;
  padding: 7px 4px 9px;
  color: #1d4a12;
  font-size: 16px;
  font-weight: 700;
  line-height: 1.25;
  word-break: break-word;
}

.seat-chat--left {
  left: 124px;
  top: 18px;
}

.seat-chat--right {
  right: 124px;
  top: 18px;
  transform: scaleX(-1);
}

.seat-chat--right .seat-chat__body {
  transform: scaleX(-1);
}

.seat-chat--self {
  left: calc(100% + 8px);
  top: 8px;
}
</style>
