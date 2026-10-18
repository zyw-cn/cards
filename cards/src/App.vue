<script setup>
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref } from 'vue'
import NetworkReconnectOverlay from './components/NetworkReconnectOverlay.vue'
import PlayerDetailModal from './components/PlayerDetailModal.vue'
import LoginView from './views/LoginView.vue'
import HallView from './views/HallView.vue'
import CreateRoomView from './views/CreateRoomView.vue'
import { GameType } from './config/gameTypes'
import { gameState, tryAutoLogin } from './services/gameState'
import { wsService } from './services/gameSocket'
import { tryEnterPendingRoom } from './services/roomResume'
import { assetUrl } from './services/assetUrl'
import { installStageIme, typingInStage } from './services/stageIme'
import { art } from './utils/art'

/** 牌桌含 Pixi，按屏异步加载，减轻首包 */
const LandlordRoomView = defineAsyncComponent(() => import('./views/LandlordRoomView.vue'))
const NiuRoomView = defineAsyncComponent(() => import('./views/NiuRoomView.vue'))
const FlowerRoomView = defineAsyncComponent(() => import('./views/FlowerRoomView.vue'))
const HzmjRoomView = defineAsyncComponent(() => import('./views/HzmjRoomView.vue'))

const scale = ref(1)
/** 首屏挂载时 #modal-root 还不在 document 里，Teleport 会找不到目标 */
const stageReady = ref(false)
const STAGE_W = 1280
const STAGE_H = 720
/** 宽屏手机把舞台加宽到铺满，避免 16:9 居中后两侧只剩背景 */
const stageW = ref(STAGE_W)
const insets = ref({ top: 0, right: 0, bottom: 0, left: 0 })
/** 软键盘弹出前的可视区域。键盘期间用它算 scale，避免整页被压小。 */
let baseW = 0
let baseH = 0
const holdKeyboard = ref(false)
/** 布局视口被键盘缩短时，舞台改贴顶，避免居中把画面裁掉一截 */
const pinTop = ref(false)
let uninstallIme = () => {}

function pxVar(name) {
  const raw = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  const n = parseFloat(raw)
  return Number.isFinite(n) ? n : 0
}

/**
 * 手机端：布局盒必须是「缩放后」的宽高，否则 1280×720 的未缩放盒被 overflow 裁掉一半。
 * 内层 transform-origin: top left + scale，贴满外层。
 * 宽屏（19.5:9 / 20:9）只用 contain，左右余量由全屏背景铺满，不再露黑边。
 * 输入框聚焦时软键盘会把 visualViewport 压矮；若跟着缩小，登录页会突然变小且无法打字。
 */
function updateScale() {
  const vv = window.visualViewport
  const w = vv?.width ?? window.innerWidth
  const h = vv?.height ?? window.innerHeight
  const typing = typingInStage()
  const widthJump = baseW > 0 && Math.abs(w - baseW) > 48
  if (baseW === 0 || widthJump) {
    baseW = w
    baseH = h
    holdKeyboard.value = false
  } else if (typing && h < baseH - 72) {
    holdKeyboard.value = true
  } else if (!typing && holdKeyboard.value && h < baseH - 40) {
    /* 键盘还在收，先保持原尺寸，避免闪一下变小 */
  } else {
    holdKeyboard.value = false
    baseW = w
    baseH = h
  }
  const useW = holdKeyboard.value ? baseW : w
  const useH = holdKeyboard.value ? baseH : h
  pinTop.value = holdKeyboard.value && window.innerHeight < baseH - 40
  const top = pxVar('--sat')
  const bottom = pxVar('--sab')
  /** 横屏牌桌左右不缩安全区，否则圆桌会偏到一侧、另一侧露出院子 */
  insets.value = { top, right: 0, bottom, left: 0 }
  const playW = Math.max(1, useW)
  const playH = Math.max(1, useH - top - bottom)
  const scaleByH = playH / STAGE_H
  const scaleByW = playW / STAGE_W
  if (scaleByW >= scaleByH) {
    scale.value = scaleByH
    stageW.value = Math.max(STAGE_W, Math.round(playW / scaleByH))
  } else {
    scale.value = scaleByW
    stageW.value = STAGE_W
  }
}

updateScale()

function pinViewportScroll() {
  if (!holdKeyboard.value) return
  if (window.scrollX || window.scrollY) window.scrollTo(0, 0)
}

onMounted(() => {
  stageReady.value = true
  updateScale()
  uninstallIme = installStageIme(() => scale.value)
  window.addEventListener('resize', updateScale)
  window.visualViewport?.addEventListener('resize', updateScale)
  window.visualViewport?.addEventListener('scroll', pinViewportScroll)
  document.addEventListener('focusin', updateScale)
  document.addEventListener('focusout', updateScale)
  void tryAutoLogin().then(async (ok) => {
    if (!ok) return
    try {
      await wsService.connect()
      await tryEnterPendingRoom()
    } catch (err) {
      console.warn('[app] bootstrap ws', err)
    }
  })
})

onUnmounted(() => {
  uninstallIme()
  window.removeEventListener('resize', updateScale)
  window.visualViewport?.removeEventListener('resize', updateScale)
  window.visualViewport?.removeEventListener('scroll', pinViewportScroll)
  document.removeEventListener('focusin', updateScale)
  document.removeEventListener('focusout', updateScale)
})

const shellStyle = computed(() => ({
  width: `${stageW.value * scale.value}px`,
  height: `${STAGE_H * scale.value}px`,
}))

const stageStyle = computed(() => ({
  width: `${stageW.value}px`,
  height: `${STAGE_H}px`,
  transform: `scale(${scale.value})`,
  transformOrigin: 'top left',
}))

const safeStyle = computed(() => ({
  top: `${insets.value.top}px`,
  right: `${insets.value.right}px`,
  bottom: `${insets.value.bottom}px`,
  left: `${insets.value.left}px`,
}))

const gt = computed(() => Number(gameState.room.gameType) || 0)
const isNiu = computed(() => gt.value === GameType.NIU)
const isFlower = computed(() => gt.value === GameType.ZJH)
const isHzmj = computed(() => gt.value === GameType.HZMJ)

/** 全屏一层背景，宽高都盖住。放在 transform 外，避免安卓 WebView 把 CSS background 裁出黑边。 */
const bleedBg = computed(() => {
  const screen = gameState.currentScreen
  if (screen === 'LOGIN') return art('bg', 'login_bg.jpg')
  if (screen === 'LOBBY' || screen === 'CREATE_ROOM') return art('bg', 'hall_bg.jpg')
  if (screen !== 'GAME_TABLE') return ''
  if (isNiu.value) return assetUrl('images/niu/table.jpg')
  if (isFlower.value) return assetUrl('images/flower/table-zhajinhua.jpg')
  if (isHzmj.value) return art('bg', 'bg_stretch_fill.jpg')
  return art('bg', 'dizhu_scene.jpg')
})
</script>

<template>
  <div class="fixed inset-0 overflow-hidden bg-[#0b0908]">
    <img
      v-if="bleedBg"
      :src="bleedBg"
      class="pointer-events-none absolute inset-0 h-full w-full object-cover"
      alt=""
      draggable="false"
    />
    <div
      v-if="gameState.currentScreen === 'LOGIN'"
      class="pointer-events-none absolute inset-0 bg-gradient-to-b from-black/25 via-transparent to-black/40"
    />
    <div
      v-else-if="gameState.currentScreen === 'LOBBY'"
      class="pointer-events-none absolute inset-0 bg-black/20"
    />
    <div
      v-else-if="gameState.currentScreen === 'CREATE_ROOM'"
      class="pointer-events-none absolute inset-0 bg-gradient-to-b from-black/45 via-black/30 to-black/50"
    />

    <div
      class="absolute flex justify-center overflow-hidden"
      :class="pinTop ? 'items-start' : 'items-center'"
      :style="safeStyle"
    >
      <div class="relative shrink-0 overflow-hidden" :style="shellStyle">
        <div id="game-stage" class="relative overflow-hidden" :style="stageStyle">
          <!-- 必须在视图之前：ModalShell Teleport 挂载时目标已存在 -->
          <div id="modal-root" class="pointer-events-none absolute inset-0 z-[100]" />

          <LoginView v-if="gameState.currentScreen === 'LOGIN'" />
          <HallView v-else-if="gameState.currentScreen === 'LOBBY'" />
          <CreateRoomView v-else-if="gameState.currentScreen === 'CREATE_ROOM'" />
          <NiuRoomView v-else-if="gameState.currentScreen === 'GAME_TABLE' && isNiu" />
          <FlowerRoomView v-else-if="gameState.currentScreen === 'GAME_TABLE' && isFlower" />
          <HzmjRoomView v-else-if="gameState.currentScreen === 'GAME_TABLE' && isHzmj" />
          <LandlordRoomView v-else-if="gameState.currentScreen === 'GAME_TABLE'" />

          <NetworkReconnectOverlay />
          <PlayerDetailModal v-if="stageReady" />
        </div>
      </div>
    </div>
  </div>
</template>
