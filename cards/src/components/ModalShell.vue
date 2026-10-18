<script setup>
import { art } from '../utils/art'

defineOptions({ inheritAttrs: false })

defineProps({
  open: { type: Boolean, default: false },
  width: { type: String, default: '640px' },
  minHeight: { type: String, default: '360px' },
  closable: { type: Boolean, default: true },
  /** false：外层不滚，由内部自己滚动（便于顶栏固定） */
  scrollable: { type: Boolean, default: true },
})
defineEmits(['close'])
</script>

<template>
  <Teleport to="#modal-root">
    <div
      v-if="open"
      class="pointer-events-auto absolute inset-0 flex items-center justify-center bg-black/55"
      @click.self="$emit('close')"
    >
      <div
        class="relative flex max-h-[94%] flex-col items-center rounded-[24px] border-[6px] border-[#222] bg-[#f7f1dc]"
        :style="{ width, minHeight }"
      >
        <div
          class="pointer-events-none absolute inset-x-0 top-0 h-11 rounded-t-[18px] bg-gradient-to-b from-white/35 to-transparent"
        />
        <button
          v-if="closable"
          class="absolute right-2 top-2 z-20 h-16 w-16 transition hover:brightness-110 active:scale-95"
          type="button"
          @click="$emit('close')"
        >
          <img :src="art('hall_modals', 'close.png')" class="h-full w-full object-contain" alt="关闭" />
        </button>
        <div
          class="relative z-10 flex min-h-0 w-full flex-1 flex-col items-center px-12 pb-12 pt-16"
          :class="scrollable ? 'overflow-y-auto' : 'overflow-hidden'"
        >
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>
