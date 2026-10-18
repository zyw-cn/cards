<script setup>
import { computed } from 'vue'
import { quickMessages } from '../config/quickMessages'

const props = defineProps({
  show: { type: Boolean, default: false },
  messages: { type: Array, default: null },
})

const emit = defineEmits(['update:show', 'select'])

const list = computed(() =>
  Array.isArray(props.messages) && props.messages.length ? props.messages : quickMessages,
)

function close() {
  emit('update:show', false)
}

function pick(text) {
  emit('select', text)
  emit('update:show', false)
}
</script>

<template>
  <div class="pointer-events-none absolute inset-0 z-[200]" :class="show ? 'pointer-events-auto' : ''">
    <div v-if="show" class="absolute inset-0 bg-transparent" @click="close" />
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="translate-y-4 opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-4 opacity-0"
    >
      <div
        v-if="show"
        class="absolute bottom-[11%] right-[2%] z-[1] box-border max-h-[58%] w-[42%] max-w-[520px] overflow-y-auto rounded-2xl border border-white/20 bg-[rgba(12,18,32,0.52)] px-3 py-3 shadow-lg backdrop-blur-md"
        @click.stop
      >
        <button
          v-for="(text, idx) in list"
          :key="idx"
          type="button"
          class="mb-2 block w-full rounded-xl border-0 bg-white/10 px-3 py-3 text-left text-base leading-snug text-white last:mb-0 active:bg-[rgba(255,220,140,0.28)]"
          style="text-shadow: 0 1px 2px rgba(0, 0, 0, 0.45)"
          @click="pick(text)"
        >
          {{ text }}
        </button>
      </div>
    </Transition>
  </div>
</template>
