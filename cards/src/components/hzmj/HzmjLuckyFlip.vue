<template>
  <div class="pointer-events-auto absolute inset-0 z-[110] flex items-center justify-center bg-black/35">
    <div class="relative h-40 rounded-lg bg-[rgba(0,40,36,0.75)]" :style="{ width: `${panelW}px` }">
      <div
        v-for="(t, i) in tiles"
        :key="i"
        class="absolute top-5 h-[122px] w-[92px]"
        :style="{ left: `${10 + 100 * i}px` }"
      >
        <img class="block h-[122px] w-[92px]" :src="revealed > i ? hzmjFrame('hand') : hzmjFrame('back')" alt="" draggable="false" />
        <img
          v-if="revealed > i"
          class="absolute left-[12%] top-[10%] h-[68%] w-[76%] object-contain"
          :src="hzmjFace(t, 'big')"
          alt=""
          draggable="false"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { hzmjFace, hzmjFrame } from '@/logic/hzmj/hzmjAssets'

const props = defineProps<{ tiles: number[]; revealed: number }>()
const panelW = computed(() => {
  const n = props.tiles.length
  if (n <= 0) return 120
  return 100 * (n - 1) + 120
})
</script>
