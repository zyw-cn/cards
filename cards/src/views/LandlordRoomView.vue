<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { PHASE, TIMEOUT } from '@/logic/landlord/rules/room'
import { findHint } from '@/logic/table/cardRules'
import { currentTableFeatures } from '@/logic/table/playMode'
import { indexToSide } from '@/logic/shared/roomSeats'
import { ensureOpponentCardsLeft } from '@/logic/landlord/LandlordCallback'
import { sortCards } from '@/logic/shared/card'
import { cardPoint } from '@/logic/shared/serverCard'
import {
  restartCountdown,
  shortenPlayCountdownIfNeeded,
  syncPlayCountdownForce,
} from '@/logic/landlord/playCountdown'
import { DealFx } from '@/pixi/dealFx'
import { PatternFx } from '@/pixi/patternFx'
import { HAND_BOTTOM_GAP, HAND_CARD_H, mountLandlordPixi } from '@/pixi/landlordTable'
import { audioManager } from '@/services/audioManager'
import { gameState, roomHasStarted, roomOwnerName } from '@/services/gameState'
import { seatAvatarSrc } from '@/services/seatAvatar'
import { showPlayerDetail } from '@/services/playerDetail'
import { landlordSession } from '@/services/landlordSession'
import {
  flashHandTip,
  landlordState,
  needMyDismissVote,
  needMyDouble,
} from '@/services/landlordState'
import {
  isRoomGoneMessage,
  returnToHallKeepingSeat,
} from '@/services/roomResume'
import { formatGold, session } from '@/services/session'
import { fetchMyRoomCards } from '@/services/roomCardApi'
import { playDdzTableBgm } from '@/logic/shared/ddzTableAudio'
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
import { runCoinFly } from '@/logic/pdk/coinFlyDom'
import { GameType, findHallGame } from '@/config/gameTypes'
import { landlordRules, laiziRules, pdkRules } from '@/config/createRules'
import { assetUrl } from '@/services/assetUrl'
import { art } from '@/utils/art'
import RoomNumberDigits from '@/components/RoomNumberDigits.vue'
import ModalShell from '@/components/ModalShell.vue'
import AppDialog from '@/components/AppDialog.vue'
import ChatQuickPanel from '@/components/ChatQuickPanel.vue'
import ChatSeatBubble from '@/components/ChatSeatBubble.vue'
import SettleResultView from '@/views/SettleResultView.vue'

const fzbpTip = tipImgs.fzbp
const tipChuPaiBuFuHe = tipImgs.chuPaiBuFuHe
const tipDaBuGuoShangJia = tipImgs.daBuGuoShangJia

const pixiHost = ref(null)
const fxHost = ref(null)
const loadError = ref('')
const statusText = ref('')
const pixiFailed = ref(false)
const settingsOpen = ref(false)
const showChat = ref(false)
const ownerDismissOpen = ref(false)
const backOpen = ref(false)
const showRule = ref(false)
const musicOn = ref(!audioManager.isBgmMuted())
const soundOn = ref(!audioManager.isMuted())

const match = landlordState
const tableFeat = computed(() => currentTableFeatures())
const isPdkTable = computed(() => Number(gameState.room.gameType) === GameType.PAODEKUAI)
const dealFx = new DealFx()
const patternFx = new PatternFx()
let cardTable = null
let pixiDestroy = null
let tableAlive = false
let noticeTimer = null
/** 久置后台时浏览器会丢掉 WebGL，房间还在，牌却画不出来 */
let handCtxLost = false
let fxCtxLost = false
let restoringSurfaces = null
/** 窗口最小化 / 切走过后，回来必须重画手牌 */
let tableAway = false

const dizhu = (...parts) => art('ui', 'dizhu', ...parts)
const cardBackSrc = assetUrl('images/common/card_back.png')
const btnOrangeLong = art('ui', 'dizhu', 'btn_orange_long.png')
const btnGreenLong = art('ui', 'dizhu', 'btn_green_long.png')

const roomId = computed(() => gameState.room.roomId || '------')

const roundText = computed(() => {
  const total = Number(gameState.room.zjs) || 0
  let now = Number(gameState.room.js)
  if (!Number.isFinite(now) || now < 1) now = 1
  if (total > 0 && now > total) now = total
  return total > 0 ? `${now}/${total}` : `${now}`
})
const nickname = computed(() => session.user.nickname || '玩家')
const cardsText = computed(() => {
  const n = Number(session.user.cards)
  return Number.isFinite(n) && n >= 0 ? String(Math.floor(n)) : '0'
})
const avatarSrc = computed(
  () => session.user.avatar || art('hall', 'user_bar_default_avatar.png'),
)

const leftSeat = computed(() => match.seats.left)
const rightSeat = computed(() => match.seats.right)

const clockSec = computed(() => {
  const n = Number(match.countdown || 0)
  return Number.isFinite(n) ? Math.max(0, Math.floor(n)) : 0
})

const uiPhase = computed(() => {
  const p = String(match.phase)
  if (p === PHASE.WAIT_READY || p === 'wait' || p === 'waitready') return 'wait'
  if (p === PHASE.BID || p === PHASE.GRAB || p === 'bid' || p === 'grab') return 'bid'
  if (p === PHASE.DOUBLE || p === 'double') return 'double'
  if (p === PHASE.PLAY || p === 'play') return 'play'
  if (p === PHASE.SETTLE || p === 'end' || p === 'settle') return 'end'
  return 'wait'
})

/** 斗地主 / 癞子：底栏倍数 = 公共倍数（叫抢 × 2^炸弹 × 春天）× 自己是否加倍 */
const showCornerMultiple = computed(
  () => tableFeat.value.hasLandlordRole && uiPhase.value !== 'wait',
)
const cornerMultiple = computed(() => {
  const pub = Math.max(1, Number(match.multiple) || 1)
  const map = match.farmerDoubles || {}
  const raw = map[String(match.myIndex)] ?? map[match.myIndex]
  const self = raw != null && Number(raw) >= 2 ? 2 : 1
  return pub * self
})

/** 跑得快：底栏中间显示自己剩余张数 */
const showSelfCardsLeft = computed(
  () => tableFeat.value.kind === 'pdk' && uiPhase.value === 'play',
)
const selfCardsLeft = computed(() => match.hand.length)

const dismissIdle = computed(() => uiPhase.value === 'wait' && !roomHasStarted())
const showDismissBtn = computed(() =>
  showDismissEntry({
    dismissOpen: match.dismissOpen,
    idle: dismissIdle.value,
    isOwner: !!gameState.room.isMaster,
  }),
)

const ruleView = computed(() => {
  const room = gameState.room
  const gt = Number(room.gameType || GameType.LANDLORD)
  const isPdk = gt === GameType.PAODEKUAI
  const isLaizi = gt === GameType.LAIZI_LANDLORD
  const catalog = isPdk ? pdkRules : isLaizi ? laiziRules : landlordRules
  const pay =
    catalog.payModes.find((x) => Number(x.value) === Number(room.payMode)) ||
    catalog.payModes[0]
  const modeName =
    room.playName || findHallGame(gt)?.name || (isPdk ? '跑得快' : isLaizi ? '癞子斗地主' : '斗地主')
  const isRoomCard = String(room.payModeText || '') === '房卡' || Number(room.payAmount) === 0
  return {
    ownerName: roomOwnerName() || '—',
    modeName,
    payModeText: isRoomCard ? '房卡' : room.payModeText || pay?.label || '房卡',
    payModeDesc: isRoomCard ? '创建只扣房卡，对局不另收费' : room.payModeDesc || pay?.desc || '',
    df: room.df || 1,
    askModeText: room.askModeText || '抢庄地主',
    bombMultiple: room.bombMultiple || 10,
    seats: room.seats || 3,
    roomId: room.roomId || '------',
    zjs: room.zjs || 6,
    played: room.playedDone != null && Number.isFinite(Number(room.playedDone))
      ? Number(room.playedDone)
      : Number(room.js) || 0,
    showAskMode: !isPdk,
    showBomb: isPdk,
    tip: isRoomCard
      ? `创建只扣房卡；未开局解散自动返还；${DISMISS_RULE_TIP}`
      : catalog.idleTip || `创建只扣房卡；${DISMISS_RULE_TIP}`,
  }
})

const showDismissVoteBtns = computed(() => needMyDismissVote())

const showLeftClock = computed(() => seatShowsClock('left'))
const showRightClock = computed(() => seatShowsClock('right'))
/** 准备、等人开局时还没发牌，不显示对家剩余张数 */
const showOppCards = computed(() => uiPhase.value === 'play' || match.dealing)

function seatShowsClock(side) {
  if (match.dealing || clockSec.value <= 0) return false
  if (uiPhase.value === 'bid') {
    if (match.bidTurn == null || Number(match.bidTurn) < 0) return false
    return indexToSide(match.myIndex, Number(match.bidTurn)) === side
  }
  if (uiPhase.value === 'play') {
    if (match.turnIndex == null || Number(match.turnIndex) < 0) return false
    return indexToSide(match.myIndex, Number(match.turnIndex)) === side
  }
  return false
}

function isActiveTurnSide(side) {
  if (match.dealing) return false
  if (uiPhase.value === 'bid') {
    if (match.bidTurn == null || Number(match.bidTurn) < 0) return false
    return indexToSide(match.myIndex, Number(match.bidTurn)) === side
  }
  if (uiPhase.value === 'play') {
    if (match.turnIndex == null || Number(match.turnIndex) < 0) return false
    return indexToSide(match.myIndex, Number(match.turnIndex)) === side
  }
  return false
}

/** 出牌阶段对家剩 1/2 张：头像旁报警（自己不显示） */
function seatIsAlarm(side) {
  if (side === 'self') return false
  if (uiPhase.value !== 'play' || match.dealing) return false
  const n = Number(match.seats[side]?.cardsLeft)
  return n === 1 || n === 2
}

function cardsLeftAlarmClass(n) {
  if (Number(n) === 1) return 'cards-left--alarm-one'
  if (Number(n) === 2) return 'cards-left--alarm-two'
  return ''
}

/** 手牌垂直中线距舞台底边，与 landlordTable 牌高一致 */
const HAND_TIP_CENTER = HAND_BOTTOM_GAP + HAND_CARD_H / 2
/** 选牌上抬约 36，操作钮底边要在手牌顶之上，留出空隙 */
const OPS_BOTTOM = HAND_BOTTOM_GAP + HAND_CARD_H + 56


const isMyBidTurn = computed(
  () => match.bidTurn != null && Number(match.bidTurn) === Number(match.myIndex),
)
const isMyPlayTurn = computed(
  () => match.turnIndex != null && Number(match.turnIndex) === Number(match.myIndex),
)

const showOpsBar = computed(() => {
  if (uiPhase.value === 'end' || match.dealing || needMyDismissVote()) return false
  if (match.tipReveal === 'bid' || match.tipReveal === 'double' || match.opsTipHold) return false
  if (match.trusted && uiPhase.value === 'play') return false
  if (uiPhase.value === 'wait') return match.canStart && !match.selfReady
  if (uiPhase.value === 'bid') return isMyBidTurn.value
  if (uiPhase.value === 'double') return needMyDouble() && !match.doubleOpsLocked
  if (uiPhase.value === 'play') return isMyPlayTurn.value
  return false
})

const inRobPhase = computed(() => match.hasCaller || match.maxBid > 0)
const bidTakeImg = computed(() =>
  inRobPhase.value ? dizhu('btn_grab_landlord.png') : dizhu('btn_call_landlord.png'),
)
const bidPassImg = computed(() =>
  inRobPhase.value ? dizhu('btn_no_grab.png') : dizhu('btn_no_grab.png'),
)

const mustFollow = computed(() => {
  const abs = Number(match.lastPlayedByAbs)
  if (match.lastPlayed.length > 0 && Number.isFinite(abs) && abs >= 0) {
    return abs !== Number(match.myIndex)
  }
  return match.lastPlayed.length > 0 && match.lastPlayedBy !== '' && match.lastPlayedBy !== 'self'
})
const canBeatOpponent = computed(() => {
  if (!mustFollow.value) return true
  return findHint(match.hand, match.lastPlayed).length > 0
})
const showPassBtn = computed(() => {
  if (!mustFollow.value) return false
  if (tableFeat.value.forceBeat && canBeatOpponent.value) return false
  return true
})
const showHintBtn = computed(() => mustFollow.value && canBeatOpponent.value)

const ACTION_TIP_MAP = {
  call: tipImgs.call,
  rob: tipImgs.rob,
  noBid: tipImgs.noBid,
  noRob: tipImgs.noRob,
  double: tipImgs.double,
  noDouble: tipImgs.noDouble,
  pass: tipImgs.pass,
}

function dismissTipSrc(absSeat) {
  return sharedDismissTipSrc(match, absSeat)
}

const selfTipImg = computed(() => {
  const d = dismissTipSrc(match.myIndex)
  if (d) return d
  if (uiPhase.value === 'wait' && match.selfReady) return tipImgs.ready
  const b = match.actionBadgeBySide.self
  return ACTION_TIP_MAP[b] || ''
})

const leftTipImg = computed(() => {
  const d = dismissTipSrc(leftSeat.value.seatIndex)
  if (d) return d
  if (uiPhase.value === 'wait' && leftSeat.value.isReady) return tipImgs.ready
  return tipForBadge(match.actionBadgeBySide.left, match.passBadgeBySide.left)
})
const rightTipImg = computed(() => {
  const d = dismissTipSrc(rightSeat.value.seatIndex)
  if (d) return d
  if (uiPhase.value === 'wait' && rightSeat.value.isReady) return tipImgs.ready
  return tipForBadge(match.actionBadgeBySide.right, match.passBadgeBySide.right)
})

function isPlayPass(badge, passed) {
  return uiPhase.value === 'play' && (passed || badge === 'pass')
}

function tipForBadge(badge, passed) {
  if (isPlayPass(badge, passed)) return ''
  if (passed || badge === 'pass') return tipImgs.pass
  return ACTION_TIP_MAP[badge] || ''
}

/** 与 landlordTable 出牌区同一落点：牌行中心 */
const PLAY_PASS_POS = {
  self: { left: '50%', top: '32%' },
  left: { left: '34%', top: '40%' },
  right: { left: '66%', top: '40%' },
}

function playPassSrc(side) {
  if (uiPhase.value !== 'play') return ''
  if (match.passBadgeBySide[side] || match.actionBadgeBySide[side] === 'pass') {
    return tipImgs.pass
  }
  return ''
}

const playPassTips = computed(() =>
  ['left', 'right', 'self']
    .map((side) => ({ side, src: playPassSrc(side), style: PLAY_PASS_POS[side] }))
    .filter((item) => item.src),
)

/** 中央 tip：三家不叫+重发合并为 cxfp；兼容旧 key */
const CENTER_TIP_MAP = {
  cxfp: tipImgs.cxfp,
  sanJiaBuJiao: tipImgs.cxfp,
  chongXinFaPai: tipImgs.cxfp,
}
const centerTipSrc = computed(() => {
  const key = String(match.centerTip || '')
  return CENTER_TIP_MAP[key] || ''
})
const tipTuoguan = tipImgs.tuoguan

const HAND_TIP_MAP = {
  chuPaiBuFuHe: tipChuPaiBuFuHe,
  daBuGuoShangJia: tipDaBuGuoShangJia,
}
/** 轮到自己且没有能压过上家的牌：提示留在手牌上方，直到这一轮结束 */
const cannotFollow = computed(
  () =>
    uiPhase.value === 'play' &&
    isMyPlayTurn.value &&
    mustFollow.value &&
    !canBeatOpponent.value &&
    !match.dealing &&
    !match.trusted,
)
const handTipSrc = computed(() => {
  if (cannotFollow.value) return tipDaBuGuoShangJia
  return HAND_TIP_MAP[match.handTip] || ''
})

/** 下家剩 1 张，刚选中非最大单牌：手牌正中提示 1 秒后消失 */
const showFangzouTip = ref(false)
let fangzouTipTimer = null

function selectionIsFangzouSingle() {
  if (!isPdkTable.value || uiPhase.value !== 'play' || !isMyPlayTurn.value || match.dealing) return false
  if (Number(match.seats.right?.cardsLeft) !== 1) return false
  const picked = new Set(match.selectedCodes.map((code) => String(code || '').toUpperCase()))
  const selected = match.hand.filter((card) => picked.has(String(card.code || '').toUpperCase()))
  if (selected.length !== 1) return false
  const played = cardPoint(selected[0])
  if (played <= 0) return false
  const maxPoint = match.hand.reduce((max, card) => Math.max(max, cardPoint(card)), 0)
  return played < maxPoint
}

function flashFangzouTip() {
  if (fangzouTipTimer) {
    clearTimeout(fangzouTipTimer)
    fangzouTipTimer = null
  }
  if (!selectionIsFangzouSingle()) {
    showFangzouTip.value = false
    return
  }
  showFangzouTip.value = true
  fangzouTipTimer = window.setTimeout(() => {
    showFangzouTip.value = false
    fangzouTipTimer = null
  }, 1000)
}

let spade3Timer = null

const spade3Side = computed(() => {
  if (!isPdkTable.value || !match.spade3Hold || match.dealing) return ''
  const abs = Number(match.spade3FromAbs)
  if (!Number.isFinite(abs) || abs < 0) return ''
  return indexToSide(match.myIndex, abs)
})

watch(
  () => match.spade3Hold && !match.dealing,
  (show) => {
    if (spade3Timer) {
      clearTimeout(spade3Timer)
      spade3Timer = null
    }
    if (!show) return
    spade3Timer = window.setTimeout(() => {
      match.spade3Hold = false
    }, 3200)
  },
)

const roomRoot = ref(null)
const selfAvatarEl = ref(null)
const leftAvatarEl = ref(null)
const rightAvatarEl = ref(null)
const flyingCoins = ref([])
const coinIcon = art('hall', 'user_bar_coin.png')
let stopCoinFly = null

watch(
  () => match.coinFly && match.coinFly.id,
  (id) => {
    if (!id || !match.coinFly) return
    const spec = match.coinFly
    window.setTimeout(() => {
      if (!tableAlive || match.coinFly?.id !== spec.id) return
      stopCoinFly?.()
      const root = roomRoot.value
      if (!root) return
      stopCoinFly = runCoinFly({
        root,
        anchors: {
          self: selfAvatarEl.value,
          left: leftAvatarEl.value,
          right: rightAvatarEl.value,
        },
        target: spec.target,
        sources: spec.sources,
        onUpdate: (coins) => {
          flyingCoins.value = coins
        },
      })
    }, 520)
  },
)

function seatGoldText(gold) {
  return formatGold(gold || 0)
}

function isLandlordSeat(seatIndex) {
  if (!tableFeat.value.hasLandlordRole) return false
  return match.landlordIndex != null && Number(match.landlordIndex) === Number(seatIndex)
}

watch(
  () => match.statusText,
  (t) => {
    statusText.value = String(t || '')
  },
)

watch(
  () => {
    const must =
      isMyPlayTurn.value &&
      mustFollow.value &&
      !canBeatOpponent.value &&
      uiPhase.value === 'play' &&
      !match.trusted
    return must
  },
  (on) => {
    if (!on) return
    shortenPlayCountdownIfNeeded()
    flashHandTip('daBuGuoShangJia')
  },
)

/** 轮到自己且剩一手能合法出完：稍候自动打出，与斗地主相同 */
watch(
  () =>
    [
      isMyPlayTurn.value,
      uiPhase.value,
      match.dealing,
      match.trusted,
      match.hand.length,
    ].join('|'),
  () => {
    if (!isMyPlayTurn.value || uiPhase.value !== 'play' || match.dealing || match.trusted) return
    if (!match.hand.length) return
    void landlordSession.tryAutoFinishPlay()
  },
)

watch(
  () => match.selectedCodes.map((c) => c).join(','),
  () => {
    cardTable?.refresh()
    flashFangzouTip()
  },
)

const backInRound = computed(() => uiPhase.value === 'bid' || uiPhase.value === 'double' || uiPhase.value === 'play')
const backTitle = computed(() => '返回大厅')
const backTip = computed(
  () => '返回大厅后游戏将自动托管，座位会保留。中途解散请点解散并全员投票同意。确定返回吗？',
)

function backHall() {
  backOpen.value = true
}

async function confirmBack() {
  backOpen.value = false
  if (backInRound.value) {
    await landlordSession.enableTrust()
  }
  returnToHallKeepingSeat()
}

async function onStartGame() {
  await landlordSession.sendReady()
}

async function onTrust() {
  await landlordSession.sendTrust()
}

async function onCancelTrust() {
  await landlordSession.cancelTrust()
}

function openSettings() {
  musicOn.value = !audioManager.isBgmMuted()
  soundOn.value = !audioManager.isMuted()
  settingsOpen.value = true
}

function toggleMusic() {
  const muted = audioManager.toggleBgmMuted()
  musicOn.value = !muted
  if (!muted) {
    try {
      playDdzTableBgm()
    } catch {
      /* bgm 可选 */
    }
  }
}

function toggleSound() {
  const muted = audioManager.toggleMuted()
  soundOn.value = !muted
  if (!muted) audioManager.playEffect('click')
}

function onSendChat(text) {
  void landlordSession.sendChat(text).catch((err) => {
    const msg = err instanceof Error ? err.message : '发送失败'
    if (isRoomGoneMessage(msg)) return
    flashNotice(msg)
  })
}

function flashNotice(msg) {
  statusText.value = String(msg || '')
  if (noticeTimer) clearTimeout(noticeTimer)
  noticeTimer = setTimeout(() => {
    if (statusText.value === msg) statusText.value = ''
  }, 2200)
}

function onDismissClick() {
  if (match.dismissOpen) return
  if (dismissIdle.value && !gameState.room.isMaster) {
    flashNotice(DISMISS_OWNER_ONLY)
    return
  }
  ownerDismissOpen.value = true
}

function confirmOwnerDismiss() {
  ownerDismissOpen.value = false
  void landlordSession.requestDismiss().catch((err) => {
    const msg = err instanceof Error ? err.message : '解散失败'
    if (isRoomGoneMessage(msg)) return
    flashNotice(msg)
  })
}

function onVoteDismiss(vote) {
  void landlordSession.voteDismiss(vote).catch((err) => {
    const msg = err instanceof Error ? err.message : '投票失败'
    if (isRoomGoneMessage(msg)) return
    flashNotice(msg)
  })
}

async function onPass() {
  await landlordSession.sendPass()
}

async function onHint() {
  landlordSession.hint()
  cardTable?.refresh()
}

async function onPlay() {
  await landlordSession.sendPlay()
}

const PATTERN_FX_TYPES = new Set(['bomb', 'rocket', 'plane', 'shunzi', 'liandui'])

async function mountCardSurfaces() {
  if (!tableAlive) return
  if (pixiHost.value && (handCtxLost || !cardTable)) {
    pixiDestroy?.()
    pixiDestroy = null
    cardTable = null
    const mounted = await mountLandlordPixi(pixiHost.value, {
      onContextLost: () => {
        handCtxLost = true
      },
    })
    if (!tableAlive) {
      mounted.destroy()
      return
    }
    pixiDestroy = mounted.destroy
    cardTable = mounted.table
    handCtxLost = false
    cardTable.refresh()
  }
  if (fxHost.value && (fxCtxLost || !patternFx.ready)) {
    dealFx.destroy()
    patternFx.destroy()
    await patternFx.mount(fxHost.value, {
      onContextLost: () => {
        fxCtxLost = true
      },
    })
    if (!tableAlive) return
    await dealFx.mount(fxHost.value, patternFx.application)
    fxCtxLost = false
  }
}

function restoreCardSurfaces() {
  if (!handCtxLost && !fxCtxLost && cardTable && patternFx.ready) {
    cardTable.refresh()
    return Promise.resolve()
  }
  if (restoringSurfaces) return restoringSurfaces
  restoringSurfaces = mountCardSurfaces()
    .catch((e) => {
      pixiFailed.value = true
      console.warn('[landlord] restore pixi', e)
    })
    .finally(() => {
      restoringSurfaces = null
    })
  return restoringSurfaces
}

function nextFrame() {
  return new Promise((resolve) => requestAnimationFrame(() => resolve()))
}

function markTableAway() {
  tableAway = true
  // 动画定时器在后台会停住，dealing 一直为真时手牌层直接不画
  if (match.dealing || dealFx.busy) {
    dealFx.resetTable?.()
    if (match.hand.length) match.hand = sortCards(match.hand)
    match.dealing = false
  }
}

/** 最小化再打开：等画面回来后再按当前手牌重画。窗口还藏着时不画，否则画进失效的 WebGL。 */
async function presentHandAfterWake() {
  if (!tableAlive || !tableAway) return
  if (document.visibilityState === 'hidden') return
  tableAway = false
  dealFx.resetTable?.()
  if (match.hand.length) match.hand = sortCards(match.hand)
  match.dealing = false
  ensureOpponentCardsLeft()
  handCtxLost = true
  fxCtxLost = true
  await restoreCardSurfaces()
  await nextFrame()
  await nextFrame()
  if (!tableAlive) return
  if (document.visibilityState === 'hidden') {
    tableAway = true
    return
  }
  match.dealing = false
  cardTable?.refresh()
}

function roomStillWaiting() {
  const phase = String(match.phase || '').toLowerCase()
  return !phase || phase === 'wait' || phase === 'waitready'
}

/** 最小化可能冻住页面，错过开局包。回来时若还停在准备，向服务器拉一次当前牌局。 */
async function wakeTable() {
  if (!tableAlive || !tableAway) return
  if (document.visibilityState === 'hidden') return
  if (roomStillWaiting() || !match.hand.length) {
    try {
      await landlordSession.resync()
    } catch (err) {
      console.warn('[landlord] wake resync', err)
    }
  }
  const phase = String(match.phase || '').toLowerCase()
  if (phase && phase !== 'wait' && phase !== 'waitready' && phase !== 'end' && phase !== 'settle') {
    match.showSettle = false
  }
  await presentHandAfterWake()
}

function onTableVisible() {
  if (document.visibilityState === 'hidden') {
    markTableAway()
    return
  }
  void wakeTable()
}

function onWindowBlur() {
  markTableAway()
}

function onWindowFocus() {
  void wakeTable()
}

onMounted(async () => {
  tableAlive = true
  void fetchMyRoomCards().catch(() => {})
    await nextTick()

  try {
    await landlordSession.enter()
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : '进房失败'
    console.warn('[landlord] enter', e)
  }
  if (!tableAlive) return

  statusText.value = match.statusText || ''

  handCtxLost = true
  fxCtxLost = true
  try {
    await mountCardSurfaces()
  } catch (e) {
    pixiFailed.value = true
    loadError.value = e instanceof Error ? e.message : '手牌层初始化失败'
    console.warn('[landlord] pixi', e)
  }

  if (!tableAlive) return
  document.addEventListener('visibilitychange', onTableVisible)
  window.addEventListener('blur', onWindowBlur)
  window.addEventListener('focus', onWindowFocus)

  if (!tableAlive) return
  landlordSession.queue?.setHandler(async (action) => {
    if (!tableAlive) return
    if (PATTERN_FX_TYPES.has(action.type)) {
      try {
        await patternFx.play(action.type)
      } catch (err) {
        console.warn('[landlord] patternFx', action.type, err)
      }
      return
    }
    if (action.type === 'settle') {
      const win = !!match.result?.selfWin
      audioManager.playEffect(win ? 'win' : 'lose', 'ddz-sfx')
      match.showSettle = true
      statusText.value = ''
      return
    }
    if (action.type !== 'deal') return
    const payload = action.payload || {}
    const hand = (payload.hand?.length ? payload.hand : match.hand) || []
    if (!hand.length) {
      match.dealing = false
      const phase = String(match.phase).toLowerCase()
      if (phase === PHASE.BID || phase === PHASE.GRAB || phase === 'bid' || phase === 'grab') {
        restartCountdown(tableFeat.value.timeout.BID || TIMEOUT.BID)
      } else if (phase === PHASE.PLAY || phase === 'play') {
        syncPlayCountdownForce()
      }
      return
    }
    // 最小化时动画定时器会停住，dealing 一直为真，回来后手牌被跳过不画
    if (document.visibilityState === 'hidden' || !document.hasFocus()) {
      markTableAway()
      match.hand = sortCards(match.hand.length ? match.hand : hand)
      match.dealing = false
      ensureOpponentCardsLeft()
      const phase = String(match.phase).toLowerCase()
      if (phase === PHASE.BID || phase === PHASE.GRAB || phase === 'bid' || phase === 'grab') {
        restartCountdown(tableFeat.value.timeout.BID || TIMEOUT.BID)
      }
      return
    }
    match.dealing = true
    await restoreCardSurfaces()
    cardTable?.refresh()
    try {
      await dealFx.play(hand)
    } catch (err) {
      console.warn('[landlord] deal', err)
    } finally {
      if (!tableAlive) return
      match.hand = sortCards(match.hand.length ? match.hand : hand)
      // 叫抢若在飞牌过程中结束，动画收尾会把地主座刷回发牌张数，这里再补上底牌
      ensureOpponentCardsLeft()
      match.dealing = false
      cardTable?.refresh()
      const phase = String(match.phase).toLowerCase()
      if (phase === PHASE.BID || phase === PHASE.GRAB || phase === 'bid' || phase === 'grab') {
        restartCountdown(tableFeat.value.timeout.BID || TIMEOUT.BID)
      } else if (phase === PHASE.PLAY || phase === 'play') {
        syncPlayCountdownForce()
      }
    }
  })
})

onUnmounted(() => {
  tableAlive = false
  document.removeEventListener('visibilitychange', onTableVisible)
  window.removeEventListener('blur', onWindowBlur)
  window.removeEventListener('focus', onWindowFocus)
  if (spade3Timer) clearTimeout(spade3Timer)
  if (fangzouTipTimer) clearTimeout(fangzouTipTimer)
  stopCoinFly?.()
  stopCoinFly = null
  if (noticeTimer) clearTimeout(noticeTimer)
  pixiDestroy?.()
  pixiDestroy = null
  cardTable = null
  landlordSession.queue?.setHandler(null)
  dealFx.resetTable?.()
  dealFx.destroy()
  patternFx.destroy()
  // 进房后不自动离座，仅本地收摊；返回大厅已确认托管留座
  void landlordSession.leave({ keepSeat: true })
})
</script>

<template>
  <div
    ref="roomRoot"
    class="relative h-full w-full overflow-hidden text-white"
  >
    <!-- 桌面落在底栏上沿，近端不再盖住头像和金币 -->
    <div class="pointer-events-none absolute inset-x-0 bottom-[72px] top-0 z-[2]">
      <img
        class="table-plate"
        :src="dizhu('table_full.png')"
        width="1680"
        height="596"
        alt=""
        draggable="false"
      />
    </div>

    <div ref="pixiHost" class="absolute inset-0 z-10" />
    <div ref="fxHost" class="pointer-events-none absolute inset-0 z-[48]" />
    <div class="pointer-events-none absolute inset-0 z-[60] overflow-hidden">
      <img
        v-for="c in flyingCoins"
        :key="c.id"
        :src="coinIcon"
        class="absolute h-8 w-8"
        :style="{
          left: `${c.x}px`,
          top: `${c.y}px`,
          opacity: c.opacity,
          transform: `translate(-50%, -50%) scale(${c.scale}) rotate(${c.rot}rad)`,
        }"
        alt=""
      />
    </div>

    <!-- 顶栏 -->
    <header class="pointer-events-none absolute inset-x-0 top-0 z-40 flex h-[84px] items-center justify-between px-3">
      <div class="pointer-events-auto flex items-center gap-2.5">
        <button type="button" class="h-[68px] w-[68px] transition active:scale-95" @click="backHall">
          <img :src="dizhu('icon_back.png')" class="h-full w-full object-contain" alt="返回" />
        </button>
        <button
          v-if="showDismissBtn"
          type="button"
          class="h-[68px] w-[68px] transition active:scale-95"
          @click="onDismissClick"
        >
          <img :src="dizhu('icon_dismiss.png')" class="h-full w-full object-contain" alt="解散" />
        </button>
        <div
          class="flex h-[46px] min-w-[180px] items-center justify-center gap-1.5 bg-[length:100%_100%] px-3"
          :style="{ backgroundImage: `url(${dizhu('room_card_bg.png')})` }"
        >
          <span class="shrink-0 text-[15px] font-bold text-white/90 drop-shadow">房间</span>
          <RoomNumberDigits :value="roomId" variant="glass" digit-class="h-[26px]" />
        </div>
        <div
          class="flex h-[46px] items-center justify-center bg-[length:100%_100%] px-3"
          :style="{ backgroundImage: `url(${dizhu('room_card_bg.png')})` }"
        >
          <span class="shrink-0 text-[15px] font-bold tabular-nums text-[#ffe08a] drop-shadow">{{ roundText }}局</span>
        </div>
      </div>

      <div class="pointer-events-auto flex items-center gap-3 pr-1">
        <button type="button" class="h-[68px] w-[68px] transition active:scale-95" @click="showRule = true">
          <img :src="dizhu('icon_rule.png')" class="h-full w-full object-contain" alt="规则" />
        </button>
        <button
          v-if="uiPhase === 'play' && !match.trusted"
          type="button"
          class="h-[68px] w-[68px] transition active:scale-95"
          @click="onTrust"
        >
          <img :src="dizhu('icon_trusteeship.png')" class="h-full w-full object-contain" alt="托管" />
        </button>
        <button type="button" class="h-[68px] w-[68px] transition active:scale-95" @click="openSettings">
          <img :src="dizhu('icon_settings.png')" class="h-full w-full object-contain" alt="设置" />
        </button>
      </div>
    </header>

    <!-- 左侧上家：仅真实入座才显示 -->
    <div v-if="leftSeat.occupied" class="pointer-events-none absolute left-[8px] top-[188px] z-[70] flex items-start gap-1.5">
      <p v-if="spade3Side === 'left'" class="spade3-broadcast spade3-broadcast--left">持红桃3者先出</p>
      <ChatSeatBubble side="left" :text="match.chatBySide.left" />
      <div class="relative z-30 flex w-[136px] flex-col items-center">
        <button
          ref="leftAvatarEl"
          type="button"
          class="pointer-events-auto relative"
          @click="showPlayerDetail({ uid: leftSeat.uid, nickname: leftSeat.nickName, gold: leftSeat.gold, avatar: leftSeat.avatar })"
        >
          <img
            :src="dizhu('avatar_frame_bg.png')"
            class="h-[108px] w-[108px] object-contain"
            :class="[
              isActiveTurnSide('left') ? 'drop-shadow-[0_0_10px_rgba(255,210,80,0.95)]' : '',
              seatIsAlarm('left') ? 'seat-avatar--alarm' : '',
            ]"
            alt=""
          />
          <img
            :src="seatAvatarSrc(leftSeat.avatar, leftSeat.uid || leftSeat.seatIndex || 2)"
            class="absolute left-1/2 top-1/2 h-[86px] w-[86px] -translate-x-1/2 -translate-y-1/2 rounded-[8px] object-cover"
            :class="[
              isActiveTurnSide('left') ? 'ring-2 ring-[#ffe27a]' : '',
              seatIsAlarm('left') ? 'seat-avatar--alarm' : '',
            ]"
            alt=""
          />
          <img
            v-if="isLandlordSeat(leftSeat.seatIndex)"
            :src="dizhu('landlord_badge.png')"
            class="absolute -right-1 -top-1 h-12 w-12 object-contain drop-shadow"
            alt="地主"
          />
          <img
            v-if="leftSeat.isTrusted"
            :src="tipTuoguan"
            class="pointer-events-none absolute left-1/2 top-1/2 z-10 h-8 w-auto max-w-[96px] -translate-x-1/2 -translate-y-1/2 object-contain drop-shadow"
            alt="托管"
          />
        </button>
        <div
          class="mt-1 flex w-full flex-col items-center bg-[length:100%_100%] px-1 py-1"
          :style="{ backgroundImage: `url(${dizhu('player_info_bg.png')})` }"
        >
          <p class="truncate text-[16px] font-semibold text-white">
            {{ leftSeat.nickName || '玩家' }}
          </p>
          <p class="text-[14px] text-[#ffe9a0]">
            {{ seatGoldText(leftSeat.gold) }}
          </p>
        </div>
        <img
          v-if="leftTipImg"
          :src="leftTipImg"
          class="mt-2 h-9 w-auto max-w-[140px] object-contain drop-shadow"
          alt=""
        />
      </div>

      <!-- 剩牌 + 报警：贴头像内侧，落在桌沿 -->
      <div
        v-if="showOppCards && leftSeat.cardsLeft != null"
        class="relative mt-[18px] h-[72px] w-[56px]"
        :class="cardsLeftAlarmClass(leftSeat.cardsLeft)"
      >
        <img
          v-if="seatIsAlarm('left')"
          :src="dizhu('baojing_tip.png')"
          alt="报警"
          class="seat-alarm-icon seat-alarm-icon--out pointer-events-none absolute bottom-full left-1/2 z-[12] mb-1 h-9 w-9 -translate-x-1/2 object-contain drop-shadow"
          :class="cardsLeftAlarmClass(leftSeat.cardsLeft)"
        />
        <img :src="cardBackSrc" class="h-full w-full object-contain drop-shadow" alt="" />
        <span
          class="cards-left-num absolute inset-0 flex items-center justify-center text-[24px] font-black drop-shadow-[0_1px_2px_rgba(0,0,0,0.85)]"
        >
          {{ leftSeat.cardsLeft }}
        </span>
      </div>

      <!-- 闹钟：再往桌心挪一点 -->
      <div class="mt-[28px] flex w-[72px] flex-col items-center">
        <div class="relative flex h-[72px] w-[72px] items-center justify-center">
          <template v-if="showLeftClock">
            <img :src="dizhu('jsq.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
            <span class="clock-sec text-[22px]">{{ clockSec }}</span>
          </template>
        </div>
      </div>
    </div>

    <!-- 右侧上家：仅真实入座才显示 -->
    <div v-if="rightSeat.occupied" class="pointer-events-none absolute right-[8px] top-[188px] z-[70] flex flex-row-reverse items-start gap-1.5">
      <p v-if="spade3Side === 'right'" class="spade3-broadcast spade3-broadcast--right">持红桃3者先出</p>
      <ChatSeatBubble side="right" :text="match.chatBySide.right" />
      <div class="relative z-30 flex w-[136px] flex-col items-center">
        <button
          ref="rightAvatarEl"
          type="button"
          class="pointer-events-auto relative"
          @click="showPlayerDetail({ uid: rightSeat.uid, nickname: rightSeat.nickName, gold: rightSeat.gold, avatar: rightSeat.avatar })"
        >
          <img
            :src="dizhu('avatar_frame_bg.png')"
            class="h-[108px] w-[108px] object-contain"
            :class="[
              isActiveTurnSide('right') ? 'drop-shadow-[0_0_10px_rgba(255,210,80,0.95)]' : '',
              seatIsAlarm('right') ? 'seat-avatar--alarm' : '',
            ]"
            alt=""
          />
          <img
            :src="seatAvatarSrc(rightSeat.avatar, rightSeat.uid || rightSeat.seatIndex || 1)"
            class="absolute left-1/2 top-1/2 h-[86px] w-[86px] -translate-x-1/2 -translate-y-1/2 rounded-[8px] object-cover"
            :class="[
              isActiveTurnSide('right') ? 'ring-2 ring-[#ffe27a]' : '',
              seatIsAlarm('right') ? 'seat-avatar--alarm' : '',
            ]"
            alt=""
          />
          <img
            v-if="isLandlordSeat(rightSeat.seatIndex)"
            :src="dizhu('landlord_badge.png')"
            class="absolute -left-1 -top-1 h-12 w-12 object-contain drop-shadow"
            alt="地主"
          />
          <img
            v-if="rightSeat.isTrusted"
            :src="tipTuoguan"
            class="pointer-events-none absolute left-1/2 top-1/2 z-10 h-8 w-auto max-w-[96px] -translate-x-1/2 -translate-y-1/2 object-contain drop-shadow"
            alt="托管"
          />
        </button>
        <div
          class="mt-1 flex w-full flex-col items-center bg-[length:100%_100%] px-1 py-1"
          :style="{ backgroundImage: `url(${dizhu('player_info_bg.png')})` }"
        >
          <p class="truncate text-[16px] font-semibold text-white">
            {{ rightSeat.nickName || '玩家' }}
          </p>
          <p class="text-[14px] text-[#ffe9a0]">
            {{ seatGoldText(rightSeat.gold) }}
          </p>
        </div>
        <img
          v-if="rightTipImg"
          :src="rightTipImg"
          class="mt-2 h-9 w-auto max-w-[140px] object-contain drop-shadow"
          alt=""
        />
      </div>

      <div
        v-if="showOppCards && rightSeat.cardsLeft != null"
        class="relative mt-[18px] h-[72px] w-[56px]"
        :class="cardsLeftAlarmClass(rightSeat.cardsLeft)"
      >
        <img
          v-if="seatIsAlarm('right')"
          :src="dizhu('baojing_tip.png')"
          alt="报警"
          class="seat-alarm-icon seat-alarm-icon--out pointer-events-none absolute bottom-full left-1/2 z-[12] mb-1 h-9 w-9 -translate-x-1/2 object-contain drop-shadow"
          :class="cardsLeftAlarmClass(rightSeat.cardsLeft)"
        />
        <img :src="cardBackSrc" class="h-full w-full object-contain drop-shadow" alt="" />
        <span
          class="cards-left-num absolute inset-0 flex items-center justify-center text-[24px] font-black drop-shadow-[0_1px_2px_rgba(0,0,0,0.85)]"
        >
          {{ rightSeat.cardsLeft }}
        </span>
      </div>

      <div class="mt-[28px] flex w-[72px] flex-col items-center">
        <div class="relative flex h-[72px] w-[72px] items-center justify-center">
          <template v-if="showRightClock">
            <img :src="dizhu('jsq.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
            <span class="clock-sec text-[22px]">{{ clockSec }}</span>
          </template>
        </div>
      </div>
    </div>


    <!-- 自己叫抢/加倍/准备 tip；解散 tip 贴自己头像旁，避免与投票按钮叠在中央 -->
    <img
      v-if="selfTipImg && match.dismissOpen"
      :src="selfTipImg"
      class="pointer-events-none absolute bottom-[160px] left-[100px] z-40 h-9 w-auto max-w-[140px] object-contain drop-shadow"
      alt=""
    />
    <img
      v-else-if="selfTipImg && (uiPhase === 'wait' || uiPhase === 'bid' || uiPhase === 'double')"
      :src="selfTipImg"
      class="pointer-events-none absolute bottom-[340px] left-1/2 z-40 h-10 w-auto max-w-[180px] -translate-x-1/2 object-contain drop-shadow"
      alt=""
    />

    <!-- 不出 / 不要：和该座位打出的牌同一位置 -->
    <img
      v-for="tip in playPassTips"
      :key="'pass-' + tip.side"
      :src="tip.src"
      class="pointer-events-none absolute z-[46] h-14 w-auto max-w-[220px] -translate-x-1/2 -translate-y-1/2 object-contain drop-shadow"
      :style="tip.style"
      alt="不出"
    />

    <!-- 中央 tip（三家不叫+重发 cxfp 等） -->
    <img
      v-if="centerTipSrc"
      :src="centerTipSrc"
      class="pointer-events-none absolute left-1/2 top-[300px] z-40 h-14 w-auto max-w-[640px] -translate-x-1/2 object-contain drop-shadow-lg"
      alt=""
    />

    <DismissVoteBar
      :show="match.dismissOpen"
      :show-vote-btns="showDismissVoteBtns"
      :dismiss-from-seat="match.dismissFromSeat"
      :my-index="match.myIndex"
      :dismiss-left="match.dismissLeft"
      @vote="onVoteDismiss"
    />

    <!-- 托管中：取消托管（web-ui 绿色按钮壳） -->
    <button
      v-if="uiPhase === 'play' && match.trusted && !match.dismissOpen"
      type="button"
      class="pointer-events-auto absolute bottom-[100px] left-1/2 z-50 h-[86px] w-[280px] -translate-x-1/2 transition active:scale-95"
      @click="onCancelTrust"
    >
      <img :src="dizhu('btn_green_long.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
      <span
        class="relative z-10 text-[24px] font-extrabold tracking-wide text-white"
        style="text-shadow: 0 2px 0 rgba(20, 60, 0, 0.35)"
      >
        取消托管
      </span>
    </button>

    <!-- 出牌不合规 / 打不过：盖在手牌正中 -->
    <img
      v-if="handTipSrc"
      :src="handTipSrc"
      alt=""
      class="pointer-events-none absolute left-1/2 z-[46] h-8 w-auto max-w-[460px] -translate-x-1/2 translate-y-1/2 object-contain drop-shadow-lg"
      :style="{ bottom: `${HAND_TIP_CENTER}px` }"
    />
    <img
      v-if="showFangzouTip"
      :src="fzbpTip"
      alt="放走包赔"
      class="pointer-events-none absolute left-1/2 z-[47] h-10 w-auto -translate-x-1/2 translate-y-1/2 object-contain drop-shadow-lg"
      :style="{ bottom: `${HAND_TIP_CENTER + (handTipSrc ? 42 : 0)}px` }"
    />

    <!-- 操作钮 -->
    <div
      v-if="showOpsBar"
      class="pointer-events-auto absolute left-1/2 z-40 flex -translate-x-1/2 items-center gap-3"
      :style="{ bottom: `${OPS_BOTTOM}px` }"
    >
      <!-- 等待：开始游戏 -->
      <template v-if="uiPhase === 'wait'">
        <button
          type="button"
          class="relative h-[86px] w-[240px] transition active:scale-95 disabled:opacity-50"
          :disabled="match.starting"
          @click="onStartGame"
        >
          <img :src="dizhu('btn_orange_long.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
          <img
            :src="dizhu('btn_start_game.png')"
            class="relative z-10 mx-auto h-[40px] object-contain"
            alt="开始游戏"
          />
        </button>
      </template>

      <!-- 叫 / 抢（跑得快无叫抢） -->
      <template v-else-if="uiPhase === 'bid' && tableFeat.hasBid">
        <button type="button" class="relative h-[86px] w-[196px] transition active:scale-95" @click="landlordSession.passBid()">
          <img :src="dizhu('btn_orange.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
          <img :src="bidPassImg" class="relative z-10 mx-auto h-[40px] object-contain" alt="不叫/不抢" />
        </button>
        <div class="relative flex h-[86px] w-[86px] items-center justify-center">
          <img :src="dizhu('jsq.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
          <span class="clock-sec text-[24px]">{{ clockSec }}</span>
        </div>
        <button type="button" class="relative h-[86px] w-[196px] transition active:scale-95" @click="landlordSession.callOrRob()">
          <img :src="dizhu('btn_orange.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
          <img :src="bidTakeImg" class="relative z-10 mx-auto h-[40px] object-contain" alt="叫/抢" />
        </button>
      </template>

      <!-- 加倍（跑得快无加倍） -->
      <template v-else-if="uiPhase === 'double' && tableFeat.hasDouble">
        <button type="button" class="relative h-[86px] w-[196px] transition active:scale-95" @click="landlordSession.sendDouble(0)">
          <img :src="dizhu('btn_orange.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
          <img :src="dizhu('btn_no_double.png')" class="relative z-10 mx-auto h-[40px] object-contain" alt="不加倍" />
        </button>
        <div class="relative flex h-[86px] w-[86px] items-center justify-center">
          <img :src="dizhu('jsq.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
          <span class="clock-sec text-[24px]">{{ clockSec }}</span>
        </div>
        <button type="button" class="relative h-[86px] w-[196px] transition active:scale-95" @click="landlordSession.sendDouble(2)">
          <img :src="dizhu('btn_orange.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
          <img :src="dizhu('btn_double.png')" class="relative z-10 mx-auto h-[40px] object-contain" alt="加倍" />
        </button>
      </template>

      <!-- 出牌 -->
      <template v-else-if="uiPhase === 'play'">
        <button
          v-if="showPassBtn"
          type="button"
          class="relative h-[86px] w-[196px] transition active:scale-95"
          @click="onPass"
        >
          <img :src="dizhu('btn_orange.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
          <img :src="dizhu('btn_pass.png')" class="relative z-10 mx-auto h-[40px] object-contain" alt="不出" />
        </button>
        <button
          v-if="showHintBtn"
          type="button"
          class="relative h-[86px] w-[196px] transition active:scale-95"
          @click="onHint"
        >
          <img :src="dizhu('btn_orange.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
          <img :src="dizhu('btn_hint.png')" class="relative z-10 mx-auto h-[40px] object-contain" alt="提示" />
        </button>
        <div class="relative flex h-[86px] w-[86px] items-center justify-center">
          <img :src="dizhu('jsq.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
          <span class="clock-sec text-[24px]">{{ clockSec }}</span>
        </div>
        <button
          v-if="!cannotFollow"
          type="button"
          class="relative h-[86px] w-[196px] transition active:scale-95"
          @click="onPlay"
        >
          <img :src="dizhu('btn_green.png')" class="absolute inset-0 h-full w-full object-contain" alt="" />
          <img :src="dizhu('btn_play.png')" class="relative z-10 mx-auto h-[40px] object-contain" alt="出牌" />
        </button>
      </template>
    </div>

    <!-- 自己：头像贴在圆桌近端，昵称落在底栏，不和金币叠在一起 -->
    <div class="pointer-events-none absolute bottom-[58px] left-2 z-[70] flex w-[116px] flex-col items-center">
      <p v-if="spade3Side === 'self'" class="spade3-broadcast spade3-broadcast--self">持红桃3者先出</p>
      <ChatSeatBubble side="self" :text="match.chatBySide.self" />
      <button
        ref="selfAvatarEl"
        type="button"
        class="pointer-events-auto relative"
        @click="showPlayerDetail({ uid: session.user.uid, nickname, gold: session.user.gold, avatar: session.user.avatar })"
      >
        <img :src="dizhu('avatar_frame_bg.png')" class="h-[96px] w-[96px] object-contain" alt="" />
        <img
          :src="seatAvatarSrc(avatarSrc, session.user.uid)"
          class="absolute left-1/2 top-1/2 h-[76px] w-[76px] -translate-x-1/2 -translate-y-1/2 rounded-[8px] object-cover"
          alt=""
        />
        <img
          v-if="isLandlordSeat(match.myIndex)"
          :src="dizhu('landlord_badge.png')"
          class="absolute -right-2 -top-2 h-8 w-8 object-contain drop-shadow"
          alt="地主"
        />
        <img
          v-if="match.trusted"
          :src="tipTuoguan"
          class="pointer-events-none absolute left-1/2 top-1/2 z-10 h-7 w-auto max-w-[88px] -translate-x-1/2 -translate-y-1/2 object-contain drop-shadow"
          alt="托管"
        />
      </button>
      <div
        class="mt-0.5 flex w-full items-center justify-center bg-[length:100%_100%] px-1.5 py-0.5"
        :style="{ backgroundImage: `url(${dizhu('player_info_bg.png')})` }"
      >
        <p class="truncate text-center text-[16px] font-semibold text-white drop-shadow">{{ nickname }}</p>
      </div>
    </div>

    <!-- 底栏 -->
    <footer class="pointer-events-auto absolute inset-x-0 bottom-0 z-40 h-[72px]">
      <img
        :src="dizhu('coin_glass_frame.png')"
        class="pointer-events-none absolute inset-x-0 bottom-0 h-[72px] w-full object-fill"
        alt=""
      />
      <div class="absolute bottom-[12px] left-[132px] flex items-center">
        <div
          class="relative flex h-[46px] w-[210px] items-center gap-1.5 bg-[length:100%_100%] bg-no-repeat pl-2 pr-9"
          :style="{ backgroundImage: `url(${dizhu('coin_bg.png')})` }"
        >
          <img :src="art('hall', 'user_bar_coin.png')" class="h-9 w-9 shrink-0 object-contain" alt="" />
          <span class="min-w-0 flex-1 truncate text-right text-[17px] font-bold tabular-nums text-[#ffe9a0]">
            {{ session.user.gold || 0 }}
          </span>
          <button type="button" class="absolute right-1 top-1/2 h-7 w-7 -translate-y-1/2">
            <img :src="dizhu('btn_plus.png')" class="h-full w-full object-contain" alt="+" />
          </button>
        </div>
      </div>
      <div
        v-if="showSelfCardsLeft"
        class="pointer-events-none absolute bottom-[14px] left-1/2 flex h-[46px] -translate-x-1/2 items-center gap-1"
      >
        <span class="text-[13px] font-semibold text-white/75">剩余</span>
        <span class="text-[22px] font-extrabold tabular-nums leading-none text-[#ffe9a0] drop-shadow">
          {{ selfCardsLeft }}
        </span>
      </div>
      <div class="absolute bottom-[8px] right-2 flex items-center gap-3">
        <div
          v-if="showCornerMultiple"
          class="flex h-[46px] items-center gap-1"
          title="当前倍数"
        >
          <span class="text-[13px] font-semibold text-white/75">倍数</span>
          <span class="text-[22px] font-extrabold tabular-nums leading-none text-[#ffe9a0] drop-shadow">
            ×{{ cornerMultiple }}
          </span>
        </div>
        <div class="flex items-center gap-1.5">
          <img :src="art('hall', 'user_bar_room_card.png')" class="h-9 w-9 object-contain" alt="" />
          <span class="text-[17px] font-bold tabular-nums text-[#ffe9a0]">{{ cardsText }}</span>
          <button type="button" class="h-7 w-7">
            <img :src="dizhu('btn_plus.png')" class="h-full w-full object-contain" alt="+" />
          </button>
        </div>
        <button
          type="button"
          class="h-[56px] w-[120px] shrink-0 transition active:scale-95"
          @click="showChat = !showChat"
        >
          <img :src="dizhu('chat.png')" class="h-full w-full object-contain" alt="聊天" />
        </button>
      </div>
    </footer>

    <ChatQuickPanel v-model:show="showChat" @select="onSendChat" />

    <p
      v-if="loadError || pixiFailed"
      class="pointer-events-none absolute left-1/2 top-[78px] z-50 -translate-x-1/2 rounded bg-black/70 px-3 py-1.5 text-[13px] text-rose-200"
    >
      {{ loadError || '手牌层异常' }}
    </p>
    <p
      v-else-if="statusText"
      class="pointer-events-none absolute left-1/2 top-[78px] z-50 -translate-x-1/2 rounded bg-black/50 px-3 py-1 text-[12px] text-amber-100/90"
    >
      {{ statusText }}
    </p>

    <!-- 结算页（dizhu_settle 素材） -->
    <SettleResultView
      :show="match.showSettle && !!match.result"
      @close="match.showSettle = false"
    />

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
          class="gf-btn-green flex-1 py-3.5 text-[22px] font-extrabold text-white"
          :style="{ backgroundImage: `url(${btnGreenLong})` }"
          @click="backOpen = false"
        >
          取消
        </button>
        <button
          type="button"
          class="gf-btn-orange flex-1 py-3.5 text-[22px] font-extrabold text-white"
          :style="{ backgroundImage: `url(${btnOrangeLong})` }"
          @click="confirmBack"
        >
          确定
        </button>
      </template>
    </AppDialog>

    <DismissConfirmDialog
      :show="ownerDismissOpen"
      :idle="dismissIdle"
      @close="ownerDismissOpen = false"
      @confirm="confirmOwnerDismiss"
    />

    <AppDialog :show="showRule" title="房间规则" closable width="560px" @close="showRule = false">
      <div class="space-y-2 text-left text-[18px] leading-8 text-[#4a3018]">
        <p><span class="text-[#5a3a18]/55">房主：</span>{{ ruleView.ownerName }}</p>
        <p><span class="text-[#5a3a18]/55">模式：</span>{{ ruleView.modeName }}</p>
        <p>
          <span class="text-[#5a3a18]/55">房卡：</span>{{ ruleView.payModeText }}
          <span v-if="ruleView.payModeDesc" class="text-[#5a3a18]/55">（{{ ruleView.payModeDesc }}）</span>
        </p>
        <p><span class="text-[#5a3a18]/55">底分：</span>{{ ruleView.df }}</p>
        <p v-if="ruleView.showAskMode">
          <span class="text-[#5a3a18]/55">规则：</span>{{ ruleView.askModeText }}
        </p>
        <p v-if="ruleView.showBomb">
          <span class="text-[#5a3a18]/55">炸弹分：</span>{{ ruleView.bombMultiple }} 分/人
        </p>
        <p><span class="text-[#5a3a18]/55">人数：</span>固定 {{ ruleView.seats }} 人</p>
        <p class="text-[#5a3a18]/55">
          房间号 {{ ruleView.roomId }} · 已打 {{ ruleView.played }} 局 · 总局 {{ ruleView.zjs }}
        </p>
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
  </div>
</template>

<style scoped>
/* 左右对称贴舞台边。高度跟图片比例，不用 object-fit。 */
.table-plate {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  display: block;
  width: 100%;
  height: auto;
  max-width: none !important;
  max-height: none !important;
}

.gf-btn-orange,
.gf-btn-green {
  border: 0;
  background-repeat: no-repeat;
  background-position: center;
  background-size: 100% 100%;
}

/* jsq.png 圆面中心约在画面 54%，不用整图几何中心 */
.clock-sec {
  position: absolute;
  left: 54%;
  top: 54%;
  z-index: 1;
  margin: 0;
  transform: translate(-50%, -50%);
  line-height: 1;
  font-weight: 900;
  font-variant-numeric: tabular-nums;
  color: #fff;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.65);
  pointer-events: none;
}

.cards-left-num {
  color: #fff;
}

.cards-left--alarm-two .cards-left-num {
  color: #ffe08a;
}

.cards-left--alarm-one .cards-left-num {
  color: #ffb0a0;
  animation: cards-left-pulse 0.55s ease-in-out infinite;
}

.cards-left--alarm-one img[src*='card_back'] {
  filter: drop-shadow(0 0 0.35rem rgba(255, 80, 60, 0.85));
}

.cards-left--alarm-two img[src*='card_back'] {
  filter: drop-shadow(0 0 0.3rem rgba(255, 200, 80, 0.75));
}

.seat-avatar--alarm {
  animation: avatar-alarm-flash 0.55s ease-in-out infinite;
}

.seat-alarm-icon {
  animation: alarm-flash 0.55s ease-in-out infinite;
}

.seat-alarm-icon--out {
  animation-name: alarm-flash-side;
}

.seat-alarm-icon.cards-left--alarm-one {
  filter: drop-shadow(0 0 0.4rem rgba(255, 40, 20, 0.85));
}

.seat-alarm-icon.cards-left--alarm-two {
  filter: drop-shadow(0 0 0.35rem rgba(255, 180, 40, 0.75));
}

@keyframes cards-left-pulse {
  0%,
  100% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.12);
  }
}

@keyframes avatar-alarm-flash {
  0%,
  100% {
    filter: brightness(1);
    box-shadow: 0 0 0 2px rgba(255, 80, 40, 0.35);
  }
  50% {
    filter: brightness(1.15);
    box-shadow: 0 0 0 4px rgba(255, 80, 40, 0.65);
  }
}

.spade3-broadcast {
  position: absolute;
  z-index: 70;
  pointer-events: none;
  white-space: nowrap;
  padding: 6px 14px;
  border-radius: 10px;
  border: 2px solid #ffe56a;
  background: rgba(40, 18, 0, 0.88);
  color: #ffe56a;
  font-size: 22px;
  font-weight: 900;
  letter-spacing: 0.04em;
  text-shadow:
    0 1px 0 #5a3200,
    0 0 8px rgba(0, 0, 0, 0.9);
  animation: spade3-pop-tip 0.35s ease-out, spade3-bob-tip 1.15s ease-in-out 0.35s infinite;
}

.spade3-broadcast--left {
  left: 92px;
  top: 10px;
}

.spade3-broadcast--right {
  right: 92px;
  top: 10px;
  animation: spade3-pop-right 0.35s ease-out, spade3-bob-right 1.15s ease-in-out 0.35s infinite;
}

.spade3-broadcast--self {
  left: 78px;
  top: 4px;
}

@keyframes spade3-pop-right {
  from {
    opacity: 0;
    transform: translateX(-100%) scale(0.6);
  }
  to {
    opacity: 1;
    transform: translateX(-100%) scale(1);
  }
}

@keyframes spade3-bob-right {
  0%,
  100% {
    transform: translateX(-100%) translateY(0);
  }
  50% {
    transform: translateX(-100%) translateY(-6px);
  }
}

@keyframes spade3-pop-tip {
  from {
    opacity: 0;
    transform: scale(0.6);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}

@keyframes spade3-bob-tip {
  0%,
  100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-5px);
  }
}

@keyframes alarm-flash {
  0%,
  100% {
    opacity: 1;
    transform: translateX(-50%) scale(1);
  }
  50% {
    opacity: 0.55;
    transform: translateX(-50%) scale(1.12);
  }
}

@keyframes alarm-flash-side {
  0%,
  100% {
    opacity: 1;
    transform: translateY(-50%) scale(1);
  }
  50% {
    opacity: 0.55;
    transform: translateY(-50%) scale(1.12);
  }
}
</style>
