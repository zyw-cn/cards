<script setup>
import ModalShell from './ModalShell.vue'

defineOptions({ inheritAttrs: false })

defineProps({
  show: { type: Boolean, required: true },
  title: { type: String, default: '' },
  closable: { type: Boolean, default: true },
  width: { type: String, default: '520px' },
  minHeight: { type: String, default: '280px' },
})

defineEmits(['close'])
</script>

<template>
  <ModalShell
    :open="show"
    :width="width"
    :min-height="minHeight"
    :closable="closable"
    @close="$emit('close')"
  >
    <p
      v-if="title"
      class="mb-2.5 text-center text-[18px] font-extrabold tracking-wide text-[#5a3a18] drop-shadow-sm"
    >
      {{ title }}
    </p>
    <div class="w-full text-[13px] leading-5 text-[#4a3018]">
      <slot />
    </div>
    <div v-if="$slots.actions" class="mt-4 flex w-full justify-center gap-2.5">
      <slot name="actions" />
    </div>
  </ModalShell>
</template>
