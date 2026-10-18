<template>
  <div v-if="visible" class="pointer-events-none absolute inset-0 z-[48] flex items-center justify-center">
    <div class="relative h-[100px] w-[160px]" :class="settled ? '' : 'dice-bounce'">
      <img
        class="absolute left-[18px] top-[28px] h-16 w-[62px] object-contain drop-shadow"
        :class="settled ? '' : 'dice-spin'"
        :src="hzmjDice(faceB)"
        alt=""
        draggable="false"
      />
      <img
        class="absolute left-[78px] top-2 h-16 w-[62px] object-contain drop-shadow"
        :class="settled ? '' : 'dice-spin'"
        :src="hzmjDice(faceA)"
        alt=""
        draggable="false"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { hzmjDice } from '@/logic/hzmj/hzmjAssets'
import { playHzmjSfx } from '@/logic/hzmj/hzmjAudio'

const props = defineProps<{ dice: number[] }>()

const visible = ref(false)
const settled = ref(false)
const faceA = ref(1)
const faceB = ref(1)
let rollTimer = 0
let doneTimer = 0
let epoch = 0
let lastStamp = ''

function pair(): [number, number] {
  const list = props.dice
  const a = Number(list[0]) || 1
  const b = Number(list[1]) || a
  return [Math.min(6, Math.max(1, a)), Math.min(6, Math.max(1, b))]
}

function stop(): void {
  window.clearInterval(rollTimer)
  window.clearTimeout(doneTimer)
  visible.value = false
  settled.value = false
}

function play(): void {
  const my = ++epoch
  stop()
  const final = pair()
  visible.value = true
  settled.value = false
  faceA.value = 1 + Math.floor(Math.random() * 6)
  faceB.value = 1 + Math.floor(Math.random() * 6)
  playHzmjSfx('saizi')
  let ticks = 0
  rollTimer = window.setInterval(() => {
    if (my !== epoch) return
    ticks += 1
    faceA.value = 1 + Math.floor(Math.random() * 6)
    faceB.value = 1 + Math.floor(Math.random() * 6)
    if (ticks >= 12) {
      window.clearInterval(rollTimer)
      faceA.value = final[0]
      faceB.value = final[1]
      settled.value = true
      doneTimer = window.setTimeout(() => {
        if (my === epoch) stop()
      }, 700)
    }
  }, 90)
}

watch(
  () => props.dice.join(','),
  (stamp) => {
    if (!stamp || stamp === lastStamp) {
      if (!stamp) stop()
      return
    }
    lastStamp = stamp
    play()
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  epoch += 1
  stop()
})
</script>

<style scoped>
.dice-bounce {
  animation: dice-bounce 0.35s ease-out;
}
.dice-spin {
  animation: dice-spin 0.12s linear infinite;
}
@keyframes dice-bounce {
  from { transform: translateY(40px) scale(0.7); opacity: 0.4; }
  to { transform: none; opacity: 1; }
}
@keyframes dice-spin {
  0% { transform: rotate(-8deg); }
  50% { transform: rotate(8deg) scale(1.05); }
  100% { transform: rotate(-8deg); }
}
</style>
