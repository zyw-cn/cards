<template>
  <button
    type="button"
    class="flex h-11 w-[7.5rem] shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white/90 ring-1 ring-white/20"
    :title="loading ? '加载中' : '点击刷新验证码'"
    @click="refresh"
  >
    <img v-if="imgSrc" :src="imgSrc" alt="验证码" class="h-full w-full object-contain" />
    <span v-else class="px-1 text-center text-[11px] leading-tight text-slate-600">
      {{ loading ? '加载中…' : error || '点击刷新' }}
    </span>
  </button>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { fetchCaptcha } from '../services/authApi'

const imgSrc = ref('')
const captchaKey = ref('')
const loading = ref(false)
const error = ref('')

async function refresh() {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    const res = await fetchCaptcha()
    if (res.code === 0 && res.data) {
      captchaKey.value = String(res.data.key || '')
      imgSrc.value = String(res.data.img || '')
      if (!imgSrc.value) error.value = '无图'
    } else {
      imgSrc.value = ''
      captchaKey.value = ''
      error.value = res.msg || '获取失败'
    }
  } catch (e) {
    imgSrc.value = ''
    captchaKey.value = ''
    error.value = '网络失败'
  } finally {
    loading.value = false
  }
}

function getKey() {
  return captchaKey.value
}

onMounted(() => {
  void refresh()
})

defineExpose({ refresh, getKey })
</script>
