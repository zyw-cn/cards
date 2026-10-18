<script setup>
import { computed } from 'vue'
import { art } from '../utils/art'

const props = defineProps({
  /** 房间号字符串，仅取数字位展示 */
  value: { type: [String, Number], default: '' },
  /**
   * glass: misc 白色透明数字（牌桌 / 深色底）
   * pad: cards room_number 深色数字（浅底弹窗）
   * jsq: 闹钟同款位图数字
   */
  variant: { type: String, default: 'glass' },
  digitClass: { type: String, default: 'h-7' },
})

const digits = computed(() => {
  const raw = String(props.value ?? '').replace(/\D/g, '')
  if (!raw) return ['-', '-', '-', '-', '-', '-']
  return raw.split('')
})

function digitSrc(ch) {
  if (ch === '-' || ch < '0' || ch > '9') return ''
  if (props.variant === 'pad') return art('room', 'number', `${ch}.png`)
  if (props.variant === 'glass') return art('misc', 'digits', `${ch}.png`)
  return art('room', 'number', `jsq${ch}.png`)
}
</script>

<template>
  <div class="inline-flex items-center justify-center gap-0.5" aria-label="房间号">
    <img
      v-for="(ch, i) in digits"
      :key="`${i}-${ch}`"
      v-show="digitSrc(ch)"
      :src="digitSrc(ch)"
      :class="['object-contain', digitClass]"
      alt=""
    />
  </div>
</template>
