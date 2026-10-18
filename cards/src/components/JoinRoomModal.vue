<script setup>
import { computed, ref, watch } from 'vue'
import { art } from '../utils/art'
import ModalShell from './ModalShell.vue'

const props = defineProps({
  open: { type: Boolean, default: false },
  /** 房间号不对：才显示「房间不存在」提示图 */
  missing: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'join', 'clearMissing'])

const digits = ref([])
const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'retype', '0', 'delete']

watch(
  () => props.open,
  (v) => {
    if (v) digits.value = []
  },
)

const slots = computed(() => {
  const arr = [...digits.value]
  while (arr.length < 6) arr.push('')
  return arr.slice(0, 6)
})

function press(key) {
  if (props.missing) emit('clearMissing')
  if (key === 'retype') {
    digits.value = []
    return
  }
  if (key === 'delete') {
    digits.value.pop()
    return
  }
  if (digits.value.length >= 6) return
  digits.value.push(key)
  if (digits.value.length === 6) {
    emit('join', digits.value.join(''))
  }
}
</script>

<template>
  <ModalShell :open="open" width="760px" min-height="700px" @close="$emit('close')">
    <img :src="art('hall_modals', 'join_room', 'title_join.png')" class="mb-3 h-14 object-contain" alt="加入房间" />
    <img
      v-if="missing"
      :src="art('hall_modals', 'join_room', 'tips.png')"
      class="mb-5 h-9 object-contain"
      alt="房间不存在"
    />

    <div class="mb-7 flex gap-3.5">
      <div
        v-for="(d, i) in slots"
        :key="i"
        class="relative flex h-[76px] w-[76px] items-center justify-center bg-[length:100%_100%]"
        :style="{ backgroundImage: `url(${art('hall_modals', 'join_room', 'circle.png')})` }"
      >
        <!-- 圆框里用白色数字切图，不用黄色按键图 -->
        <span
          v-if="d !== ''"
          class="pointer-events-none absolute inset-[18%] rounded-full bg-[#1a2a22]/72"
        />
        <img
          v-if="d !== ''"
          :src="art('misc', 'digits', `${d}.png`)"
          class="relative z-[1] h-11 object-contain drop-shadow"
          alt=""
        />
      </div>
    </div>

    <div class="grid grid-cols-3 gap-3.5">
      <button
        v-for="key in keys"
        :key="key"
        class="flex h-[80px] w-[156px] items-center justify-center transition hover:brightness-110 active:scale-95"
        type="button"
        @click="press(key)"
      >
        <img
          v-if="key === 'retype'"
          :src="art('hall_modals', 'join_room', 'retype.png')"
          class="h-full w-full object-contain"
          alt="重输"
        />
        <img
          v-else-if="key === 'delete'"
          :src="art('hall_modals', 'join_room', 'delete.png')"
          class="h-full w-full object-contain"
          alt="删除"
        />
        <img
          v-else
          :src="art('hall_modals', 'join_room', `${key}.png`)"
          class="h-14 object-contain drop-shadow"
          alt=""
        />
      </button>
    </div>
  </ModalShell>
</template>
