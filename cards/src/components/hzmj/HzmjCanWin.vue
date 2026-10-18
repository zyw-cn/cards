<template>
  <div
    v-if="showPanel || expanded"
    class="pointer-events-none absolute left-1/2 z-[38] -translate-x-1/2"
    :class="panelBottomClass"
  >
    <div
      class="relative min-w-[160px] max-w-[720px] px-5 pb-3.5 pt-9"
      :style="{ backgroundImage: `url(${hzmjCanWinFrame})`, backgroundSize: '100% 100%' }"
    >
      <img class="relative z-[1] mx-auto block h-7 w-auto" :src="hzmjCanWinTitle" alt="" draggable="false" />
      <div class="relative z-[1] mt-2 flex max-w-[680px] flex-wrap justify-center gap-1.5">
        <img v-if="isAny" class="h-7 w-auto" :src="hzmjCanWinAny" alt="" draggable="false" />
        <div v-for="t in tiles" v-else :key="t" class="relative h-12 w-9">
          <img class="block h-full w-full" :src="hzmjFrame('desk')" alt="" draggable="false" />
          <img class="absolute left-1/2 top-[40%] h-[55%] w-[70%] -translate-x-1/2 -translate-y-1/2 object-contain" :src="hzmjFace(t, 'small')" alt="" draggable="false" />
        </div>
      </div>
    </div>
  </div>
  <button
    v-else-if="showTingBtn"
    type="button"
    class="absolute left-1/2 z-[38] -translate-x-1/2 border-0 bg-transparent p-0"
    :class="tingBtnBottomClass"
    @click="expanded = !expanded"
  >
    <img :src="hzmjTingBtn" alt="听" class="h-12 w-auto object-contain" draggable="false" />
  </button>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { hzmjCanWinAny, hzmjCanWinFrame, hzmjCanWinTitle, hzmjFace, hzmjFrame, hzmjTingBtn } from '@/logic/hzmj/hzmjAssets'
import { getCanWinTiles } from '@/logic/hzmj/rules/typed'

const props = defineProps<{
  hand: number[]
  selected: number
  kings: number[]
  myTurn: boolean
  /** 操作条展开时抬高，避免与碰杠胡按钮叠压 */
  opsOpen?: boolean
}>()

const expanded = ref(false)

watch(
  () => [props.selected, props.hand.length],
  () => {
    expanded.value = false
  },
)

const winTiles = computed(() => {
  const sel = Number(props.selected) || 0
  if (sel <= 0) return [] as number[]
  return getCanWinTiles(props.hand, sel, props.kings)
})

const tingTiles = computed(() => {
  if (Number(props.selected) > 0) return [] as number[]
  return getCanWinTiles(props.hand, 0, props.kings)
})

const showPanel = computed(() => winTiles.value.length > 0)
const isAny = computed(() => (showPanel.value ? winTiles.value : tingTiles.value).length >= 34)
const tiles = computed(() => (showPanel.value ? winTiles.value : tingTiles.value))
const showTingBtn = computed(() => props.myTurn && tingTiles.value.length > 0)

const panelBottomClass = computed(() => {
  if (props.opsOpen) return showPanel.value ? 'bottom-[248px]' : 'bottom-[268px]'
  return showPanel.value ? 'bottom-[168px]' : 'bottom-[200px]'
})

const tingBtnBottomClass = computed(() => (props.opsOpen ? 'bottom-[228px]' : 'bottom-[148px]'))
</script>
