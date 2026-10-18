<template>
  <div class="pointer-events-none relative h-full w-full select-none overflow-hidden text-white">
    <!-- 竖图桌布：对齐炸金花原版 background-size 100% 100%，横向拉满横屏 -->
    <img
      class="pointer-events-none absolute inset-0 z-0 h-full w-full object-fill"
      :src="tableBg"
      alt=""
    />
    <div ref="pixiHost" class="pointer-events-auto absolute inset-0 z-[5]" />

    <img
      class="pointer-events-none absolute bottom-0 left-0 z-[8] h-[72px] w-full object-fill"
      :src="flowerUi.footer"
      alt=""
    />

    <header class="absolute inset-x-0 top-0 z-30 flex items-start justify-between px-4 pt-3">
      <div class="flex items-center gap-2">
        <button type="button" class="pointer-events-auto h-11 border-0 bg-transparent p-0" @click="onBack">
          <img class="h-11 w-auto" :src="flowerUi.home" alt="返回" />
        </button>
        <button
          v-if="showDismiss"
          type="button"
          class="pointer-events-auto rounded-full bg-black/45 px-4 py-2 text-base font-semibold"
          @click="onDismiss"
        >
          解散
        </button>
      </div>
      <div class="pointer-events-none rounded-full bg-black/40 px-4 py-1.5 text-sm">
        炸金花 · 房 {{ gameState.room.roomId || '------' }}
        <span class="ml-2 tabular-nums text-amber-200">第{{ roundText }}局</span>
        <span class="ml-2 text-white/70">{{ match.unit > 0 ? `闷注 ${match.unit}` : `打底 ${match.df}` }}</span>
      </div>
      <div class="flex items-center gap-2.5">
        <button type="button" class="pointer-events-auto h-16 border-0 bg-transparent p-0" @click="ruleOpen = true">
          <img class="h-16 w-auto" :src="flowerUi.rule" alt="规则" />
        </button>
        <button type="button" class="pointer-events-auto h-16 border-0 bg-transparent p-0" @click="tipOpen = true">
          <img class="h-16 w-auto" :src="flowerUi.note" alt="提示" />
        </button>
      </div>
    </header>

    <!-- 底池（对齐 FlowerRoom.pot）；操作倒计时跟在当前玩家头像旁 -->
    <div class="pointer-events-none absolute left-1/2 top-[42%] z-20 flex -translate-x-1/2 -translate-y-1/2 flex-col items-center">
      <div class="relative flex items-center justify-center">
        <img class="h-16 w-auto drop-shadow" :src="flowerUi.totalScore" alt="" />
        <span class="absolute text-base font-extrabold text-[#ffe08a] drop-shadow">底池 {{ match.pot }}</span>
      </div>
      <p
        v-if="!match.dismissOpen && match.statusText"
        class="mt-2 max-w-[70%] text-center text-base font-bold text-white/90"
      >
        {{ match.statusText }}
      </p>
    </div>

    <p
      v-if="match.tip"
      class="pointer-events-none absolute left-1/2 top-[100px] z-30 -translate-x-1/2 rounded bg-black/55 px-3 py-1 text-sm text-amber-200"
    >
      {{ match.tip }}
    </p>

    <div
      v-for="chip in otherChips"
      :key="chip.id"
      class="pointer-events-none absolute z-[40] flex w-[120px] flex-col items-center"
      :style="chip.style"
    >
      <div class="relative h-16 w-16">
        <img
          v-if="chip.winFrame"
          class="pointer-events-none absolute -inset-2 z-0 h-[calc(100%+1rem)] w-[calc(100%+1rem)] object-fill"
          :src="flowerUi.playerWin"
          alt=""
        />
        <button
          type="button"
          class="pointer-events-auto relative z-[1] h-16 w-16 overflow-hidden rounded-full bg-black/50 ring-2"
          :class="chip.active ? 'ring-amber-300' : chip.dealer ? 'ring-red-400' : 'ring-white/25'"
          @click="showPlayerDetail({ uid: chip.id, nickname: chip.name, gold: chip.gold, avatar: chip.avatar })"
        >
          <img v-if="chip.avatar" class="h-full w-full object-cover" :src="chip.avatar" alt="" />
          <span v-else class="flex h-full w-full items-center justify-center text-sm">{{ chip.short }}</span>
        </button>
        <img
          v-if="chip.dealer"
          class="pointer-events-none absolute -right-2 -top-2 z-[2] h-8 w-8 object-contain drop-shadow"
          :src="flowerUi.zhuang"
          alt="庄"
        />
        <div
          v-if="chip.active && match.countdown > 0 && !match.dealing"
          class="seat-clock"
          :class="chip.clockLeft ? 'seat-clock--left' : 'seat-clock--right'"
        >
          <RoomClock
            :seconds="match.countdown"
            :clock-src="flowerUi.clock"
            compact
          />
        </div>
      </div>
      <p class="mt-1 max-w-full truncate text-center text-xs">{{ chip.name }}</p>
      <p class="text-[10px] text-amber-100/80">{{ chip.gold }}</p>
      <img
        v-if="chip.dismissTip"
        class="pointer-events-none mt-1 h-8 w-auto max-w-[120px] object-contain drop-shadow"
        :src="chip.dismissTip"
        alt=""
      />
      <img
        v-else-if="(uiPhase === 'wait' || uiPhase === 'settle') && chip.ready"
        class="pointer-events-none mt-1 h-8 w-auto object-contain drop-shadow"
        :src="tipReady"
        alt="已准备"
      />
      <span v-if="chip.lost" class="mt-0.5 rounded bg-rose-900/70 px-1.5 text-[10px] text-rose-200">比负</span>
      <span v-else-if="chip.folded" class="mt-0.5 rounded bg-black/50 px-1.5 text-[10px] text-white/55">弃牌</span>
      <span v-else-if="chip.looked" class="mt-0.5 rounded bg-sky-900/50 px-1.5 text-[10px] text-sky-200">已看牌</span>
      <p v-if="chip.pattern" class="text-[11px] font-semibold text-amber-200">{{ chip.pattern }}</p>
      <p
        v-if="uiPhase === 'settle' && chip.delta"
        class="text-sm font-extrabold"
        :class="chip.delta > 0 ? 'text-[rgb(234,171,55)]' : 'text-white'"
      >
        {{ chip.delta > 0 ? `+${chip.delta}` : chip.delta }}
      </p>
    </div>

    <!-- 自己中区：看牌 / 牌型 / 飘分（手牌上方） -->
    <div class="pointer-events-none absolute bottom-[120px] left-1/2 z-20 flex -translate-x-1/2 flex-col items-center">
      <button
        v-if="uiPhase === 'play' && me && !me.looked && !me.folded && !me.lost && !match.dealing"
        type="button"
        class="pointer-events-auto mb-1 h-16 border-0 bg-transparent p-0"
        @click="flowerSession.sendLook()"
      >
        <img class="h-16 w-auto" :src="flowerUi.look" alt="看牌" />
      </button>
      <p class="text-base font-extrabold text-[#ffe08a] drop-shadow">
        {{
          me?.looked || uiPhase === 'settle'
            ? match.myPattern || '已看牌'
            : uiPhase === 'play'
              ? '未看牌 · 闷牌中'
              : ''
        }}
      </p>
      <div
        v-if="uiPhase === 'settle' && match.selfDelta !== 0"
        class="mt-1 text-3xl font-extrabold"
        :class="match.selfDelta > 0 ? 'text-[rgb(234,171,55)]' : 'text-white'"
      >
        {{ match.selfDelta > 0 ? '+' : '' }}{{ match.selfDelta }}
      </div>
    </div>

    <!-- 自己信息条：右下角 -->
    <div class="pointer-events-none absolute bottom-[14px] right-[2%] z-[40] flex max-w-[42%] flex-row-reverse items-center gap-3 rounded-full border border-white/20 bg-black/55 px-3 py-2">
      <button
        type="button"
        class="pointer-events-auto relative h-12 w-12 shrink-0"
        @click="showPlayerDetail({ uid: me?.id || gameState.user.uid, nickname: me?.nickname || gameState.user.nickname, gold: me?.gold ?? gameState.user.gold, avatar: me?.avatar || gameState.user.avatar })"
      >
        <div class="h-full w-full overflow-hidden rounded-full ring-2 ring-white/30">
          <img
            class="h-full w-full object-cover"
            :src="seatAvatarSrc(me?.avatar || gameState.user.avatar, me?.id || gameState.user.uid)"
            alt=""
          />
        </div>
        <img
          v-if="amDealer"
          class="pointer-events-none absolute -left-2 -top-2 h-7 w-7 object-contain drop-shadow"
          :src="flowerUi.zhuang"
          alt="庄"
        />
      </button>
      <div class="min-w-0 text-right text-xs text-white/90">
        <p class="truncate font-semibold">{{ me?.nickname || gameState.user.nickname }}</p>
        <p class="text-amber-100">{{ me?.gold ?? gameState.user.gold }}</p>
      </div>
      <img
        v-if="selfDismissTip"
        class="pointer-events-none h-8 w-auto max-w-[120px] shrink-0 object-contain drop-shadow"
        :src="selfDismissTip"
        alt=""
      />
      <img
        v-else-if="(uiPhase === 'wait' || uiPhase === 'settle') && match.selfReady"
        class="pointer-events-none h-8 w-auto shrink-0 object-contain drop-shadow"
        :src="tipReady"
        alt="已准备"
      />
    </div>

    <DismissVoteBar
      :show="match.dismissOpen"
      :show-vote-btns="needVote"
      :dismiss-from-seat="match.dismissFromSeat"
      :my-index="match.myIndex"
      :dismiss-left="match.dismissLeft"
      bar-class="absolute bottom-[20%] left-1/2 z-50 flex -translate-x-1/2 flex-col items-center gap-2"
      @vote="(v) => flowerSession.voteDismiss(v)"
    />

    <!-- 操作区（对齐 FlowerRoom.ops） -->
    <div
      v-if="!match.pkShow"
      class="zjh-ops pointer-events-auto absolute bottom-[9%] left-1/2 z-50 flex w-[78%] max-w-[780px] -translate-x-1/2 flex-wrap items-center justify-center gap-2"
    >
      <template v-if="uiPhase === 'wait' && !match.selfReady && roomFull">
        <button type="button" class="zjh-ops__ready" @click="flowerSession.sendReady()">
          <img :src="flowerUi.readyBtn" alt="准备" />
        </button>
      </template>

      <template v-else-if="uiPhase === 'settle' && !match.roomOver && !match.selfReady && !match.resultOpen">
        <button type="button" class="gf-ops-btn gf-ops-btn--orange" @click="flowerSession.sendReady()">
          再来一局
        </button>
      </template>

      <template v-else-if="myTurn && !meOut">
        <RoomClock
          v-if="match.countdown > 0 && !match.dealing"
          :seconds="match.countdown"
          :clock-src="flowerUi.clock"
          compact
        />
        <button
          type="button"
          class="zjh-ops__chip"
          :class="{ look: me?.looked }"
          @click="flowerSession.sendCall()"
        >
          {{ me?.looked ? '看跟' : '闷跟' }} {{ callAmt }}
        </button>
        <button
          v-for="amt in raiseAmts"
          :key="'r' + amt"
          type="button"
          class="zjh-ops__chip"
          :class="{ look: me?.looked }"
          @click="flowerSession.sendRaise(amt)"
        >
          {{ me?.looked ? '看加' : '闷加' }} {{ amt }}
        </button>
        <button type="button" class="zjh-ops__round fold" @click="flowerSession.sendFold()">弃牌</button>
        <button
          type="button"
          class="zjh-ops__round pk"
          :class="{ off: !canCompare }"
          @click="onCompare"
        >
          比牌 {{ callAmt }}
        </button>
      </template>

      <template v-else-if="uiPhase === 'play' && !myTurn && !meOut">
        <div class="zjh-ops__wait">等待 {{ turnName }} 操作</div>
      </template>
      <template v-else-if="uiPhase === 'play' && meOut">
        <div class="zjh-ops__wait">{{ me?.lost ? '比牌负 · 旁观中' : '已弃牌 · 旁观中' }}</div>
      </template>
    </div>

    <FlowerResultOverlay
      :show="match.resultOpen && uiPhase === 'settle'"
      :self-win="match.selfDelta > 0"
      :no-compare="match.noCompare"
      :rows="resultRows"
      @again="onSettleAgain"
      @hall="onSettleHall"
    />

    <FlowerPkOverlay
      :show="match.pkShow"
      :left="match.pkLeft"
      :right="match.pkRight"
      :loser-id="match.pkLoserId"
      :done="match.pkDone"
      @cancel="match.pkShow = false"
    />

    <AppDialog :show="match.compareOpen" title="选择比牌对象" closable @close="match.compareOpen = false">
      <p class="text-center text-[20px] leading-8 text-[#5a3a18]/85">比牌支付与跟注相同（{{ callAmt }}），不另收双倍</p>
      <div class="mt-4 flex flex-col gap-3">
        <button
          v-for="p in compareTargets"
          :key="p.id"
          type="button"
          class="gf-btn-green pointer-events-auto w-full py-3.5 text-[22px] font-extrabold text-white"
          :style="{ backgroundImage: `url(${btnGreenLong})` }"
          @click="confirmCompare(p.id)"
        >
          {{ p.nickname || `座位${p.seat + 1}` }}
          <em v-if="p.looked" class="ml-1 not-italic text-white/80">已看牌</em>
        </button>
        <p v-if="!compareTargets.length" class="text-center text-[20px] leading-8 text-[#5a3a18]/55">没有可比的玩家</p>
      </div>
    </AppDialog>
    <AppDialog :show="leaveOpen" :title="backTitle" closable @close="leaveOpen = false">
      <p class="text-center text-[20px] leading-8 text-[#5a3a18]/85">{{ backTip }}</p>
      <template #actions>
        <button
          type="button"
          class="gf-btn-green flex-1 py-3.5 text-[22px] font-extrabold text-white"
          :style="{ backgroundImage: `url(${btnGreenLong})` }"
          @click="leaveOpen = false"
        >
          取消
        </button>
        <button
          type="button"
          class="gf-btn-orange flex-1 py-3.5 text-[22px] font-extrabold text-white"
          :style="{ backgroundImage: `url(${btnOrangeLong})` }"
          @click="confirmLeave"
        >
          确定
        </button>
      </template>
    </AppDialog>
    <DismissConfirmDialog
      :show="dismissOpen"
      :idle="dismissIdle"
      @close="dismissOpen = false"
      @confirm="confirmDismiss"
    />
    <AppDialog :show="ruleOpen" title="房间规则" closable width="560px" @close="ruleOpen = false">
      <div class="space-y-2 text-left text-[18px] leading-8 text-[#4a3018]">
        <p><span class="text-[#5a3a18]/55">房主：</span>{{ ownerName }}</p>
        <p><span class="text-[#5a3a18]/55">模式：</span>炸金花</p>
        <p><span class="text-[#5a3a18]/55">底分：</span>{{ match.df }}</p>
        <p><span class="text-[#5a3a18]/55">人数：</span>{{ match.seats }} 人</p>
        <p><span class="text-[#5a3a18]/55">房间号：</span>{{ gameState.room.roomId || '------' }}</p>
        <p><span class="text-[#5a3a18]/55">最大跟注：</span>看牌最高 {{ match.lookMax }}，闷牌最高 {{ match.menMax }}</p>
      </div>
      <div class="mt-3 space-y-2">
        <div
          v-for="section in flowerPlayNotes"
          :key="section.title"
          class="rounded-lg bg-white/45 px-3 py-2"
        >
          <p class="text-[14px] font-semibold leading-6 text-[#5a3a18]">{{ section.title }}</p>
          <p
            v-for="(line, idx) in section.lines"
            :key="`${section.title}-${idx}`"
            class="text-[14px] leading-6 text-[#8a5a28]/85"
          >
            {{ line }}
          </p>
        </div>
      </div>
    </AppDialog>
    <AppDialog :show="tipOpen" title="温馨提示" closable @close="tipOpen = false">
      <p class="text-center text-[20px] leading-8 text-[#5a3a18]/85">
        抵制不良游戏，拒绝盗版游戏。<br />
        注意自我保护，谨防受骗上当。<br />
        适度游戏益脑，沉迷游戏伤身。
      </p>
    </AppDialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, onUnmounted, ref, watch } from 'vue'
import { tipImgs } from '@/logic/shared/tipImgs'
import {
  DISMISS_OWNER_ONLY,
  dismissTipSrc,
  showDismissEntry,
} from '@/logic/shared/dismiss'
import DismissConfirmDialog from '@/components/DismissConfirmDialog.vue'
import DismissVoteBar from '@/components/DismissVoteBar.vue'
const tipReady = tipImgs.ready
import AppDialog from '@/components/AppDialog.vue'
import FlowerPkOverlay from '@/components/flower/FlowerPkOverlay.vue'
import FlowerResultOverlay, { type FlowerResultRow } from '@/components/flower/FlowerResultOverlay.vue'
import RoomClock from '@/components/RoomClock.vue'
import { eventBus } from '@/core/eventBus'
import { FlowerEvents } from '@/logic/flower/FlowerCallback'
import { flowerUi } from '@/logic/flower/flowerUi'
import { callAmountFor, flowerCreateRoomRules, PHASE, resolveRaiseAmounts } from '@/logic/flower/rules/room'
import { DESIGN_W, ringSlot, slotStyle } from '@/logic/shared/ringSeats'
import { mountPokerPixi, type PokerSeatTable } from '@/pixi/pokerSeatTable'
import { flowerSession } from '@/services/flowerSession'
import { flowerState, needMyFlowerDismissVote } from '@/services/flowerState'
import { gameState, roomHasStarted, roomOwnerName } from '@/services/gameState'
import { showPlayerDetail } from '@/services/playerDetail'
import { seatAvatarSrc } from '@/services/seatAvatar'
import { assetUrl } from '@/services/assetUrl'
import { art } from '@/utils/art'
import { returnToHallKeepingSeat } from '@/services/roomResume'

const match = flowerState
const ownerName = computed(
  () => roomOwnerName() || match.players.find((p) => p.isMaster)?.nickname || '—',
)
const roundText = computed(() => {
  const total = match.totalRound || Number(gameState.room.zjs) || 0
  let now = match.round || Number(gameState.room.js) || 1
  if (!Number.isFinite(now) || now < 1) now = 1
  if (total > 0 && now > total) now = total
  return total > 0 ? `${now}/${total}` : `${now}`
})
const tableBg = assetUrl('images/flower/table-zhajinhua.jpg')
const btnOrangeLong = art('ui', 'dizhu', 'btn_orange_long.png')
const btnGreenLong = art('ui', 'dizhu', 'btn_green_long.png')
const opsOrangeBg = `url("${btnOrangeLong}") center / 100% 100% no-repeat`
const opsGreenBg = `url("${btnGreenLong}") center / 100% 100% no-repeat`
const flowerPlayNotes = flowerCreateRoomRules.playNotes
const leaveOpen = ref(false)
const dismissOpen = ref(false)
const ruleOpen = ref(false)
const tipOpen = ref(false)
const pixiHost = ref<HTMLElement | null>(null)
let alive = false
let pixiDestroy: (() => void) | null = null
let table: PokerSeatTable | null = null
let offStart: (() => void) | null = null
let offAction: (() => void) | null = null
let offSettle: (() => void) | null = null

const uiPhase = computed(() => {
  const p = String(match.phase).toLowerCase()
  if (p === PHASE.BET) return 'play'
  if (p === PHASE.SETTLE) return 'settle'
  return 'wait'
})

const me = computed(() => match.players.find((p) => p.id === match.myUid))
const amDealer = computed(() => match.dealerId > 0 && match.dealerId === match.myUid)
const myTurn = computed(
  () => uiPhase.value === 'play' && !!me.value && !me.value.folded && !me.value.lost && me.value.seat === match.turnSeat,
)
const meOut = computed(() => !!me.value && (me.value.folded || me.value.lost))
const callAmt = computed(() => callAmountFor(!!me.value?.looked, match.unit, match.selectPoints))
const raiseAmts = computed(() =>
  resolveRaiseAmounts({
    looked: !!me.value?.looked,
    unit: match.unit,
    maxFollow: match.lookMax,
    menMax: match.menMax,
    lookMax: match.lookMax,
    selectPoints: match.selectPoints,
  }),
)
const compareTargets = computed(() =>
  match.players.filter((p) => p.id !== match.myUid && !p.folded && !p.lost),
)
const aliveCount = computed(() => match.players.filter((p) => !p.folded && !p.lost).length)
const canCompare = computed(() => {
  if (!myTurn.value) return false
  if (!me.value?.looked && aliveCount.value > 2) return false
  return compareTargets.value.length > 0
})
const needVote = computed(() => needMyFlowerDismissVote())
const dismissIdle = computed(() => uiPhase.value === 'wait' && !roomHasStarted())
const showDismiss = computed(() =>
  showDismissEntry({
    dismissOpen: match.dismissOpen,
    idle: dismissIdle.value,
    isOwner: !!gameState.room.isMaster,
  }),
)
const selfDismissTip = computed(() => dismissTipSrc(match, match.myIndex))
const roomFull = computed(() => match.players.length >= Math.max(2, match.seats) || match.canStart)
const turnName = computed(() => {
  const p = match.players.find((x) => x.seat === match.turnSeat)
  return p?.nickname || '其他玩家'
})

function onCompare(): void {
  if (!canCompare.value) {
    match.tip = '当前不可比牌'
    window.setTimeout(() => {
      if (match.tip === '当前不可比牌') match.tip = ''
    }, 1600)
    return
  }
  const targets = compareTargets.value
  if (targets.length === 1) {
    void confirmCompare(targets[0].id)
    return
  }
  match.compareOpen = true
}

function confirmCompare(targetId: number): void {
  match.compareOpen = false
  void flowerSession.sendCompare(targetId)
}

function relOf(seat: number): number {
  const n = Math.max(match.seats, 1)
  return ((seat - match.myIndex) % n + n) % n
}

function otherRel(seat: number): number {
  const r = relOf(seat)
  return r === 0 ? 1 : r
}

function seatRel(seat: number, id: number): number {
  if (id > 0 && id === match.myUid) return 0
  return otherRel(seat)
}

const otherChips = computed(() => {
  const total = Math.max(match.players.length, 2)
  const others = match.players.filter((p) => p.id !== match.myUid)
  return others.map((p) => {
    const rel = otherRel(p.seat)
    const slot = ringSlot(rel, total)
    // 对齐 game OtherPlayerSeats：靠右座位闹钟放头像左侧（内侧），靠左放右侧，避免出屏
    return {
      id: p.id,
      seat: p.seat,
      name: p.nickname || `座位${p.seat + 1}`,
      short: (p.nickname || '玩').slice(0, 1),
      avatar: seatAvatarSrc(p.avatar, p.id),
      gold: p.gold,
      ready: p.ready,
      dealer: match.dealerId === p.id,
      winFrame: uiPhase.value === 'settle' && p.lastDelta > 0,
      looked: p.looked,
      folded: p.folded,
      lost: p.lost,
      pattern: uiPhase.value === 'settle' ? p.pattern : '',
      delta: p.lastDelta,
      active: uiPhase.value === 'play' && match.turnSeat === p.seat && !p.folded && !p.lost,
      clockLeft: slot.x >= DESIGN_W / 2,
      style: slotStyle(slot),
      dismissTip: dismissTipSrc(match, p.seat),
    }
  })
})

const resultRows = computed((): FlowerResultRow[] => {
  const list = match.players.filter((p) => Number(p.id) > 0)
  let bigId = 0
  let bigDelta = -Infinity
  for (const p of list) {
    if (p.lastDelta > bigDelta) {
      bigDelta = p.lastDelta
      bigId = p.id
    }
  }
  return list.map((p) => ({
    id: p.id,
    nickname: p.nickname || `座位${p.seat + 1}`,
    avatar: p.avatar,
    delta: p.lastDelta,
    ante: p.anteAmt || 0,
    men: p.menAmt || 0,
    follow: p.followAmt || 0,
    pk: p.pkAmt || 0,
    bill: p.bill || '',
    pattern: p.pattern || '',
    status: p.folded ? '弃牌' : p.lost ? '比负' : p.looked ? '已看牌' : '',
    isSelf: Number(p.id) === Number(match.myUid),
    isBanker: Number(p.id) === Number(match.dealerId),
    isBigWinner: bigDelta > 0 && p.id === bigId,
  }))
})

function onSettleAgain(): void {
  match.resultOpen = false
  void flowerSession.sendReady()
}

function onSettleHall(): void {
  match.resultOpen = false
  leaveOpen.value = true
}

function visibleCards(cards: string[], looked: boolean, self: boolean): string[] {
  if (uiPhase.value === 'wait') return []
  if (uiPhase.value === 'settle') return cards
  if (self && looked && match.myCards.length) return match.myCards
  return cards.length ? cards : []
}

function drawModel() {
  const total = Math.max(match.players.length, 2)
  const seats = match.players.map((p) => {
    const self = p.id === match.myUid
    return {
      id: p.id,
      rel: seatRel(p.seat, p.id),
      cards: visibleCards(p.cards, p.looked, self),
      highlight: uiPhase.value === 'play' && match.turnSeat === p.seat && !p.folded,
    }
  })
  table?.render({ seats, total, pot: match.pot, dealing: match.dealing })
}

function onBack(): void {
  leaveOpen.value = true
}

const backInRound = computed(() => uiPhase.value === 'play')
const backTitle = computed(() => '返回大厅')
const backTip = computed(
  () => '返回大厅后游戏将自动托管，座位会保留。中途解散请点解散并全员投票同意。确定返回吗？',
)

async function confirmLeave(): Promise<void> {
  leaveOpen.value = false
  if (backInRound.value) {
    await flowerSession.enableTrust()
  }
  returnToHallKeepingSeat()
}

function onDismiss(): void {
  if (match.dismissOpen) return
  if (dismissIdle.value && !gameState.room.isMaster) {
    match.tip = DISMISS_OWNER_ONLY
    return
  }
  dismissOpen.value = true
}

function confirmDismiss(): void {
  dismissOpen.value = false
  void flowerSession.requestDismiss().catch((err) => {
    match.tip = err instanceof Error ? err.message : '解散失败'
  })
}

const tableSnapshot = computed(() => ({
  seats: match.players,
  hand: match.myCards,
  pot: match.pot,
  turn: match.turnSeat,
  dealing: match.dealing,
  cardsKey: match.players
    .map((p) => `${p.id}:${p.cards.join(',')}:${p.looked ? 1 : 0}:${p.folded ? 1 : 0}`)
    .join('|'),
  countdown: match.countdown,
}))

watch(
  tableSnapshot,
  () => drawModel(),
  { deep: true },
)

onMounted(async () => {
  alive = true
    offStart = eventBus.on(FlowerEvents.START, () => {
    drawModel()
    table?.playDeal()
  })
  offAction = eventBus.on(FlowerEvents.ACTION, (payload) => {
    const data = (payload || {}) as Record<string, unknown>
    const last = (
      data.lastAction && typeof data.lastAction === 'object' ? data.lastAction : {}
    ) as Record<string, unknown>
    if (String(last.action || '').toLowerCase() !== 'compare') return
    const fromSeat = Number(last.seat)
    const toSeat = Number(last.target)
    if (!Number.isFinite(fromSeat) || !Number.isFinite(toSeat)) return
    table?.playClash(relOf(fromSeat), relOf(toSeat))
  })
  offSettle = eventBus.on(FlowerEvents.SETTLE, () => {
    table?.playSettle(
      match.players.map((p) => ({
        rel: seatRel(p.seat, p.id),
        delta: p.lastDelta,
      })),
    )
  })
  await flowerSession.enter()
  if (!alive || !pixiHost.value) return
  try {
    const mounted = await mountPokerPixi(pixiHost.value)
    if (!alive) {
      mounted.destroy()
      return
    }
    pixiDestroy = mounted.destroy
    table = mounted.table
    drawModel()
    if (match.dealing) table.playDeal()
  } catch (err) {
    console.warn('[FlowerRoomView] pixi', err)
  }
})

onBeforeUnmount(() => {
  alive = false
  offStart?.()
  offAction?.()
  offSettle?.()
  offStart = null
  offAction = null
  offSettle = null
  pixiDestroy?.()
  pixiDestroy = null
  table = null
})

onUnmounted(() => {
  void flowerSession.leave({ keepSeat: true })
})
</script>

<style scoped>
.gf-btn-orange,
.gf-btn-green {
  border: 0;
  background-color: transparent;
  background-repeat: no-repeat;
  background-position: center;
  background-size: 100% 100%;
}
.zjh-ops__ready {
  border: 0;
  padding: 0;
  background: transparent;
  height: 86px;
  cursor: pointer;
}
.zjh-ops__ready img {
  height: 86px;
  width: auto;
}
.gf-ops-btn {
  min-width: 180px;
  height: 76px;
  border: 0;
  border-radius: 999px;
  color: #fff;
  font-size: 24px;
  font-weight: 800;
  letter-spacing: 0.06em;
  cursor: pointer;
  padding: 0 28px;
}
.gf-ops-btn--orange {
  text-shadow: 0 2px 0 rgba(80, 40, 0, 0.45);
  background: v-bind(opsOrangeBg);
}
.gf-ops-btn--green {
  text-shadow: 0 2px 0 rgba(20, 60, 0, 0.35);
  background: v-bind(opsGreenBg);
}
.zjh-ops__chip {
  min-width: 112px;
  height: 68px;
  border: 0;
  border-radius: 999px;
  color: #5a2a00;
  font-size: 20px;
  font-weight: 800;
  cursor: pointer;
  background: linear-gradient(180deg, #ffe08a, #e0a040);
  box-shadow: 0 3px 5px rgba(0, 0, 0, 0.35);
  padding: 0 16px;
}
.zjh-ops__chip.look {
  background: linear-gradient(180deg, #9ad0ff, #3a7ec8);
  color: #fff;
}
.zjh-ops__round {
  min-width: 124px;
  height: 68px;
  border: 0;
  border-radius: 999px;
  color: #fff;
  font-size: 20px;
  font-weight: 800;
  cursor: pointer;
  text-shadow: 0 1px 2px #000;
  padding: 0 16px;
}
.zjh-ops__round.fold {
  background: linear-gradient(180deg, #8a8a8a, #4a4a4a);
}
.zjh-ops__round.pk {
  background: linear-gradient(180deg, #ff8a6a, #c04030);
}
.zjh-ops__round.pk.off {
  opacity: 0.4;
}
.seat-clock {
  position: absolute;
  top: 50%;
  z-index: 6;
  transform: translateY(-50%);
  pointer-events: none;
  line-height: 0;
}
.seat-clock--right {
  left: calc(100% + 20px);
  right: auto;
}
.seat-clock--left {
  right: calc(100% + 20px);
  left: auto;
}
.zjh-ops__wait {
  min-width: 200px;
  height: 60px;
  line-height: 60px;
  text-align: center;
  color: #ffe08a;
  font-size: 20px;
  font-weight: 700;
  text-shadow: 0 1px 2px #000;
  background: rgba(0, 0, 0, 0.45);
  border-radius: 8px;
  padding: 0 16px;
}

</style>
