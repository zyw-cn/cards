<template>
  <div v-if="show" class="pointer-events-none absolute inset-0 z-[110] flex items-center justify-center">
    <div class="absolute inset-0 bg-black/60" />
    <div
      class="relative z-[1] w-[82%] max-w-[640px] rounded-2xl border border-[rgba(255,214,110,0.5)] bg-gradient-to-b from-[rgba(48,54,88,0.97)] to-[rgba(16,20,38,0.97)] px-6 pb-6 pt-5 text-center shadow-2xl"
    >
      <p class="text-2xl font-extrabold text-[#ffe08a]">{{ title }}</p>
      <div class="mt-5 flex flex-wrap items-start justify-center gap-4">
        <div
          v-for="item in candidates"
          :key="item.id"
          class="w-[100px] rounded-xl border-2 px-2 py-3 transition-all duration-100"
          :class="itemClass(item.id)"
        >
          <div class="relative mx-auto h-16 w-16">
            <img
              class="h-full w-full rounded-full border-[3px] border-[rgba(255,220,140,0.75)] object-cover bg-[#273151]"
              :src="avatarOf(item)"
              alt=""
            />
            <img
              v-if="done && String(item.id) === String(winnerId)"
              class="absolute -right-2 -top-2 h-9 w-9 object-contain drop-shadow"
              :src="bankerIcon"
              alt="庄"
            />
          </div>
          <p class="mt-2 truncate text-sm font-bold text-white">{{ item.nickname }}</p>
          <p v-if="item.multiple > 0" class="mt-0.5 text-sm font-extrabold text-[#ffd76a]">
            叫{{ item.multiple }}倍
          </p>
        </div>
      </div>
      <p class="mt-5 text-lg font-bold text-white">{{ hint }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { assetUrl } from '@/services/assetUrl'
import { seatAvatarSrc } from '@/services/seatAvatar'

export type RollCandidate = { id: number; nickname: string; avatar: string; multiple: number }

const props = withDefaults(
  defineProps<{
    show: boolean
    candidates: RollCandidate[]
    winnerId: number | string | null
    mode?: 'tie' | 'system'
  }>(),
  { mode: 'tie', candidates: () => [] },
)

const emit = defineEmits<{
  done: []
  highlight: [id: number | null]
  rolling: [ids: number[]]
}>()

const highlightId = ref<number | null>(null)
const done = ref(false)
const timers: number[] = []
const bankerIcon = assetUrl('images/niu/zhuang.png')

function avatarOf(item: RollCandidate): string {
  return seatAvatarSrc(item.avatar, item.id)
}

const title = computed(() =>
  props.mode === 'system' ? '无人抢庄 · 随机定庄' : '叫分相同 · 随机定庄',
)

const hint = computed(() => {
  if (done.value) {
    const win = props.candidates.find((c) => String(c.id) === String(props.winnerId))
    return win ? `庄家：${win.nickname}` : '定庄完成'
  }
  return '边框闪动中，即将选出庄家…'
})

function itemClass(id: number): string {
  const active = String(id) === String(highlightId.value)
  const winner = done.value && String(id) === String(props.winnerId)
  if (winner) {
    return 'scale-110 border-[#ffd45a] bg-[rgba(255,212,90,0.2)] shadow-[0_0_1.5rem_rgba(255,212,90,0.9)]'
  }
  if (active) {
    return 'scale-110 border-[#ffd45a] bg-[rgba(255,212,90,0.12)] shadow-[0_0_1rem_rgba(255,212,90,0.75)]'
  }
  return 'border-white/10 bg-white/[0.04]'
}

function clearTimers(): void {
  while (timers.length) window.clearTimeout(timers.pop())
}

function runRoll(): void {
  clearTimers()
  done.value = false
  highlightId.value = null
  const list = props.candidates || []
  const ids = list.map((c) => c.id)
  emit('rolling', ids)

  if (ids.length < 2) {
    done.value = true
    highlightId.value = Number(props.winnerId) || ids[0] || null
    emit('highlight', highlightId.value)
    timers.push(window.setTimeout(() => emit('done'), 800))
    return
  }

  const winner = props.winnerId
  const steps = 18 + Math.floor(Math.random() * 6)
  let i = 0
  let delay = 70
  let lastIdx = -1

  const tick = (): void => {
    i += 1
    let idx: number
    if (i >= steps) {
      idx = ids.findIndex((id) => String(id) === String(winner))
      if (idx < 0) idx = 0
    } else {
      do {
        idx = Math.floor(Math.random() * ids.length)
      } while (ids.length > 1 && idx === lastIdx)
    }
    lastIdx = idx
    highlightId.value = ids[idx]
    emit('highlight', ids[idx])

    if (i >= steps) {
      highlightId.value = Number(winner) || ids[idx]
      emit('highlight', highlightId.value)
      done.value = true
      timers.push(window.setTimeout(() => emit('done'), 1400))
      return
    }
    delay = Math.min(340, delay + (i > steps - 7 ? 42 : 12))
    timers.push(window.setTimeout(tick, delay))
  }
  tick()
}

watch(
  () => props.show,
  (v) => {
    if (v) runRoll()
    else {
      clearTimers()
      done.value = false
      highlightId.value = null
      emit('rolling', [])
      emit('highlight', null)
    }
  },
)

onBeforeUnmount(clearTimers)
</script>
