<template>
  <div class="pointer-events-none absolute z-[28]" :style="KING_BOX">
    <img class="absolute -left-0.5 top-0 h-[108%] w-[105%] object-fill" :src="hzmjKingBg" alt="" draggable="false" />
    <img class="absolute left-[14%] top-0 h-[18%] w-[72%] object-contain" :src="hzmjKingTitle" alt="" draggable="false" />
    <div class="absolute left-[12%] top-[24%] h-[54%] w-[76%]">
      <img class="absolute inset-0 h-full w-full object-fill" :src="hzmjKingWhite" alt="" draggable="false" />
      <img class="absolute left-[12%] top-[12%] h-[64%] w-[76%] object-contain" :src="face" alt="" draggable="false" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { hzmjFace, hzmjKingBg, hzmjKingTitle, hzmjKingWhite } from '@/logic/hzmj/hzmjAssets'
import { KING_BOX } from '@/logic/hzmj/roomChrome'

const props = defineProps<{ tile: number }>()
const face = computed(() => hzmjFace(props.tile, 'big'))
</script>
