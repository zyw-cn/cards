<template>
  <div v-if="show" class="pointer-events-auto absolute inset-0 z-[130] flex items-center justify-center">
    <div class="absolute inset-0 bg-black/70" @click="$emit('cancel')" />
    <div
      class="relative z-[1] w-[70%] max-w-[520px] rounded-2xl border-2 border-[rgba(255,210,100,0.55)] bg-[rgba(20,12,28,0.92)] px-6 pb-7 pt-5 shadow-[0_0_2rem_rgba(255,180,60,0.25)]"
    >
      <p class="mb-5 text-center text-xl font-extrabold text-[#ffe08a]">
        {{ left.nickname || '' }} VS {{ right.nickname || '' }}
      </p>
      <div class="flex items-center justify-around gap-4">
        <div class="relative flex flex-col items-center gap-2">
          <img
            class="h-20 w-20 rounded-full border-[3px] border-[rgba(255,220,140,0.7)] object-cover bg-[#273151]"
            :src="seatAvatarSrc(left.avatar, left.id)"
            alt=""
          />
          <p class="text-base font-bold text-white">{{ left.nickname }}</p>
          <img
            v-if="done && String(loserId) === String(left.id)"
            class="absolute -right-2 -top-1 h-12 w-auto"
            :src="loseSrc"
            alt="败"
          />
        </div>
        <img class="h-14 w-auto drop-shadow" :src="vsSrc" alt="VS" />
        <div class="relative flex flex-col items-center gap-2">
          <img
            class="h-20 w-20 rounded-full border-[3px] border-[rgba(255,220,140,0.7)] object-cover bg-[#273151]"
            :src="seatAvatarSrc(right.avatar, right.id)"
            alt=""
          />
          <p class="text-base font-bold text-white">{{ right.nickname }}</p>
          <img
            v-if="done && String(loserId) === String(right.id)"
            class="absolute -right-2 -top-1 h-12 w-auto"
            :src="loseSrc"
            alt="败"
          />
        </div>
      </div>
      <p class="mt-5 text-center text-lg font-extrabold text-white">
        {{ done ? resultText : '比牌中…' }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { assetUrl } from '@/services/assetUrl'
import { seatAvatarSrc } from '@/services/seatAvatar'

export type PkSide = { id: number; nickname: string; avatar: string }

const props = defineProps<{
  show: boolean
  left: PkSide
  right: PkSide
  loserId: number | string | null
  done: boolean
}>()

defineEmits<{ cancel: [] }>()

const loseSrc = assetUrl('images/flower/comLoser.png')
const vsSrc = assetUrl('images/flower/comV.png')

const resultText = computed(() => {
  if (!props.done) return ''
  if (String(props.loserId) === String(props.left?.id)) {
    return `${props.right?.nickname || ''} 胜`
  }
  return `${props.left?.nickname || ''} 胜`
})
</script>
