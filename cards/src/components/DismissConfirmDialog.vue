<script setup>
/**
 * 全玩法统一的解散确认框
 */
import AppDialog from '@/components/AppDialog.vue'
import { art } from '@/utils/art'
import { DISMISS_TITLE, dismissConfirmText } from '@/logic/shared/dismiss'

defineProps({
  show: { type: Boolean, required: true },
  /** 未开局等待中：立即解散文案；否则申请投票文案 */
  idle: { type: Boolean, default: false },
  /** false 时用纯色按钮（红中设置弹层风格） */
  artButtons: { type: Boolean, default: true },
})

defineEmits(['close', 'confirm'])

const btnOrangeLong = art('ui', 'dizhu', 'btn_orange_long.png')
const btnGreenLong = art('ui', 'dizhu', 'btn_green_long.png')
</script>

<template>
  <AppDialog :show="show" :title="DISMISS_TITLE" closable @close="$emit('close')">
    <p class="text-center text-[20px] leading-8 text-[#5a3a18]/85">
      {{ dismissConfirmText(idle) }}
    </p>
    <template #actions>
      <button
        v-if="artButtons"
        type="button"
        class="gf-btn-green flex-1 py-3.5 text-[22px] font-extrabold text-white"
        :style="{ backgroundImage: `url(${btnGreenLong})` }"
        @click="$emit('close')"
      >
        取消
      </button>
      <button
        v-if="artButtons"
        type="button"
        class="gf-btn-orange flex-1 py-3.5 text-[22px] font-extrabold text-white"
        :style="{ backgroundImage: `url(${btnOrangeLong})` }"
        @click="$emit('confirm')"
      >
        确定
      </button>
      <button
        v-if="!artButtons"
        type="button"
        class="flex-1 rounded-lg bg-[#c4a574] py-3 text-[20px] font-bold text-white"
        @click="$emit('close')"
      >
        取消
      </button>
      <button
        v-if="!artButtons"
        type="button"
        class="flex-1 rounded-lg bg-[#e07a2f] py-3 text-[20px] font-bold text-white"
        @click="$emit('confirm')"
      >
        确定
      </button>
    </template>
  </AppDialog>
</template>

<style scoped>
.gf-btn-orange,
.gf-btn-green {
  position: relative;
  border: 0;
  background-color: transparent;
  background-size: 100% 100%;
  background-position: center;
  background-repeat: no-repeat;
  text-shadow: 0 2px 0 rgba(80, 40, 0, 0.35);
}
.gf-btn-green {
  text-shadow: 0 2px 0 rgba(20, 60, 0, 0.35);
}
</style>
