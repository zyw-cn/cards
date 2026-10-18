<template>
  <div v-show="ops.length" class="pointer-events-none absolute z-30" :style="barStyle">
    <img
      class="pointer-events-none absolute top-1/2 -translate-y-1/2 object-fill"
      :src="hzmjOpBg"
      alt=""
      :style="bgStyle"
      draggable="false"
    />
    <button
      v-for="(op, i) in ops"
      :key="op"
      type="button"
      class="pointer-events-auto absolute border-0 bg-transparent p-0"
      :style="btnStyle(i)"
      @click="emit('select', op)"
    >
      <img
        :src="hzmjOpBtn(op, ops)"
        alt=""
        class="block min-h-[76px] w-[148px] max-w-none object-contain active:scale-95"
        draggable="false"
      />
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { hzmjOpBg, hzmjOpBtn } from '@/logic/hzmj/hzmjAssets'
import { OP_BOX, SX } from '@/logic/hzmj/roomChrome'
import { SHOW_OP } from '@/logic/hzmj/rules/typed'

const props = defineProps<{ ops: number[] }>()
const emit = defineEmits<{ select: [op: number] }>()

const order = [SHOW_OP.PASS, SHOW_OP.WIN, SHOW_OP.KONG, SHOW_OP.PONG, SHOW_OP.CHOW]
const ops = computed(() => order.filter((op) => props.ops.includes(op)))
const BTN_STEP = 158

const barStyle = computed(() => {
  const n = ops.value.length
  const extra = Math.max(0, n - 2) * BTN_STEP * SX
  return {
    ...OP_BOX,
    height: `${120 * SX}px`,
    width: `${300 * SX + extra}px`,
    left: `calc(${OP_BOX.left} - ${extra}px)`,
  }
})

const bgStyle = computed(() => {
  const n = ops.value.length
  return {
    width: `${Math.max(220, (n - 2) * 75 + 420)}px`,
    height: '130%',
    right: n > 2 ? '0' : '-20%',
  }
})

function btnStyle(i: number): Record<string, string> {
  const x = 300 * SX + Math.max(0, ops.value.length - 2) * BTN_STEP * SX - 70 * SX - BTN_STEP * SX * i
  return {
    left: `${x}px`,
    top: '50%',
    transform: 'translate(-50%, -50%)',
    minHeight: '86px',
    minWidth: '148px',
    padding: '4px',
  }
}
</script>
