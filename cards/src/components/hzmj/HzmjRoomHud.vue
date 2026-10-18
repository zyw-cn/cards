<script setup>
/**
 * 对齐 Cocos GameRoomInfo2DUI / game RoomInfoHud：房间号、局数、规则、设置、聊天、电量时间。
 * 退出/解散走设置菜单，不使用斗地主顶栏。
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { cocosStyle } from '@/logic/hzmj/roomChrome'
import { hzmjAsset } from '@/logic/hzmj/hzmjAssets'

const props = defineProps({
  roomId: { type: [String, Number], default: '------' },
  round: { type: [String, Number], default: '0/0' },
})

const emit = defineEmits(['config', 'help', 'chat'])

const INFO2D = 'hzmj/ui/GameRoomInfo2DUI'
const INFO = 'hzmj/ui/GameRoomInfoUI'
const HALL = 'hzmj/ui/GameHallUI'

const roomImg = hzmjAsset(`${INFO2D}/room_img.png`)
const roundImg = hzmjAsset(`${INFO2D}/round_img.png`)
const settingImg = hzmjAsset(`${HALL}/setting.png`)
const ruleImg = hzmjAsset(`${HALL}/rule.png`)
const chatImg = hzmjAsset(`${INFO}/communicate_btn.png`)
const wifiImg = hzmjAsset(`${INFO2D}/wifi_light_3.png`)
const batFrame = hzmjAsset(`${INFO2D}/battery_frame.png`)
const batGrid = hzmjAsset(`${INFO2D}/battery_grid.png`)

const clock = ref('')
let timer = 0

function pad(n) {
  return n < 10 ? `0${n}` : String(n)
}
function tick() {
  const d = new Date()
  clock.value = `${pad(d.getHours())}:${pad(d.getMinutes())}`
}

onMounted(() => {
  tick()
  timer = window.setInterval(tick, 15_000)
})
onUnmounted(() => clearInterval(timer))

const roominfoStyle = computed(() =>
  cocosStyle({ x: 631.04, y: 720, w: 224, h: 141, ax: 0.5, ay: 1 }),
)
const roomImgStyle = computed(() =>
  cocosStyle({ x: 7.3262, y: 119.4944, w: 58, h: 30, ax: 0.5, ay: 0.5 }, 141),
)
const roomIdStyle = computed(() =>
  cocosStyle({ x: 36.6985, y: 112.3929, w: 110, h: 32, ax: 0, ay: 0.5 }, 141),
)
const roundImgStyle = computed(() =>
  cocosStyle({ x: 168.2464, y: 119.0886, w: 58, h: 30, ax: 0.5, ay: 0.5 }, 141),
)
const roundStyle = computed(() =>
  cocosStyle({ x: 200.8347, y: 112.5347, w: 56, h: 32, ax: 0, ay: 0.5 }, 141),
)

const functionStyle = computed(() =>
  cocosStyle({ x: 1280, y: 720, w: 200, h: 100, ax: 1, ay: 1 }),
)
const configStyle = computed(() =>
  cocosStyle({ x: 145.42, y: 47.22, w: 96, h: 92, ax: 0.5, ay: 0.5 }, 100),
)
const helpStyle = computed(() =>
  cocosStyle({ x: 85, y: 46.5, w: 96, h: 92, ax: 0.5, ay: 0.5 }, 100),
)

const communicateStyle = computed(() =>
  cocosStyle({ x: 1280, y: 0, w: 120, h: 320, ax: 1, ay: 0 }),
)
const chatStyle = computed(() =>
  cocosStyle({ x: 44.1942, y: 306.0735, w: 96, h: 96, ax: 0.5, ay: 0.5 }, 320),
)

const deviceStyle = computed(() =>
  cocosStyle({ x: 0, y: 720, w: 240, h: 60, ax: 0, ay: 1 }),
)
const timeStyle = computed(() =>
  cocosStyle({ x: 131.904, y: 33.426, w: 66, h: 40, ax: 0.5, ay: 0.5 }, 60),
)
const wifiStyle = computed(() =>
  cocosStyle({ x: 177.8342, y: 34.9964, w: 30, h: 30, ax: 0.5, ay: 0.5 }, 60),
)
const batteryStyle = computed(() =>
  cocosStyle({ x: 215, y: 35, w: 40, h: 22, ax: 0.5, ay: 0.5 }, 60),
)
</script>

<template>
  <div class="pointer-events-none absolute inset-0 z-30">
    <div class="absolute" :style="roominfoStyle">
      <img class="absolute object-contain" :src="roomImg" alt="" :style="roomImgStyle" draggable="false" />
      <div
        class="absolute whitespace-nowrap text-[22px] font-bold leading-8 tracking-wide text-[#fff8e1]"
        :style="{ ...roomIdStyle, textShadow: '0 1px 0 #3a2a10' }"
      >
        {{ roomId }}
      </div>
      <img class="absolute object-contain" :src="roundImg" alt="" :style="roundImgStyle" draggable="false" />
      <div
        class="absolute whitespace-nowrap text-[22px] font-bold leading-8 tracking-wide text-[#fff8e1]"
        :style="{ ...roundStyle, textShadow: '0 1px 0 #3a2a10' }"
      >
        {{ round }}
      </div>
    </div>

    <div class="absolute" :style="functionStyle">
      <button
        type="button"
        class="absolute border-0 bg-transparent p-0"
        style="pointer-events: auto"
        :style="helpStyle"
        @click="emit('help')"
      >
        <img :src="ruleImg" class="h-full w-full object-contain" alt="规则" draggable="false" />
      </button>
      <button
        type="button"
        class="absolute border-0 bg-transparent p-0"
        style="pointer-events: auto"
        :style="configStyle"
        @click="emit('config')"
      >
        <img :src="settingImg" class="h-full w-full object-contain" alt="设置" draggable="false" />
      </button>
    </div>

    <div class="absolute" :style="communicateStyle">
      <button
        type="button"
        class="absolute border-0 bg-transparent p-0"
        style="pointer-events: auto"
        :style="chatStyle"
        @click="emit('chat')"
      >
        <img :src="chatImg" class="h-full w-full object-contain" alt="聊天" draggable="false" />
      </button>
    </div>

    <div class="absolute" :style="deviceStyle">
      <div
        class="absolute text-center text-[23px] leading-10 text-[rgb(63,143,128)] opacity-80"
        :style="timeStyle"
      >
        {{ clock }}
      </div>
      <img class="absolute object-contain" :src="wifiImg" alt="" :style="wifiStyle" draggable="false" />
      <div class="absolute" :style="batteryStyle">
        <img :src="batFrame" class="absolute left-0 top-0 h-full w-auto" alt="" draggable="false" />
        <img
          :src="batGrid"
          class="absolute left-1 top-1 h-[calc(100%-8px)] w-auto"
          alt=""
          draggable="false"
        />
      </div>
    </div>
  </div>
</template>
