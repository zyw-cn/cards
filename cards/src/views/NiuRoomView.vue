<template>
  <div class="pointer-events-none relative h-full w-full select-none overflow-hidden text-white">
    <!-- 竖图桌布：对齐 game GameTable object-fit:fill，横向拉满横屏舞台 -->
    <img
      class="pointer-events-none absolute inset-0 z-0 h-full w-full object-fill"
      :src="tableBg"
      alt=""
    />
    <div ref="pixiHost" class="pointer-events-none absolute inset-0 z-[5]" />

    <!-- 底栏 footer_bull（对齐 game Room） -->
    <img
      class="pointer-events-none absolute bottom-0 left-0 z-[8] h-[72px] w-full object-fill"
      :src="niuUi.footer"
      alt=""
    />

    <!-- 顶栏：回家 / 规则 / 提示 / 音效 / 解散 -->
    <header class="absolute inset-x-0 top-0 z-30 flex items-start justify-between px-4 pt-3">
      <div class="flex items-center gap-2">
        <button type="button" class="pointer-events-auto h-11 border-0 bg-transparent p-0" @click="onBack">
          <img class="h-11 w-auto" :src="niuUi.home" alt="返回" />
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
        {{ match.modeName || gameState.room.playName || '斗牛' }} · 房 {{ gameState.room.roomId || '------' }}
        <span class="ml-2 text-amber-200">底分 {{ match.df }}</span>
        <span v-if="match.bankBeishu > 1" class="ml-2 inline-flex items-center">
          庄
          <img :src="niuBeiSrc(match.bankBeishu)" alt="" class="ml-0.5 h-5 w-auto" />
        </span>
      </div>
      <div class="flex items-center gap-2.5">
        <button type="button" class="pointer-events-auto h-16 border-0 bg-transparent p-0" @click="ruleOpen = true">
          <img class="h-16 w-auto" :src="niuUi.rule" alt="规则" />
        </button>
        <button type="button" class="pointer-events-auto h-16 border-0 bg-transparent p-0" @click="tipOpen = true">
          <img class="h-16 w-auto" :src="niuUi.note" alt="提示" />
        </button>
        <button type="button" class="pointer-events-auto h-16 border-0 bg-transparent p-0" @click="openSettings">
          <img class="h-16 w-auto" :src="niuUi.sound" alt="音效" />
        </button>
      </div>
    </header>

    <!-- 桌心：非抢庄轮次才放大闹钟；抢庄时闹钟跟当前玩家头像 -->
    <div class="pointer-events-none absolute left-1/2 top-[52%] z-20 flex max-w-[72%] -translate-x-1/2 -translate-y-1/2 flex-col items-center gap-1.5 text-center">
      <RoomClock
        v-if="showCenterClock"
        :seconds="match.countdown"
        :clock-src="niuUi.clock"
        stage
      />
      <div
        v-if="(uiPhase === 'show' || uiPhase === 'settle') && me?.shown && myBadge"
        class="niu-tip mt-1 flex items-center justify-center"
      >
        <img class="niu-tip__badge" :src="myBadge" alt="" />
        <img
          v-if="myNiuBei"
          class="niu-tip__bei"
          :src="myNiuBei"
          alt=""
        />
      </div>
    </div>

    <!-- 他人座位 -->
    <div
      v-for="chip in otherChips"
      :key="chip.id"
      class="pointer-events-none absolute z-[40] flex w-[120px] flex-col items-center"
      :style="chip.style"
      :class="chip.rollActive ? 'scale-110' : ''"
    >
      <div
        class="relative h-16 w-16"
        :class="[
          chip.rollActive ? 'drop-shadow-[0_0_12px_rgba(255,212,90,0.95)]' : '',
          chip.active ? 'seat-turn' : '',
        ]"
      >
        <img
          v-if="chip.winFrame"
          class="pointer-events-none absolute -inset-2 z-0 h-[calc(100%+1rem)] w-[calc(100%+1rem)] object-fill"
          :src="niuUi.playerWin"
          alt=""
        />
        <button
          type="button"
          class="pointer-events-auto relative z-[1] h-16 w-16 overflow-hidden rounded-full bg-black/50 ring-2"
          :class="chip.active || chip.rollActive ? 'ring-amber-300' : chip.banker ? 'ring-red-400' : 'ring-white/25'"
          @click="showPlayerDetail({ uid: chip.id, nickname: chip.name, gold: chip.gold, avatar: chip.avatar })"
        >
          <img v-if="chip.avatar" class="h-full w-full object-cover" :src="chip.avatar" alt="" />
          <span v-else class="flex h-full w-full items-center justify-center text-sm">{{ chip.short }}</span>
        </button>
        <img
          v-if="chip.banker"
          class="pointer-events-none absolute -right-2 -top-2 z-[2] h-8 w-8 object-contain drop-shadow"
          :src="niuUi.zhuang"
          alt="庄"
        />
        <!-- 轮到该玩家抢庄：头像旁小闹钟 -->
        <div
          v-if="chip.active && match.countdown > 0"
          class="seat-clock"
          :class="chip.clockLeft ? 'seat-clock--left' : 'seat-clock--right'"
        >
          <RoomClock
            :seconds="match.countdown"
            :clock-src="niuUi.clock"
            compact
          />
        </div>
        <!-- 牛几图 + 倍数（倍数偏右，避免挤变形） -->
        <div
          v-if="(uiPhase === 'show' || uiPhase === 'settle') && chip.shown && chip.badge"
          class="seat-niu niu-tip pointer-events-none absolute left-1/2 z-[2] -translate-x-1/2"
        >
          <img class="niu-tip__badge niu-tip__badge--seat" :src="chip.badge" alt="" />
          <img v-if="chip.bei" class="niu-tip__bei niu-tip__bei--seat" :src="chip.bei" alt="" />
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
      <p v-if="chip.qz >= 0 && uiPhase === 'grab'" class="mt-0.5 flex items-center justify-center">
        <img
          v-if="chip.qz === 0"
          :src="niuUi.notRob"
          alt="不抢"
          class="h-8 w-auto object-contain drop-shadow"
        />
        <img
          v-else-if="grabTipSrc(chip.qz)"
          :src="grabTipSrc(chip.qz)"
          :alt="`${chip.qz}分`"
          class="h-10 w-auto object-contain drop-shadow"
        />
        <template v-else>
          <img :src="niuUi.rob" alt="抢庄" class="h-5 w-auto object-contain drop-shadow" />
          <img :src="niuPointSrc(chip.qz)" :alt="`${chip.qz}分`" class="h-4 w-auto object-contain" />
        </template>
      </p>
      <p v-if="chip.bet > 0" class="mt-0.5 flex items-center justify-center">
        <img :src="niuBeiSrc(chip.bet)" :alt="`注×${chip.bet}`" class="h-4 w-auto" />
      </p>
      <div
        v-if="uiPhase === 'settle' && chip.delta"
        class="niu-score mt-0.5"
        :class="chip.delta > 0 ? 'niu-score--win' : 'niu-score--lose'"
      >
        <i class="niu-score__sign" :class="chip.delta > 0 ? 'is-plus' : 'is-minus'" />
        <img
          v-for="(src, i) in scoreDigits(chip.delta)"
          :key="'d' + chip.id + i"
          :src="src"
          alt=""
          class="niu-score__digit"
        />
      </div>
    </div>

    <!-- 自己结算飘分（图片数字，无文字） -->
    <div
      v-if="uiPhase === 'settle' && match.selfDelta !== 0"
      class="niu-score niu-score--self pointer-events-none absolute bottom-[168px] left-1/2 z-40 -translate-x-1/2"
      :class="match.selfDelta > 0 ? 'niu-score--win' : 'niu-score--lose'"
    >
      <i class="niu-score__sign niu-score__sign--lg" :class="match.selfDelta > 0 ? 'is-plus' : 'is-minus'" />
      <img
        v-for="(src, i) in scoreDigits(match.selfDelta)"
        :key="'sd' + i"
        :src="src"
        alt=""
        class="niu-score__digit niu-score__digit--lg"
      />
    </div>

    <!-- 自己信息条：右下角（定庄轮转打在这里，勿用对面） -->
    <div
      class="pointer-events-none absolute bottom-[14px] right-[2%] z-[40] flex max-w-[42%] flex-row-reverse items-center gap-3 rounded-full border border-white/20 bg-black/55 px-3 py-2"
      :class="{
        'scale-110 ring-2 ring-amber-300/80': selfRollActive || isMyGrab,
        'ring-1 ring-amber-200/40': (selfRollCandidate && !selfRollActive) || (uiPhase === 'grab' && isMyGrab),
      }"
    >
      <button
        type="button"
        class="pointer-events-auto relative h-12 w-12 shrink-0"
        :class="selfRollActive || isMyGrab ? 'drop-shadow-[0_0_12px_rgba(255,212,90,0.95)]' : ''"
        @click="showPlayerDetail({ uid: me?.id || gameState.user.uid, nickname: me?.nickname || gameState.user.nickname, gold: me?.gold ?? gameState.user.gold, avatar: me?.avatar || gameState.user.avatar })"
      >
        <div
          class="h-full w-full overflow-hidden rounded-full ring-2"
          :class="selfRollActive || isMyGrab || amBanker ? 'ring-amber-300' : 'ring-white/30'"
        >
          <img
            class="h-full w-full object-cover"
            :src="seatAvatarSrc(me?.avatar || gameState.user.avatar, me?.id || gameState.user.uid)"
            alt=""
          />
        </div>
        <img
          v-if="amBanker"
          class="pointer-events-none absolute -left-2 -top-2 h-7 w-7 object-contain drop-shadow"
          :src="niuUi.zhuang"
          alt="庄"
        />
      </button>
      <div class="min-w-0 text-right text-xs text-white/90">
        <p class="truncate font-semibold">{{ me?.nickname || gameState.user.nickname }}</p>
        <p class="text-amber-100">{{ me?.gold ?? gameState.user.gold }}</p>
      </div>
    </div>

    <!-- 自己：解散 tip / 准备 tip -->
    <img
      v-if="selfDismissTip"
      :src="selfDismissTip"
      alt=""
      class="pointer-events-none absolute bottom-[108px] left-1/2 z-40 h-9 w-auto max-w-[140px] -translate-x-1/2 object-contain drop-shadow"
    />
    <img
      v-else-if="(uiPhase === 'wait' || uiPhase === 'settle') && match.selfReady"
      :src="tipReady"
      alt="已准备"
      class="pointer-events-none absolute bottom-[108px] left-1/2 z-40 h-10 w-auto -translate-x-1/2 object-contain drop-shadow"
    />

    <DismissVoteBar
      :show="match.dismissOpen"
      :show-vote-btns="needVote"
      :dismiss-from-seat="match.dismissFromSeat"
      :my-index="match.myIndex"
      :dismiss-left="match.dismissLeft"
      bar-class="absolute bottom-[168px] left-1/2 z-50 flex -translate-x-1/2 flex-col items-center gap-2"
      @vote="(v) => niuSession.voteDismiss(v)"
    />

    <!-- 已叫分：停在手牌下沿 -->
    <div
      v-if="uiPhase === 'grab' && !isMyGrab"
      class="pointer-events-none absolute left-1/2 z-40 flex -translate-x-1/2 flex-col items-center gap-2"
      :style="{ top: `${grabRowTop}px` }"
    >
      <img
        v-if="myGrabTip"
        :src="myGrabTip"
        :alt="`${me?.qz ?? ''}分`"
        class="h-12 w-auto object-contain drop-shadow"
      />
      <img
        v-else-if="me?.qz === 0"
        :src="niuUi.notRob"
        alt="不抢"
        class="h-10 w-auto object-contain drop-shadow"
      />
      <p class="niu-ops__wait">{{ grabWaitText }}</p>
    </div>

    <!-- 按钮：屏幕底部，不压手牌 -->
    <div class="niu-ops pointer-events-auto absolute bottom-[100px] left-1/2 z-50 flex w-[70%] max-w-[720px] -translate-x-1/2 flex-wrap items-center justify-center gap-3">
      <RoomClock
        v-if="uiPhase === 'grab' && isMyGrab && !isFreeGrab && match.countdown > 0"
        :seconds="match.countdown"
        :clock-src="niuUi.clock"
        compact
      />
      <template v-if="uiPhase === 'grab' && isMyGrab && isFreeGrab">
        <button type="button" class="niu-ops__btn" aria-label="不抢" @click="onGrab(0)">
          <img :src="niuUi.btnBlue" alt="" />
          <img class="niu-ops__label" :src="niuUi.notRob" alt="不抢" />
        </button>
        <button
          v-for="n in freeGrabMuls"
          :key="'fg' + n"
          type="button"
          class="niu-ops__tip"
          :aria-label="`${n}分`"
          @click="onGrab(n)"
        >
          <img :src="grabTipSrc(n)" :alt="`${n}分`" />
        </button>
      </template>
      <template v-else-if="uiPhase === 'grab' && isMyGrab">
        <button type="button" class="niu-ops__btn" aria-label="不抢" @click="onGrab(0)">
          <img :src="niuUi.btnBlue" alt="" />
          <img class="niu-ops__label" :src="niuUi.notRob" alt="不抢" />
        </button>
        <button
          v-for="n in grabScoreOpts"
          :key="'q' + n"
          type="button"
          :class="grabTipSrc(n) ? 'niu-ops__tip' : 'niu-ops__btn'"
          :aria-label="`${n}分`"
          @click="onGrab(n)"
        >
          <img v-if="grabTipSrc(n)" :src="grabTipSrc(n)" :alt="`${n}分`" />
          <template v-else>
            <img :src="niuUi.btnOrange" alt="" />
            <img class="niu-ops__label niu-ops__label--bei" :src="niuBeiSrc(n)" :alt="`×${n}`" />
          </template>
        </button>
      </template>
      <template v-else-if="uiPhase === 'wait'">
        <button
          v-if="showReadyBtn"
          type="button"
          class="niu-ops__ready"
          @click="niuSession.sendReady()"
        >
          <img :src="niuUi.readyBtn" alt="准备" />
        </button>
      </template>

      <!-- 闲家下注：bei 倍数图 -->
      <template v-else-if="uiPhase === 'bet' && !amBanker && (me?.bet ?? -1) < 0">
        <button
          v-for="n in betOpts"
          :key="'b' + n"
          type="button"
          class="gf-ops-btn gf-ops-btn--orange gf-ops-btn--art"
          :aria-label="`${n}倍`"
          @click="niuSession.sendBet(n)"
        >
          <img :src="niuBeiSrc(n)" :alt="`${n}倍`" class="gf-ops-btn__art gf-ops-btn__art--bei" />
        </button>
      </template>

      <!-- 看牌 / 亮牌：发牌后自己 2 明 3 暗 -->
      <template v-else-if="uiPhase === 'show' && me && !me.shown">
        <button
          v-if="!me.looked"
          type="button"
          class="niu-ops__btn"
          aria-label="看牌"
          @click="niuSession.sendLook()"
        >
          <img :src="niuUi.btnOrange" alt="" />
          <span class="niu-ops__txt">看牌</span>
        </button>
        <button
          type="button"
          class="niu-ops__btn"
          aria-label="亮牌"
          @click="niuSession.sendShow()"
        >
          <img :src="niuUi.btnBlue" alt="" />
          <span class="niu-ops__txt">亮牌</span>
        </button>
      </template>

      <!-- 结算：弹窗外的兜底「再来一局」（弹窗打开时隐藏） -->
      <template v-else-if="uiPhase === 'settle' && !match.roomOver && !match.selfReady && !match.resultOpen">
        <button type="button" class="gf-ops-btn gf-ops-btn--orange" @click="niuSession.sendReady()">
          再来一局
        </button>
      </template>
    </div>

    <NiuResultOverlay
      :show="match.resultOpen && uiPhase === 'settle'"
      :self-win="match.selfDelta > 0"
      :rows="resultRows"
      @again="onSettleAgain"
      @hall="onSettleHall"
    />

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
        <p><span class="text-[#5a3a18]/55">模式：</span>{{ match.modeName || '斗牛' }}</p>
        <p><span class="text-[#5a3a18]/55">底分：</span>{{ match.df }}</p>
        <p><span class="text-[#5a3a18]/55">人数：</span>{{ match.seats }} 人</p>
        <p><span class="text-[#5a3a18]/55">房间号：</span>{{ gameState.room.roomId || '------' }}</p>
      </div>
      <div class="mt-3 space-y-2">
        <div
          v-for="section in niuPlayNotes"
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
import ModalShell from '@/components/ModalShell.vue'
import RoomClock from '@/components/RoomClock.vue'
import NiuResultOverlay, { type NiuResultRow } from '@/components/niu/NiuResultOverlay.vue'
import { eventBus } from '@/core/eventBus'
import { NiuEvents } from '@/logic/niu/NiuCallback'
import { niuBeiSrc, niuGrabTipSrc, niuPointSrc, niuScoreDigitSrcs as scoreDigitSrcs } from '@/logic/niu/niuArt'
import { playNiuBgm } from '@/logic/niu/niuAudio'
import { niuUi } from '@/logic/niu/niuUi'
import { niuBadgeUrl } from '@/logic/niu/rules/hand'
import { BET_MULTIPLES, GRAB_MULTIPLES, PHASE, niuCreateRoomRules } from '@/logic/niu/rules/room'
import { DESIGN_H, DESIGN_W, ringSlot, slotStyle } from '@/logic/shared/ringSeats'
import { mountPokerPixi, type PokerSeatTable } from '@/pixi/pokerSeatTable'
import { gameState, roomHasStarted, roomOwnerName } from '@/services/gameState'
import { showPlayerDetail } from '@/services/playerDetail'
import { seatAvatarSrc } from '@/services/seatAvatar'
import { niuSession } from '@/services/niuSession'
import { needMyNiuDismissVote, niuState } from '@/services/niuState'
import { audioManager } from '@/services/audioManager'
import { assetUrl } from '@/services/assetUrl'
import { art } from '@/utils/art'
import { returnToHallKeepingSeat } from '@/services/roomResume'

/** 与 landlord 一样直接绑同一份 reactive，避免经 store 代理丢更新 */
const match = niuState
const ownerName = computed(
  () => roomOwnerName() || match.players.find((p) => p.isMaster)?.nickname || '—',
)
const tableBg = assetUrl('images/niu/table.jpg')
const btnOrangeLong = art('ui', 'dizhu', 'btn_orange_long.png')
const btnGreenLong = art('ui', 'dizhu', 'btn_green_long.png')
const niuBtnOrangeBg = `url("${niuUi.btnOrange}") center / 100% 100% no-repeat`
const niuBtnBlueBg = `url("${niuUi.btnBlue}") center / 100% 100% no-repeat`
const leaveOpen = ref(false)
const dismissOpen = ref(false)
const ruleOpen = ref(false)
const tipOpen = ref(false)
const settingsOpen = ref(false)
const musicOn = ref(!audioManager.isBgmMuted())
const soundOn = ref(!audioManager.isMuted())
const pixiHost = ref<HTMLElement | null>(null)
const betOpts = BET_MULTIPLES
const grabScoreOpts = GRAB_MULTIPLES.filter((n) => n > 0)
const niuPlayNotes = niuCreateRoomRules.playNotes
/** 自己手牌中心 y=500*(720/750)，牌高 107 且锚点居中；叫分排在牌下沿 */
const grabRowTop = Math.round(500 * (DESIGN_H / 750) + 107 / 2 + 14)
/** 自由抢庄一排倍数：不抢旁依次 ×1 ×2 ×3 */
const freeGrabMuls = Object.freeze([1, 2, 3])

function onGrab(n: number): void {
  void niuSession.sendGrab(n)
}

function grabTipSrc(n: number): string {
  return niuGrabTipSrc(n)
}

/** 模板用：结算飘分数字切图 */
function scoreDigits(value: number): string[] {
  return scoreDigitSrcs(Number(value) || 0)
}
let alive = false
let pixiDestroy: (() => void) | null = null
let table: PokerSeatTable | null = null
let offStart: (() => void) | null = null
let offSettle: (() => void) | null = null

const uiPhase = computed(() => {
  const p = String(match.phase).toLowerCase()
  // 即便 phase 被迟到包打成 wait，只要还在叫分轮次，仍按 grab 渲染
  if (p === PHASE.GRAB || (match.inRound && match.currentGrabId > 0 && p === PHASE.WAIT)) return 'grab'
  if (p === PHASE.BET) return 'bet'
  if (p === PHASE.SHOW) return 'show'
  if (p === PHASE.SETTLE) return 'settle'
  return 'wait'
})

const me = computed(() => match.players.find((p) => Number(p.id) === Number(match.myUid)))
const amBanker = computed(() => Number(match.bankerId) > 0 && Number(match.bankerId) === Number(match.myUid))
/** 自由抢庄：发完牌全员同时可抢；轮流模式才看 currentGrabId */
const isFreeGrab = computed(() => String(match.modeKey || 'free') === 'free')
const isMyGrab = computed(() => {
  if (uiPhase.value !== 'grab') return false
  if ((me.value?.qz ?? -1) >= 0) return false
  if (Number(match.myUid) <= 0) return false
  if (isFreeGrab.value) return true
  return Number(match.currentGrabId) === Number(match.myUid)
})
const grabWaitText = computed(() => {
  if ((me.value?.qz ?? -1) >= 0) return '已叫分，等待其他玩家'
  if (isFreeGrab.value) return '等待其他玩家抢庄'
  const cur = match.players.find((p) => Number(p.id) === Number(match.currentGrabId))
  return cur?.nickname ? `等待 ${cur.nickname} 抢庄` : '等待其他玩家抢庄'
})
const roomFull = computed(() => match.players.length >= Math.max(2, match.seats) || match.canStart)
/** 仅未开局等待时显示准备；叫分中绝不显示 */
const showReadyBtn = computed(
  () =>
    uiPhase.value === 'wait' &&
    !match.inRound &&
    !match.selfReady &&
    roomFull.value &&
    Number(match.currentGrabId) <= 0,
)
const needVote = computed(() => needMyNiuDismissVote())
const selfIsOwner = computed(() => {
  if (gameState.room.isMaster) return true
  return !!me.value?.isMaster
})
const inRound = computed(
  () => uiPhase.value === 'grab' || uiPhase.value === 'bet' || uiPhase.value === 'show' || match.inRound,
)
const dismissIdle = computed(() => !roomHasStarted() && !inRound.value)
const showDismiss = computed(() =>
  showDismissEntry({
    dismissOpen: match.dismissOpen,
    idle: dismissIdle.value,
    isOwner: selfIsOwner.value,
    roomOver: match.roomOver,
  }),
)
const selfDismissTip = computed(() => dismissTipSrc(match, match.myIndex))
const myBadge = computed(() => (me.value?.shown ? niuBadgeUrl(me.value.niuType) : ''))
const myGrabTip = computed(() => grabTipSrc(me.value?.qz ?? -1))
const myNiuBei = computed(() => {
  if (!me.value?.shown) return ''
  const m = Math.max(1, Number(me.value.niuMultiple) || 0)
  return niuBeiSrc(m)
})
/** 自由抢庄用桌心闹钟；轮流抢庄闹钟跟当前人头像 */
const showCenterClock = computed(
  () =>
    !match.dismissOpen &&
    match.countdown > 0 &&
    !match.dealing &&
    !(uiPhase.value === 'grab' && !isFreeGrab.value && Number(match.currentGrabId) > 0),
)

const resultRows = computed((): NiuResultRow[] => {
  const list = match.players.filter((p) => Number(p.id) > 0)
  let bigId = 0
  let bigDelta = -Infinity
  for (const p of list) {
    if (p.lastDelta > bigDelta) {
      bigDelta = p.lastDelta
      bigId = p.id
    }
  }
  return list
    .slice()
    .sort((a, b) => b.lastDelta - a.lastDelta)
    .map((p) => ({
      id: p.id,
      nickname: p.nickname || `玩家${p.seat + 1}`,
      avatar: p.avatar,
      delta: p.lastDelta,
      niuName: p.niuLabel || '',
      badge: p.shown || p.niuType > 0 ? niuBadgeUrl(p.niuType) : '',
      isSelf: Number(p.id) === Number(match.myUid),
      isBanker: Number(p.id) === Number(match.bankerId),
      isBigWinner: bigDelta > 0 && p.id === bigId,
      platformFee: Number(p.platformFee) || 0,
    }))
})

function onSettleAgain(): void {
  match.resultOpen = false
  void niuSession.sendReady()
}

function onSettleHall(): void {
  match.resultOpen = false
  leaveOpen.value = true
}

function relOf(seat: number): number {
  const n = Math.max(match.seats, 1)
  return ((seat - match.myIndex) % n + n) % n
}

/** 他人相对座：禁止 `rel || 1`（rel=0 是自己，会把自己当成对面） */
function otherRel(seat: number): number {
  const r = relOf(seat)
  return r === 0 ? 1 : r
}

function seatRel(seat: number, id: number): number {
  if (id > 0 && id === match.myUid) return 0
  return otherRel(seat)
}

const selfRollActive = computed(
  () => match.rollHighlightId > 0 && match.rollHighlightId === match.myUid,
)
const selfRollCandidate = computed(
  () =>
    Array.isArray(match.rollCandidates) &&
    match.rollCandidates.some((id) => Number(id) === Number(match.myUid)),
)

const otherChips = computed(() => {
  const uid = Number(match.myUid) || 0
  const others = match.players.filter((p) => Number(p.id) !== uid)
  return others.map((p) => {
    const rel = otherRel(p.seat)
    const slot = ringSlot(rel, Math.max(match.players.length, 2))
    const rollActive = match.rollHighlightId === p.id
    const active =
      (!isFreeGrab.value && uiPhase.value === 'grab' && match.currentGrabId === p.id) || rollActive
    return {
      id: p.id,
      seat: p.seat,
      name: p.nickname || `座位${p.seat + 1}`,
      short: (p.nickname || '玩').slice(0, 1),
      avatar: seatAvatarSrc(p.avatar, p.id),
      gold: p.gold,
      ready: p.ready,
      banker: match.bankerId === p.id,
      winFrame: uiPhase.value === 'settle' && p.lastDelta > 0,
      qz: p.qz,
      bet: p.bet,
      shown: p.shown,
      label: p.niuLabel,
      badge: p.shown ? niuBadgeUrl(p.niuType) : '',
      niuMultiple: p.niuMultiple,
      bei: p.shown ? niuBeiSrc(Math.max(1, Number(p.niuMultiple) || 0)) : '',
      delta: p.lastDelta,
      active,
      rollActive,
      clockLeft: slot.x >= DESIGN_W / 2,
      style: slotStyle(slot),
      dismissTip: dismissTipSrc(match, p.seat),
    }
  })
})

function drawModel() {
  const total = Math.max(match.players.length, 2)
  const seats = match.players.map((p) => ({
    id: p.id,
    rel: seatRel(p.seat, p.id),
    cards: uiPhase.value === 'wait' ? [] : p.cards,
    highlight:
      match.rollHighlightId === p.id ||
      (!isFreeGrab.value && uiPhase.value === 'grab' && match.currentGrabId === p.id),
  }))
  table?.render({ seats, total, dealing: match.dealing })
}

function openSettings(): void {
  musicOn.value = !audioManager.isBgmMuted()
  soundOn.value = !audioManager.isMuted()
  settingsOpen.value = true
}

function toggleMusic(): void {
  const muted = audioManager.toggleBgmMuted()
  musicOn.value = !muted
  if (!muted) playNiuBgm()
}

function toggleSound(): void {
  const muted = audioManager.toggleMuted()
  soundOn.value = !muted
  if (!muted) audioManager.playEffect('click')
}

function onBack(): void {
  leaveOpen.value = true
}

const backTitle = computed(() => '返回大厅')
const backTip = computed(
  () => '返回大厅后游戏将自动托管，座位会保留。中途解散请点解散并全员投票同意。确定返回吗？',
)

async function confirmLeave(): Promise<void> {
  leaveOpen.value = false
  if (inRound.value) {
    await niuSession.enableTrust()
  }
  returnToHallKeepingSeat()
}

function onDismiss(): void {
  if (match.dismissOpen) return
  if (dismissIdle.value && !selfIsOwner.value) {
    match.tip = DISMISS_OWNER_ONLY
    return
  }
  dismissOpen.value = true
}

function confirmDismiss(): void {
  dismissOpen.value = false
  void niuSession.requestDismiss().catch((err) => {
    match.tip = err instanceof Error ? err.message : '解散失败'
  })
}

let lastPulse = 0

const tableSnapshot = computed(() => ({
  seats: match.players,
  hand: match.players.find((p) => p.id === match.myUid)?.cards ?? [],
  dealing: match.dealing,
  highlightId: match.rollHighlightId,
  grabId: match.currentGrabId,
  cardsKey: match.players
    .map((p) => `${p.id}:${p.cards.join(',')}:${p.shown}:${p.looked ? 1 : 0}`)
    .join('|'),
  countdown: match.countdown,
  phase: match.phase,
}))

watch(
  tableSnapshot,
  () => {
    drawModel()
    const id = match.rollHighlightId
    if (id && id !== lastPulse) {
      lastPulse = id
      const p = match.players.find((x) => x.id === id)
      if (p) table?.pulseSeat(seatRel(p.seat, p.id))
    }
    if (!id) lastPulse = 0
  },
  { deep: true },
)

onMounted(async () => {
  alive = true
    offStart = eventBus.on(NiuEvents.START, () => {
    drawModel()
    table?.playDeal()
  })
  offSettle = eventBus.on(NiuEvents.SETTLE, () => {
    table?.playSettle(
      match.players.map((p) => ({
        rel: seatRel(p.seat, p.id),
        delta: p.lastDelta,
      })),
    )
  })
  await niuSession.enter()
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
    console.warn('[NiuRoomView] pixi', err)
  }
})

onBeforeUnmount(() => {
  alive = false
  offStart?.()
  offSettle?.()
  offStart = null
  offSettle = null
  pixiDestroy?.()
  pixiDestroy = null
  table = null
})

onUnmounted(() => {
  void niuSession.leave({ keepSeat: true })
})
</script>

<style scoped>
/* 他人牛几：贴桌面内侧，仅开牌/结算阶段展示 */
.seat-niu {
  top: calc(100% + 52px);
  display: flex;
  align-items: center;
}

.niu-tip {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
}
.niu-tip__badge {
  height: 48px;
  width: auto;
  max-width: 140px;
  object-fit: contain;
  object-position: center;
  flex-shrink: 0;
  filter: drop-shadow(0 2px 3px rgba(0, 0, 0, 0.45));
}
.niu-tip__badge--seat {
  height: 32px;
  max-width: 96px;
}
.niu-tip__bei {
  height: 26px;
  width: auto;
  margin-left: 14px;
  object-fit: contain;
  flex-shrink: 0;
  filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.45));
}
.niu-tip__bei--seat {
  height: 18px;
  margin-left: 10px;
}

/* 结算飘分：points 数字切图 */
.niu-score {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 1px;
  filter: drop-shadow(0 2px 3px rgba(0, 0, 0, 0.5));
}
.niu-score--lose {
  filter: drop-shadow(0 2px 3px rgba(0, 0, 0, 0.5)) grayscale(0.35) brightness(1.25);
}
.niu-score__digit {
  height: 22px;
  width: auto;
  object-fit: contain;
  flex-shrink: 0;
}
.niu-score__digit--lg {
  height: 36px;
}
.niu-score__sign {
  position: relative;
  flex-shrink: 0;
  width: 14px;
  height: 22px;
  margin-right: 2px;
}
.niu-score__sign--lg {
  width: 22px;
  height: 36px;
  margin-right: 4px;
}
.niu-score__sign.is-plus::before,
.niu-score__sign.is-plus::after,
.niu-score__sign.is-minus::before {
  content: '';
  position: absolute;
  left: 50%;
  top: 50%;
  background: linear-gradient(180deg, #fff6c8 0%, #f0c040 55%, #c88820 100%);
  border-radius: 2px;
  box-shadow: 0 1px 0 #5a2a00;
  transform: translate(-50%, -50%);
}
.niu-score__sign.is-minus::before {
  width: 12px;
  height: 3px;
}
.niu-score__sign--lg.is-minus::before {
  width: 18px;
  height: 4px;
}
.niu-score__sign.is-plus::before {
  width: 12px;
  height: 3px;
}
.niu-score__sign.is-plus::after {
  width: 3px;
  height: 12px;
}
.niu-score__sign--lg.is-plus::before {
  width: 18px;
  height: 4px;
}
.niu-score__sign--lg.is-plus::after {
  width: 4px;
  height: 18px;
}

.gf-btn-orange,
.gf-btn-green {
  border: 0;
  background-color: transparent;
  background-repeat: no-repeat;
  background-position: center;
  background-size: 100% 100%;
}
.niu-ops__ready {
  border: 0;
  padding: 0;
  background: transparent;
  height: 86px;
  cursor: pointer;
}
.niu-ops__ready img {
  height: 86px;
  width: auto;
}
.niu-ops__tip {
  border: 0;
  padding: 0;
  background: transparent;
  height: 76px;
  cursor: pointer;
  flex-shrink: 0;
}
.niu-ops__tip img {
  display: block;
  height: 76px;
  width: auto;
  object-fit: contain;
}
.niu-ops__btn {
  position: relative;
  display: inline-block;
  height: 76px;
  width: auto;
  border: 0;
  padding: 0;
  background: transparent;
  cursor: pointer;
  flex-shrink: 0;
}
.niu-ops__btn--tip {
  cursor: default;
  pointer-events: none;
}
.niu-ops__btn > img:first-child {
  display: block;
  height: 76px;
  width: auto;
  object-fit: contain;
}
.niu-ops__label {
  position: absolute !important;
  left: 50%;
  top: 50%;
  width: 55% !important;
  height: auto !important;
  transform: translate(-50%, -50%);
  pointer-events: none;
}
.niu-ops__label--bei {
  width: 42% !important;
  max-height: 40px;
  object-fit: contain;
}
.niu-ops__txt {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  color: #fff;
  font-size: 24px;
  font-weight: 800;
  text-shadow: 0 1px 2px #000;
  pointer-events: none;
  white-space: nowrap;
}
.niu-ops__coin {
  min-width: 112px;
  height: 68px;
  padding: 0 14px;
  border: 0;
  color: #5a2a00;
  font-size: 20px;
  font-weight: 800;
  cursor: pointer;
  background: v-bind(niuBtnOrangeBg);
}
.niu-ops__coin--blue {
  color: #fff;
  background: v-bind(niuBtnBlueBg);
}
.gf-ops-btn {
  height: 76px;
  width: calc(76px * 222 / 93);
  border: 0;
  color: #fff;
  font-size: 24px;
  font-weight: 800;
  letter-spacing: 0.06em;
  cursor: pointer;
  padding: 0;
  background-repeat: no-repeat;
  background-position: center;
  background-size: 100% 100%;
}
.gf-ops-btn--sm {
  height: 68px;
  width: calc(68px * 222 / 93);
  font-size: 20px;
  letter-spacing: 0.02em;
}
.gf-ops-btn--art {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 76px;
  width: calc(76px * 182 / 93);
  padding: 0;
}
.gf-ops-btn__art {
  height: 34px;
  width: auto;
  object-fit: contain;
  pointer-events: none;
  filter: drop-shadow(0 1px 1px rgba(0, 0, 0, 0.45));
}
.gf-ops-btn__art--bei {
  height: 32px;
}
.gf-ops-btn--orange {
  text-shadow: 0 2px 0 rgba(80, 40, 0, 0.45);
  background: v-bind(niuBtnOrangeBg);
}
.gf-ops-btn--art.gf-ops-btn--orange {
  background: v-bind(niuBtnOrangeBg);
}
.gf-ops-btn--green {
  text-shadow: 0 2px 0 rgba(20, 60, 0, 0.35);
  background: v-bind(niuBtnBlueBg);
}
.gf-ops-btn--art.gf-ops-btn--green {
  background: v-bind(niuBtnBlueBg);
}

.niu-ops__wait {
  min-width: 200px;
  height: 60px;
  line-height: 60px;
  text-align: center;
  color: #ffe08a;
  font-size: 16px;
  font-weight: 700;
  text-shadow: 0 1px 2px #000;
  background: rgba(0, 0, 0, 0.45);
  border-radius: 8px;
  padding: 0 16px;
}

/* 抢庄轮次：当前玩家头像高亮 + 旁侧闹钟 */
.seat-turn {
  animation: seat-turn-pulse 1s ease-in-out infinite;
}
@keyframes seat-turn-pulse {
  0%,
  100% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.06);
  }
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
  left: calc(100% + 0.8rem);
  right: auto;
}
.seat-clock--left {
  right: calc(100% + 0.8rem);
  left: auto;
}
</style>
