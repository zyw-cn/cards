<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { art } from '../utils/art'
import { gameState, resolveToken } from '../services/gameState'
import { wsService } from '../services/gameSocket'
import { syncBrowserOnline, wsUi } from '../services/wsUi'

const visible = ref(false)
const retrying = ref(false)
let showTimer = null

/** 仅牌桌/结算挡操作。大厅、创房若也遮罩，APK 上 WS 未连上时会出现「创建房间点了没反应」。 */
const TABLE_SCREENS = new Set(['GAME_TABLE', 'SETTLE'])

const loggedIn = computed(() => Boolean(resolveToken()))
const screen = computed(() => String(gameState.currentScreen || ''))

const isReconnecting = computed(
  () =>
    wsUi.connecting ||
    wsUi.roomResyncing ||
    (!wsUi.ready && wsUi.willAutoReconnect && wsUi.browserOnline),
)

const statusTitle = computed(() => {
  if (!wsUi.browserOnline) return '网络不可用，请检查网络…'
  if (wsUi.roomResyncing) return '正在同步房间状态…'
  return '网络连接中，请稍候…'
})

const attemptText = computed(() => {
  const n = wsUi.reconnectAttempts
  return n > 0 ? `第 ${n} 次重连` : ''
})

const offlineTitle = computed(() =>
  wsUi.browserOnline ? '网络连接已断开' : '当前网络不可用',
)

const offlineHint = computed(() =>
  wsUi.browserOnline ? '请检查网络后重试' : '请打开网络后再重新连接',
)

const shouldShow = computed(() => {
  if (!loggedIn.value || !TABLE_SCREENS.has(screen.value)) return false
  if (!wsUi.browserOnline) return true
  if (!gameState.wsUrl) return false
  if (wsUi.roomResyncing) return true
  if (wsUi.ready) return false
  // 仅真正在连 / 断过线后才挡，避免首进未就绪时整屏遮罩挡住座位
  return wsUi.connecting || wsUi.reconnectAttempts > 0
})

watch(
  shouldShow,
  (show) => {
    if (showTimer) {
      clearTimeout(showTimer)
      showTimer = null
    }
    if (!show) {
      visible.value = false
      return
    }
    showTimer = setTimeout(() => {
      visible.value = true
      showTimer = null
    }, 180)
  },
  { immediate: true },
)

async function onRetry() {
  if (retrying.value) return
  retrying.value = true
  try {
    syncBrowserOnline()
    await wsService.refreshConnection()
  } catch (err) {
    console.warn('[net-overlay] retry', err)
  } finally {
    retrying.value = false
  }
}

function onBrowserOnline() {
  syncBrowserOnline()
  if (loggedIn.value && gameState.wsUrl && !wsService.ready) {
    void wsService.refreshConnection().catch(() => undefined)
  }
}

function onBrowserOffline() {
  syncBrowserOnline()
}

onMounted(() => {
  syncBrowserOnline()
  window.addEventListener('online', onBrowserOnline)
  window.addEventListener('offline', onBrowserOffline)
})

onUnmounted(() => {
  if (showTimer) clearTimeout(showTimer)
  window.removeEventListener('online', onBrowserOnline)
  window.removeEventListener('offline', onBrowserOffline)
})
</script>

<template>
  <Transition name="net-overlay-fade">
    <div
      v-if="visible"
      class="pointer-events-auto absolute inset-0 z-[9999] flex items-center justify-center bg-black/55"
      role="alertdialog"
      aria-live="assertive"
    >
      <div
        class="relative flex min-w-[360px] max-w-[70%] flex-col items-center rounded-[22px] border-[5px] border-[#222] bg-[#f7f1dc] px-10 pb-10 pt-12 text-center"
      >
        <div
          class="pointer-events-none absolute inset-x-0 top-0 h-10 rounded-t-[17px] bg-gradient-to-b from-white/35 to-transparent"
        />

        <template v-if="isReconnecting">
          <div class="net-spinner relative z-10 mb-5" aria-hidden="true" />
          <p
            class="relative z-10 m-0 text-[26px] font-extrabold tracking-wide text-[#5a3a18]"
            style="text-shadow: 0 1px 0 rgba(255, 255, 255, 0.55)"
          >
            {{ statusTitle }}
          </p>
          <p v-if="attemptText" class="relative z-10 mt-3 m-0 text-[16px] font-semibold text-[#5a3a18]/65">
            {{ attemptText }}
          </p>
        </template>

        <template v-else>
          <p
            class="relative z-10 m-0 text-[26px] font-extrabold tracking-wide text-[#5a3a18]"
            style="text-shadow: 0 1px 0 rgba(255, 255, 255, 0.55)"
          >
            {{ offlineTitle }}
          </p>
          <p class="relative z-10 mt-3 m-0 text-[16px] font-semibold text-[#5a3a18]/75">
            {{ offlineHint }}
          </p>
          <button
            type="button"
            class="relative z-10 mt-8 h-12 w-40 transition hover:brightness-110 active:scale-95 disabled:opacity-65"
            :disabled="retrying"
            @click="onRetry"
          >
            <img
              :src="art('guest_login', 'orange_btn_sm.png')"
              class="h-full w-full object-contain"
              :alt="retrying ? '连接中' : '重新连接'"
            />
            <span
              class="pointer-events-none absolute inset-0 flex items-center justify-center text-[18px] font-extrabold text-[#5a2a00]"
              style="text-shadow: 0 1px 0 rgba(255, 255, 255, 0.35)"
            >
              {{ retrying ? '连接中…' : '重新连接' }}
            </span>
          </button>
        </template>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.net-spinner {
  width: 3.25rem;
  height: 3.25rem;
  border-radius: 9999px;
  border: 5px solid rgba(90, 58, 24, 0.18);
  border-top-color: #c48a2a;
  animation: net-spin 0.85s linear infinite;
}

.net-overlay-fade-enter-active,
.net-overlay-fade-leave-active {
  transition: opacity 0.2s ease;
}

.net-overlay-fade-enter-from,
.net-overlay-fade-leave-to {
  opacity: 0;
}

@keyframes net-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
