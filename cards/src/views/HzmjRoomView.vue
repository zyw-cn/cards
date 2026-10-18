<script setup>
/**
 * 桌面/罗盘：majiang.fire；顶栏按钮：对齐斗地主 LandlordRoomView。
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import AppDialog from '@/components/AppDialog.vue'
import ModalShell from '@/components/ModalShell.vue'
import RoomNumberDigits from '@/components/RoomNumberDigits.vue'
import HzmjCanWin from '@/components/hzmj/HzmjCanWin.vue'
import HzmjDice from '@/components/hzmj/HzmjDice.vue'
import HzmjKingPanel from '@/components/hzmj/HzmjKingPanel.vue'
import HzmjOpBar from '@/components/hzmj/HzmjOpBar.vue'
import {
  hzmjAvatarDefault,
  hzmjAvatarFrame,
  hzmjBankerImg,
  hzmjCompassBg,
  hzmjCompassDirs,
  hzmjDragonCenter,
  hzmjGameTitle,
  hzmjNeedDiscard,
  hzmjReadyBtn,
  hzmjRoomOwner,
  hzmjTableCloth,
  hzmjTableCorner,
} from '@/logic/hzmj/hzmjAssets'
import {
  COMPASS,
  COMPASS_DIRS,
  DRAGON_BOX,
  GAME_TITLE_BOX,
  PREPARE_BOX,
  ROOM_TABLE_INFO,
  SEAT_BOX,
  TABLE_CORNERS,
} from '@/logic/hzmj/roomChrome'
import { DESIGN_H, HAND_SELF, toUiSeat } from '@/logic/hzmj/layout'
import { PHASE, hzmjRules } from '@/logic/hzmj/rules/room'
import { mountHzmjPixi } from '@/pixi/hzmj/hzmjTable'
import { art } from '@/utils/art'
import { audioManager } from '@/services/audioManager'
import { seatAvatarSrc } from '@/services/seatAvatar'
import { gameState, roomHasStarted, roomOwnerName } from '@/services/gameState'
import {
  bounceToHallKeepResume,
  returnToHallKeepingSeat,
} from '@/services/roomResume'
import { formatGold, session } from '@/services/session'
import { showPlayerDetail } from '@/services/playerDetail'
import { hzmjSession } from '@/services/hzmjSession'
import { flashHzmjTip, hzmjState, needMyHzmjDismissVote } from '@/services/hzmjState'
import ChatQuickPanel from '@/components/ChatQuickPanel.vue'
import HzmjSettleView from '@/views/HzmjSettleView.vue'
import { tipImgs } from '@/logic/shared/tipImgs'
import {
  DISMISS_OWNER_ONLY,
  DISMISS_RULE_NOTE,
  DISMISS_RULE_TIP,
  dismissTipSrc as sharedDismissTipSrc,
  showDismissEntry,
} from '@/logic/shared/dismiss'
import DismissConfirmDialog from '@/components/DismissConfirmDialog.vue'
import DismissVoteBar from '@/components/DismissVoteBar.vue'

const tipTuoguan = tipImgs.tuoguan
const dizhu = (...parts) => art('ui', 'dizhu', ...parts)

/** 取消托管叠在手牌垂直中央（对齐斗地主） */
const HAND_CANCEL_CENTER = DESIGN_H - HAND_SELF.y - HAND_SELF.tileH / 2

const match = hzmjState
const pixiHost = ref(null)
const backOpen = ref(false)
const dismissOpen = ref(false)
const showSettle = ref(false)
const showChat = ref(false)
const showRule = ref(false)
const settingsOpen = ref(false)
const musicOn = ref(!audioManager.isBgmMuted())
const soundOn = ref(!audioManager.isMuted())
let alive = false
let pixiDestroy = null
let table = null

const roomId = computed(() => gameState.room.roomId || '------')
const nickname = computed(() => session.user.nickname || '玩家')
const roundText = computed(
  () =>
    `${match.round || gameState.room.js || 0}/${match.totalRound || gameState.room.zjs || 0}`,
)

const uiPhase = computed(() => {
  const p = String(match.phase).toLowerCase()
  if (p === PHASE.WAIT_READY || p === 'wait' || p === 'waitready') return 'wait'
  if (p === PHASE.GRAB || p === 'grab') return 'grab'
  if (p === PHASE.SETTLE || p === 'settle' || p === 'end') return 'settle'
  if (p === PHASE.PLAY || p === 'play' || p === 'playing') return 'play'
  return 'wait'
})

const showGrabBar = computed(
  () => uiPhase.value === 'grab' && Number(match.myGrabMultiple) < 0,
)

function onGrab(n) {
  void hzmjSession.sendGrab(n)
}

/** 抢庄、出牌算牌局中；等待和结算不算 */
const inRound = computed(() => uiPhase.value === 'grab' || uiPhase.value === 'play' || match.dealing)

const selfIsOwner = computed(() => {
  if (gameState.room.isMaster) return true
  const uid = Number(gameState.user.uid || 0)
  const byUid = match.seats.find((s) => s.occupied && uid > 0 && Number(s.uid) === uid)
  if (byUid?.isMaster) return true
  const mine = match.seats[Number(match.myIndex)]
  return !!(mine?.occupied && mine.isMaster)
})

const dismissIdle = computed(() => !roomHasStarted() && !inRound.value)
/** 未开局仅房主直接解散；已开局任意人可申请投票解散 */
const showDismissBtn = computed(() =>
  showDismissEntry({
    dismissOpen: match.dismissOpen,
    idle: dismissIdle.value,
    isOwner: selfIsOwner.value,
  }),
)

const showDismissVoteBtns = computed(() => needMyHzmjDismissVote())

const ruleView = computed(() => ({
  ownerName: roomOwnerName() || match.seats.find((s) => s.occupied && s.isMaster)?.nickname || '—',
  roomId: roomId.value,
  round: roundText.value,
  playerNum: match.playerNum || hzmjRules.seats,
  leftTiles: match.leftTiles,
  df: Number(gameState.room.df ?? session.room?.df ?? 1) || 1,
  tip: hzmjRules.idleTip || DISMISS_RULE_TIP,
}))

/** cards 罗盘：116 方盘，中心对齐桌心。必须有实体宽高，0 尺寸会被桌面 overflow 裁掉 */
const COMPASS_DIAL = 168
const compassWrapStyle = {
  left: `${COMPASS.cx}px`,
  top: `${COMPASS.cy}px`,
  width: `${COMPASS_DIAL}px`,
  height: `${COMPASS_DIAL}px`,
  transform: 'translate(-50%, -50%)',
}
const compassBgStyle = {
  left: '0',
  top: '0',
  width: `${COMPASS_DIAL}px`,
  height: `${COMPASS_DIAL}px`,
}
const DIAL_SCALE = COMPASS_DIAL / 116
const compassDirStyles = COMPASS_DIRS.map((d, i) => {
  const w = d.w * DIAL_SCALE
  const h = d.h * DIAL_SCALE
  return {
    left: `${COMPASS_DIAL / 2 + d.x * DIAL_SCALE - w / 2}px`,
    top: `${COMPASS_DIAL / 2 + d.y * DIAL_SCALE - h / 2}px`,
    width: `${w}px`,
    height: `${h}px`,
    // 南、北原图尖朝外，和东（朝自己）相反，翻过来尖朝桌心
    transform: i === 1 || i === 3 ? 'scaleX(-1)' : undefined,
  }
})

const activeWind = computed(() => {
  if (match.settleResult || match.showResult || uiPhase.value === 'settle') return -1
  if (uiPhase.value !== 'play' && !match.dealing) return -1
  const raw =
    match.turnIndex != null && match.turnIndex !== ''
      ? Number(match.turnIndex)
      : Number(match.dealerIndex)
  if (!Number.isFinite(raw) || raw < 0) return -1
  return toUiSeat(raw, match.myIndex, match.playerNum)
})

const showCompass = computed(() => !showSettle.value)
const compassCountdown = computed(() => {
  if (uiPhase.value !== 'play' || match.countdown <= 0) return ''
  if (match.settleResult || match.showResult) return ''
  return String(match.countdown)
})

const isMyTurn = computed(
  () => uiPhase.value === 'play' && Number(match.turnIndex) === Number(match.myIndex),
)

const selectedHandTile = computed(() => {
  const i = match.selectedTileIdx
  if (i == null || i < 0) return 0
  return match.hand[i] || 0
})

const showDiscardTip = computed(
  () =>
    isMyTurn.value &&
    !match.trusted &&
    match.countdown > 0 &&
    match.countdown < 5 &&
    !match.notifyOps.length,
)

/** majiang.fire readybtn */
const showStartBtn = computed(
  () => uiPhase.value === 'wait' && match.canStart && !match.selfReady,
)

/** 碰杠胡操作条是否展开：用于抬高听牌/出牌提示，避免叠压 */
const hasNotifyOps = computed(
  () => uiPhase.value === 'play' && !match.trusted && (match.notifyOps?.length || 0) > 0,
)

function seatAvatar(key, uid = 0) {
  return seatAvatarSrc(key, uid)
}

/** 头像旁 tip 落点由 dismissTipClass 控制；图源全玩法统一 */
function dismissTipSrc(seat) {
  return sharedDismissTipSrc(match, seat)
}

function dismissTipClass(ui) {
  const onLeft = Number(ui) === 0 || Number(ui) === 3
  if (onLeft) return 'left-[calc(50%+48px)] top-[48px] -translate-y-1/2'
  return 'left-[calc(50%-48px)] top-[48px] -translate-x-full -translate-y-1/2'
}

/** 准备 tip 贴朝桌心一侧，避开头像正下方的昵称/金币 */
function readyTipClass(ui) {
  switch (Number(ui)) {
    case 0:
      return 'left-1/2 top-[2px] -translate-x-1/2 -translate-y-full'
    case 1:
      return 'left-[calc(50%-52px)] top-[48px] -translate-x-full -translate-y-1/2'
    case 2:
      return 'left-1/2 top-[4px] -translate-x-1/2 -translate-y-full'
    case 3:
      return 'left-[calc(50%+52px)] top-[48px] -translate-y-1/2'
    default:
      return 'left-1/2 top-[148px] -translate-x-1/2'
  }
}

/** 叫分标签：贴头像侧边，不压昵称/金币 */
function grabTipClass(ui) {
  switch (Number(ui)) {
    case 0:
      return 'left-[calc(50%+52px)] top-[48px] -translate-y-1/2'
    case 1:
      return 'left-[calc(50%-52px)] top-[20px] -translate-x-full -translate-y-1/2'
    case 2:
      return 'left-1/2 top-[100px] -translate-x-1/2'
    case 3:
      return 'left-[calc(50%+52px)] top-[20px] -translate-y-1/2'
    default:
      return 'left-1/2 top-[100px] -translate-x-1/2'
  }
}

const seatChips = computed(() => {
  const n = match.playerNum
  const chips = []
  for (let seat = 0; seat < n; seat++) {
    const ui = toUiSeat(seat, match.myIndex, n)
    const s = match.seats[seat]
    const occupied = !!s?.occupied
    if (!occupied) continue
    const raw =
      s.avatar || (seat === match.myIndex ? session.user.avatar : '')
    chips.push({
      ui,
      seat,
      occupied,
      name: s.nickname || (seat === match.myIndex ? nickname.value : ''),
      uid: seat === match.myIndex ? Number(session.user.uid) || 0 : Number(s.uid) || 0,
      avatar: seatAvatar(
        raw,
        seat === match.myIndex ? Number(session.user.uid) || 0 : Number(s.uid) || seat + 1,
      ),
      gold:
        seat === match.myIndex
          ? Number(s.gold) || Number(session.user.gold) || 0
          : Number(s.gold) || 0,
      goldText: formatGold(
        seat === match.myIndex
          ? Number(s.gold) || Number(session.user.gold) || 0
          : Number(s.gold) || 0,
      ),

      ready: !!s.isReady || (seat === match.myIndex && match.selfReady),
      dealer:
        (uiPhase.value === 'play' || uiPhase.value === 'settle') &&
        Number(match.dealerIndex) >= 0 &&
        seat === Number(match.dealerIndex),
      rollOn: uiPhase.value === 'grab' && seat === Number(match.rollHighlightSeat),
      master: !!s.isMaster,
      active: Number(match.turnIndex) === seat && uiPhase.value === 'play',
      grabLabel: grabLabelFor(seat),
      dismissTip: dismissTipSrc(seat),
      trusted: !!s.isTrusted || (seat === match.myIndex && match.trusted),
      style: SEAT_BOX[ui] || SEAT_BOX[0],
    })
  }
  return chips
})

function grabLabelFor(seat) {
  if (uiPhase.value !== 'grab') return ''
  const mine = seat === Number(match.myIndex) ? Number(match.myGrabMultiple) : -1
  const raw = match.grabsBySeat?.[seat]
  const n = mine >= 0 ? mine : raw == null ? -1 : Number(raw)
  if (!Number.isFinite(n) || n < 0) return ''
  return n === 0 ? '不抢' : `×${n}`
}

function onSendChat(text) {
  void hzmjSession.sendChat(text).catch((err) => {
    flashHzmjTip(err instanceof Error ? err.message : '发送失败')
  })
}

async function onStartGame() {
  try {
    await hzmjSession.sendReady()
  } catch (err) {
    flashHzmjTip(err instanceof Error ? err.message : '开始失败')
  }
}

const backTitle = '返回大厅'
const backTip = computed(
  () => '返回大厅后游戏将自动托管，座位会保留。中途解散请点解散并全员投票同意。确定返回吗？',
)

function onBack() {
  backOpen.value = true
}

async function confirmBack() {
  backOpen.value = false
  if (inRound.value) {
    await hzmjSession.enableTrust()
  }
  returnToHallKeepingSeat()
}

function onDismiss() {
  if (match.dismissOpen) return
  if (dismissIdle.value && !selfIsOwner.value) {
    flashHzmjTip(DISMISS_OWNER_ONLY)
    return
  }
  dismissOpen.value = true
}

function confirmDismiss() {
  dismissOpen.value = false
  void hzmjSession.requestDismiss().catch((err) => {
    flashHzmjTip(err instanceof Error ? err.message : '解散失败')
  })
}

function onVoteDismiss(vote) {
  void hzmjSession.voteDismiss(vote).catch((err) => {
    flashHzmjTip(err instanceof Error ? err.message : '投票失败')
  })
}

function openSettings() {
  musicOn.value = !audioManager.isBgmMuted()
  soundOn.value = !audioManager.isMuted()
  settingsOpen.value = true
}

function toggleMusic() {
  const muted = audioManager.toggleBgmMuted()
  musicOn.value = !muted
  if (!muted) audioManager.playPackBGM('hzmj-sfx')
}

function toggleSound() {
  const muted = audioManager.toggleMuted()
  soundOn.value = !muted
  if (!muted) audioManager.playEffect('click')
}

async function onTrust() {
  try {
    await hzmjSession.sendTrust()
  } catch (err) {
    flashHzmjTip(err instanceof Error ? err.message : '托管失败')
  }
}

async function onCancelTrust() {
  try {
    await hzmjSession.cancelTrust()
  } catch (err) {
    flashHzmjTip(err instanceof Error ? err.message : '取消托管失败')
  }
}

watch(
  () => match.settleResult,
  (result) => {
    if (!alive) return
    showSettle.value = !!result || !!match.showResult
  },
)

watch(
  () => [
    match.hand.length,
    match.phase,
    match.selectedTileIdx,
    match.discards,
    match.upTiles,
    match.countdown,
    match.dealerIndex,
    match.turnIndex,
  ],
  () => {
    table?.refreshAll()
  },
  { deep: true },
)

onMounted(async () => {
  alive = true
  // 先挂 Pixi，再 Join/resume：否则断线恢复的牌面在桌面创建前就发完，只会看到空桌
  if (!pixiHost.value) {
    bounceToHallKeepResume()
    return
  }
  try {
    const mounted = await mountHzmjPixi(pixiHost.value)
    if (!alive) {
      mounted.destroy()
      return
    }
    pixiDestroy = mounted.destroy
    table = mounted.table
  } catch (err) {
    flashHzmjTip(err instanceof Error ? err.message : '牌桌加载失败')
    bounceToHallKeepResume()
    return
  }

  try {
    await hzmjSession.enter()
  } catch (err) {
    flashHzmjTip(err instanceof Error ? err.message : '无法进入房间')
    // 进房失败：回大厅但保留重进，勿清房号
    bounceToHallKeepResume()
    return
  }
  if (!alive) return
  if (hzmjSession.queue && table) table.attachQueue(hzmjSession.queue)
  // 再刷一次：吃掉 enter 期间可能被跳过的 resume 状态
  table?.refreshAll()
  if (match.settleResult || match.showResult) {
    showSettle.value = true
  }
})

onUnmounted(() => {
  alive = false
  pixiDestroy?.()
  pixiDestroy = null
  table = null
  hzmjSession.queue?.setHandler(null)
  void hzmjSession.leave({ keepSeat: true })
})
</script>

<template>
  <div class="relative h-full w-full select-none overflow-hidden text-white">
    <!-- 桌布用 img：手机 transform 舞台下 CSS background 易被裁成半截 -->
    <img
      :src="hzmjTableCloth"
      class="pointer-events-none absolute inset-0 z-0 h-full w-full object-fill"
      alt=""
      draggable="false"
    />
    <!-- majiang.fire 桌角 + 龙纹 -->
    <img
      v-for="(c, i) in TABLE_CORNERS"
      :key="i"
      :src="hzmjTableCorner(c.key)"
      class="pointer-events-none absolute z-[1] object-fill opacity-90"
      :style="c.style"
      alt=""
      draggable="false"
    />
    <img
      :src="hzmjDragonCenter"
      class="pointer-events-none absolute z-[1] object-contain opacity-90"
      :style="DRAGON_BOX"
      alt=""
      draggable="false"
    />
    <img
      v-if="uiPhase === 'wait'"
      :src="hzmjGameTitle"
      class="pointer-events-none absolute z-[2] object-contain"
      :style="GAME_TITLE_BOX"
      alt=""
      draggable="false"
    />

    <!-- 罗盘：对齐 cards（curplayer_bg + 东南西北），当前行牌位高亮 -->
    <div
      v-if="showCompass"
      class="pointer-events-none absolute z-[42]"
      :style="compassWrapStyle"
    >
      <img
        :src="hzmjCompassBg"
        class="absolute object-fill"
        :style="compassBgStyle"
        alt=""
        draggable="false"
      />
      <img
        v-for="(src, wind) in hzmjCompassDirs"
        :key="wind"
        :src="src"
        class="absolute object-fill"
        :style="{ ...compassDirStyles[wind], opacity: wind === activeWind ? 1 : 0.22 }"
        alt=""
        draggable="false"
      />
      <span
        v-if="compassCountdown"
        class="absolute left-1/2 top-1/2 z-[4] -translate-x-1/2 -translate-y-1/2 text-[28px] font-bold text-white drop-shadow-[0_2px_2px_rgba(0,0,0,0.85)]"
      >
        {{ compassCountdown }}
      </span>
    </div>

    <div ref="pixiHost" class="absolute inset-0 z-[26]" />

    <!-- mjplayer：头像 + 昵称/金币（Label，无错误拉伸的 base_name 条） -->
    <div
      v-for="chip in seatChips"
      :key="chip.ui"
      class="pointer-events-none absolute z-[40]"
      :style="chip.style"
    >
      <!-- 头像居中于座位盒 -->
      <button
        type="button"
        class="pointer-events-auto absolute left-1/2 top-[48px] h-[88px] w-[96px] -translate-x-1/2 -translate-y-1/2"
        @click="showPlayerDetail({ uid: chip.uid, nickname: chip.name, gold: chip.gold, avatar: chip.avatar })"
      >
        <img
          class="absolute inset-0 h-full w-full object-contain"
          :src="hzmjAvatarFrame"
          alt=""
          draggable="false"
        />
        <img
          v-if="chip.occupied"
          :src="chip.avatar"
          alt=""
          class="absolute left-1/2 top-1/2 h-[68px] w-[68px] -translate-x-1/2 -translate-y-1/2 rounded object-cover"
          :class="
            chip.rollOn
              ? 'ring-4 ring-[#ffd45a] scale-105'
              : chip.active
                ? 'ring-2 ring-amber-300'
                : ''
          "
          draggable="false"
        />
        <img
          v-if="chip.dealer"
          class="absolute -bottom-1 right-0 z-[3] w-[30px]"
          :src="hzmjBankerImg"
          alt=""
          draggable="false"
        />
        <img
          v-if="chip.master"
          class="absolute -left-1 -top-1 z-[3] w-7 object-contain"
          :src="hzmjRoomOwner"
          alt="房主"
          draggable="false"
        />
        <img
          v-if="chip.trusted"
          :src="tipTuoguan"
          class="pointer-events-none absolute left-1/2 top-1/2 z-[4] h-8 w-auto max-w-[96px] -translate-x-1/2 -translate-y-1/2 object-contain drop-shadow"
          alt="托管"
          draggable="false"
        />
      </button>
      <!-- 个人信息：昵称 + 金豆（对齐斗地主 player_info 条） -->
      <div
        v-if="chip.occupied"
        class="absolute left-1/2 top-[100px] z-[4] flex w-[112px] -translate-x-1/2 flex-col items-center bg-[length:100%_100%] px-1.5 py-1"
        :style="{ backgroundImage: `url(${dizhu('player_info_bg.png')})` }"
      >
        <span class="max-w-full truncate text-center text-[12px] font-semibold leading-tight text-white">
          {{ chip.name }}
        </span>
        <div class="mt-0.5 flex max-w-full items-center justify-center gap-0.5">
          <img
            :src="art('hall', 'user_bar_coin.png')"
            class="h-4 w-4 shrink-0 object-contain"
            alt=""
            draggable="false"
          />
          <span class="truncate text-[11px] font-bold tabular-nums leading-tight text-[#ffe9a0]">
            {{ chip.goldText }}
          </span>
        </div>
      </div>
      <span
        v-if="chip.grabLabel"
        class="pointer-events-none absolute z-[5] rounded bg-[#c62828]/95 px-2 py-0.5 text-[14px] font-extrabold leading-none text-[#ffe566] ring-1 ring-[#ffe566]/55 drop-shadow"
        :class="grabTipClass(chip.ui)"
      >
        {{ chip.grabLabel }}
      </span>
      <img
        v-if="uiPhase === 'wait' && chip.ready"
        :src="tipImgs.ready"
        class="pointer-events-none absolute z-[3] h-[28px] w-auto max-w-[72px] object-contain drop-shadow"
        :class="readyTipClass(chip.ui)"
        alt="准备"
        draggable="false"
      />
      <img
        v-if="chip.dismissTip"
        :src="chip.dismissTip"
        class="pointer-events-none absolute z-[6] h-[32px] w-auto max-w-[140px] object-contain drop-shadow"
        :class="dismissTipClass(chip.ui)"
        alt=""
        draggable="false"
      />
    </div>

    <!-- 余牌 -->
    <div
      v-if="uiPhase === 'play'"
      class="pointer-events-none absolute z-[12] flex items-center justify-center gap-1 text-[14px]"
      :style="ROOM_TABLE_INFO"
    >
      <span class="text-[#c8f5e8]">剩余</span>
      <span class="font-bold tabular-nums text-[#00f76f]">{{ match.leftTiles }}</span>
    </div>

    <!-- 顶栏：对齐斗地主（返回 / 解散 / 房号 | 局数居中 | 规则 / 托管 / 设置） -->
    <header class="pointer-events-none absolute inset-x-0 top-0 z-40 flex h-[72px] items-start justify-between px-3 pt-2">
      <div class="pointer-events-auto flex items-center gap-2">
        <button type="button" class="h-12 w-12 transition active:scale-95" @click="onBack">
          <img :src="dizhu('icon_back.png')" class="h-full w-full object-contain" alt="返回" />
        </button>
        <button
          v-if="showDismissBtn"
          type="button"
          class="h-12 w-12 transition active:scale-95"
          @click="onDismiss"
        >
          <img :src="dizhu('icon_dismiss.png')" class="h-full w-full object-contain" alt="解散" />
        </button>
        <div
          class="flex h-[40px] min-w-[148px] items-center justify-center gap-1.5 bg-[length:100%_100%] px-3"
          :style="{ backgroundImage: `url(${dizhu('room_card_bg.png')})` }"
        >
          <span class="shrink-0 text-[13px] font-bold text-white/90 drop-shadow">房间</span>
          <RoomNumberDigits :value="roomId" variant="glass" digit-class="h-[22px]" />
        </div>
      </div>

      <div
        class="pointer-events-none absolute left-1/2 top-2 flex h-[40px] min-w-[96px] -translate-x-1/2 items-center justify-center gap-1 bg-[length:100%_100%] px-3"
        :style="{ backgroundImage: `url(${dizhu('room_card_bg.png')})` }"
      >
        <span class="shrink-0 text-[13px] font-bold text-white/90 drop-shadow">局</span>
        <span class="text-[14px] font-bold tabular-nums text-amber-100 drop-shadow">{{ roundText }}</span>
      </div>

      <div class="pointer-events-auto flex items-center gap-3 pr-1">
        <button type="button" class="h-12 w-12 transition active:scale-95" @click="showRule = true">
          <img :src="dizhu('icon_rule.png')" class="h-full w-full object-contain" alt="规则" />
        </button>
        <button
          v-if="uiPhase === 'play' && !match.trusted && !match.dismissOpen"
          type="button"
          class="h-12 w-12 transition active:scale-95"
          title="托管"
          @click="onTrust"
        >
          <img :src="dizhu('icon_trusteeship.png')" class="h-full w-full object-contain" alt="托管" />
        </button>
        <button type="button" class="h-12 w-12 transition active:scale-95" @click="openSettings">
          <img :src="dizhu('icon_settings.png')" class="h-full w-full object-contain" alt="设置" />
        </button>
      </div>
    </header>

    <HzmjKingPanel
      v-if="uiPhase === 'play' && match.kingTiles[0] != null"
      :tile="match.kingTiles[0]"
    />

    <img
      v-if="showDiscardTip"
      class="pointer-events-none absolute left-1/2 z-40 w-[200px] -translate-x-1/2"
      :class="hasNotifyOps ? 'bottom-[248px]' : 'bottom-[168px]'"
      :src="hzmjNeedDiscard"
      alt=""
      draggable="false"
    />

    <p
      v-if="match.tip"
      class="pointer-events-none absolute left-1/2 top-[76px] z-30 max-w-[420px] -translate-x-1/2 truncate rounded bg-black/55 px-3 py-1 text-sm text-amber-100"
    >
      {{ match.tip }}
    </p>

    <HzmjCanWin
      v-if="uiPhase === 'play'"
      :hand="match.hand"
      :selected="selectedHandTile"
      :kings="match.kingTiles"
      :my-turn="isMyTurn && !match.trusted"
      :ops-open="hasNotifyOps"
    />

    <HzmjDice v-if="false" :dice="match.diceList" />

    <!-- 抢庄：不抢 / ×1 / ×2 / ×3（对齐斗地主美术按钮） -->
    <div
      v-if="showGrabBar"
      class="pointer-events-auto absolute bottom-[200px] left-1/2 z-40 flex -translate-x-1/2 items-center gap-3"
    >
      <button
        type="button"
        class="relative h-[56px] w-[148px] transition active:scale-95"
        @click="onGrab(0)"
      >
        <img :src="dizhu('btn_green.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
        <span class="relative z-10 text-[22px] font-extrabold text-white drop-shadow-[0_2px_0_rgba(20,60,0,0.35)]">不抢</span>
      </button>
      <button
        v-for="n in [1, 2, 3]"
        :key="n"
        type="button"
        class="relative h-[56px] w-[120px] transition active:scale-95"
        @click="onGrab(n)"
      >
        <img :src="dizhu('btn_orange.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
        <span class="relative z-10 text-[22px] font-extrabold text-white drop-shadow-[0_2px_0_rgba(80,40,0,0.45)]">×{{ n }}</span>
      </button>
    </div>
    <p
      v-else-if="uiPhase === 'grab'"
      class="absolute bottom-[210px] left-1/2 z-40 -translate-x-1/2 rounded bg-black/55 px-3 py-1 text-[16px] text-amber-100"
    >
      {{ match.statusText || '等待其他玩家抢庄' }}
    </p>

    <DismissVoteBar
      :show="match.dismissOpen"
      :show-vote-btns="showDismissVoteBtns"
      :dismiss-from-seat="match.dismissFromSeat"
      :my-index="match.myIndex"
      :dismiss-left="match.dismissLeft"
      bar-class="absolute bottom-[200px] left-1/2 z-50 flex -translate-x-1/2 flex-col items-center gap-2"
      @vote="onVoteDismiss"
    />

    <!-- readybtn -->
    <button
      v-if="showStartBtn"
      type="button"
      class="absolute z-30 border-0 bg-transparent p-0 disabled:opacity-60"
      style="pointer-events: auto"
      :style="PREPARE_BOX"
      :disabled="match.starting"
      @click="onStartGame"
    >
      <img class="block h-full w-full object-contain" :src="hzmjReadyBtn" alt="准备" draggable="false" />
    </button>

    <HzmjOpBar
      v-if="uiPhase === 'play' && !match.trusted"
      :ops="match.notifyOps"
      @select="hzmjSession.sendShowOp"
    />

    <!-- 托管中：取消托管叠在手牌垂直中央（对齐斗地主） -->
    <button
      v-if="uiPhase === 'play' && match.trusted && !match.dismissOpen"
      type="button"
      class="pointer-events-auto absolute left-1/2 z-50 h-[56px] w-[200px] -translate-x-1/2 translate-y-1/2 transition active:scale-95"
      :style="{ bottom: `${HAND_CANCEL_CENTER}px` }"
      @click="onCancelTrust"
    >
      <img :src="dizhu('btn_green_long.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
      <span
        class="relative z-10 text-[22px] font-extrabold tracking-wide text-white"
        style="text-shadow: 0 2px 0 rgba(20, 60, 0, 0.35)"
      >
        取消托管
      </span>
    </button>

    <ModalShell :open="settingsOpen" width="560px" min-height="420px" @close="settingsOpen = false">
      <img :src="art('hall_modals', 'settings', 'title_setting.png')" class="mb-10 h-10 object-contain" alt="设置" />
      <div class="flex w-[380px] flex-col gap-8">
        <div class="flex items-center justify-between">
          <img :src="art('hall_modals', 'settings', 'music1.png')" class="h-10 object-contain" alt="音乐" />
          <button type="button" class="h-12 w-24 transition active:scale-95" @click="toggleMusic">
            <img
              :src="art('hall_modals', 'settings', musicOn ? 'on.png' : 'off.png')"
              class="h-full w-full object-contain"
              alt=""
            />
          </button>
        </div>
        <div class="flex items-center justify-between">
          <img :src="art('hall_modals', 'settings', 'music2.png')" class="h-10 object-contain" alt="音效" />
          <button type="button" class="h-12 w-24 transition active:scale-95" @click="toggleSound">
            <img
              :src="art('hall_modals', 'settings', soundOn ? 'on.png' : 'off.png')"
              class="h-full w-full object-contain"
              alt=""
            />
          </button>
        </div>
      </div>
    </ModalShell>

    <AppDialog :show="backOpen" :title="backTitle" closable @close="backOpen = false">
      <p class="text-center text-[20px] leading-8 text-[#5a3a18]/85">{{ backTip }}</p>
      <template #actions>
        <button
          type="button"
          class="flex-1 rounded-lg bg-[#c4a574] py-3 text-[20px] font-bold text-white"
          @click="backOpen = false"
        >
          取消
        </button>
        <button
          type="button"
          class="flex-1 rounded-lg bg-[#e07a2f] py-3 text-[20px] font-bold text-white"
          @click="confirmBack"
        >
          确定
        </button>
      </template>
    </AppDialog>

    <DismissConfirmDialog
      :show="dismissOpen"
      :idle="dismissIdle"
      :art-buttons="false"
      @close="dismissOpen = false"
      @confirm="confirmDismiss"
    />

    <AppDialog :show="showRule" title="房间规则" closable width="560px" @close="showRule = false">
      <div class="space-y-2 text-left text-[18px] leading-8 text-[#4a3018]">
        <p><span class="text-[#5a3a18]/55">房主：</span>{{ ruleView.ownerName }}</p>
        <p><span class="text-[#5a3a18]/55">人数：</span>固定 {{ ruleView.playerNum }} 人</p>
        <p><span class="text-[#5a3a18]/55">底分：</span>{{ ruleView.df }}</p>
        <p><span class="text-[#5a3a18]/55">定庄：</span>抢庄（不抢 / ×1 / ×2 / ×3）</p>
        <p><span class="text-[#5a3a18]/55">局数：</span>{{ ruleView.round }}</p>
        <p v-if="uiPhase === 'play'">
          <span class="text-[#5a3a18]/55">剩余牌：</span>{{ ruleView.leftTiles }}
        </p>
        <p><span class="text-[#5a3a18]/55">房间号：</span>{{ ruleView.roomId }}</p>
        <p class="pt-1 text-[15px] leading-relaxed text-[#8a5a28]/85">{{ ruleView.tip }}</p>
        <div class="mt-3 rounded-lg bg-white/45 px-3 py-2">
          <p class="text-[15px] font-semibold leading-7 text-[#5a3a18]">{{ DISMISS_RULE_NOTE.title }}</p>
          <p
            v-for="(line, idx) in DISMISS_RULE_NOTE.lines"
            :key="idx"
            class="text-[14px] leading-6 text-[#8a5a28]/85"
          >
            {{ line }}
          </p>
        </div>
      </div>
    </AppDialog>

    <ChatQuickPanel v-model:show="showChat" @select="onSendChat" />

    <div v-if="showSettle" class="absolute inset-0 z-[80]">
      <HzmjSettleView />
    </div>
  </div>
</template>
