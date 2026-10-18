<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { art } from '../utils/art'
import JoinRoomModal from '../components/JoinRoomModal.vue'
import ModalShell from '../components/ModalShell.vue'
import { forceLogin } from '../services/authRedirect'
import { formatGold, session } from '../services/session'
import { applyUserProfile, goCreateRoom, goGameTable } from '../services/gameState'
import { wsService } from '../services/gameSocket'
import { seatAvatarSrc } from '../services/seatAvatar'
import { http } from '../services/http'
import { allowAutoResume, recoverAlreadyInRoom, tryEnterPendingRoom } from '../services/roomResume'
import { joinRoomWs } from '../services/roomApi'
import { fetchRoomCardTypes, fetchShopRoomCardTip } from '../services/roomCardApi'
import { fetchLetterList } from '../services/letterApi'
import {
  fetchFeedbackList,
  fetchFeedbackUnread,
  markFeedbackReplyRead,
  submitFeedback as submitFeedbackApi,
} from '../services/feedbackApi'
import { fetchRecordList } from '../services/recordApi'
import { GameType, hallGames, findHallGame } from '../config/gameTypes'
import {
  flowerCreateRoomRules,
  hzmjRules,
  landlordRules,
  laiziRules,
  niuCreateRoomRules,
  pdkRules,
} from '../config/createRules'
import { DISMISS_RULE_NOTE } from '../logic/shared/dismiss'
import { audioManager } from '../services/audioManager'

const feedbackUnread = ref(0)
const docks = computed(() => [
  { key: 'settings', label: '设置', icon: 'menu_settings.png' },
  { key: 'mail', label: '邮件', icon: 'menu_mail.png' },
  { key: 'rules', label: '玩法', icon: 'menu_rules.png' },
  {
    key: 'feedback',
    label: '反馈',
    icon: 'menu_feedback.png',
    badge: feedbackUnread.value > 0 ? (feedbackUnread.value > 99 ? '99+' : feedbackUnread.value) : 0,
  },
  { key: 'record', label: '战绩', icon: 'menu_record.png' },
  { key: 'shop', label: '商城', icon: 'menu_shop.png', large: true },
])

const shopPacks = ref([])
const shopLoading = ref(false)
const shopHint = ref('')
const shopTip = ref('')

const joinOpen = ref(false)
const joinMissing = ref(false)
const tipOpen = ref(false)
const tipTitle = ref('')
const tipBody = ref('')
const settingsOpen = ref(false)
const mailOpen = ref(false)
const shopOpen = ref(false)
const feedbackOpen = ref(false)
const recordOpen = ref(false)
const rulesOpen = ref(false)
const rulesTab = ref(GameType.PAODEKUAI)

const RULE_BY_TYPE = {
  [GameType.PAODEKUAI]: pdkRules,
  [GameType.HZMJ]: hzmjRules,
  [GameType.LANDLORD]: landlordRules,
  [GameType.LAIZI_LANDLORD]: laiziRules,
  [GameType.ZJH]: flowerCreateRoomRules,
  [GameType.NIU]: niuCreateRoomRules,
}

const rulesGame = computed(() => hallGames.find((g) => g.gameType === rulesTab.value) || hallGames[0])
const rulesPack = computed(() => RULE_BY_TYPE[rulesTab.value] || {})
const rulesNotes = computed(() => {
  const pack = rulesPack.value
  let notes = []
  if (Array.isArray(pack.playNotes) && pack.playNotes.length) {
    notes = [...pack.playNotes]
  } else {
    const tip = String(pack.idleTip || pack.desc || '').trim()
    if (tip) notes = [{ title: '说明', lines: [tip] }]
  }
  if (!notes.some((n) => n.title === DISMISS_RULE_NOTE.title)) {
    notes = [...notes, DISMISS_RULE_NOTE]
  }
  return notes
})
const musicOn = ref(!audioManager.isBgmMuted())
const soundOn = ref(!audioManager.isMuted())

/** 列表滚到底再要下一页 */
const LIST_PAGE_SIZE = 20

/** 站内信：仅标题列表 */
const mailList = ref([])
const mailPage = ref(1)
const mailTotal = ref(0)
const mailLoading = ref(false)
const mailLoadingMore = ref(false)
const mailHint = ref('')
const mailScroller = ref(null)
const mailHasMore = computed(() => mailList.value.length < mailTotal.value)

/** 反馈：write | reply */
const feedbackTab = ref('write')
const feedbackText = ref('')
const feedbackSent = ref(false)
const feedbackHint = ref('')
const feedbackSubmitting = ref(false)
const feedbackList = ref([])
const feedbackLoading = ref(false)

const feedbackReplies = computed(() =>
  feedbackList.value.filter((f) => f.hasReply),
)

/** 战绩列表 */
const recordRows = ref([])
const recordPage = ref(1)
const recordHasMore = ref(true)
const recordLoading = ref(false)
const recordLoadingMore = ref(false)
const recordHint = ref('')
const recordScroller = ref(null)

const filteredRecords = computed(() => recordRows.value)

const noticeText = ref('暂无公告')
const NOTICE_FALLBACK = '暂无公告'

const nickname = computed(() => session.user.nickname || '玩家')
const uidText = computed(() => {
  const id = Number(session.user.uid) || 0
  return id > 0 ? `ID ${id}` : ''
})
const goldText = computed(() => formatGold(session.user.gold || 0))
/** 金币不足：用于提示，不再静默吞掉点击（APK 上资料未刷到时曾表现为「创建房间没反应」） */
const goldEmpty = computed(() => {
  const n = Number(session.user.gold)
  return !Number.isFinite(n) || n <= 0
})
const cardsText = computed(() => {
  const n = Number(session.user.cards)
  return Number.isFinite(n) && n >= 0 ? String(Math.floor(n)) : '0'
})
const avatarSrc = computed(() =>
  seatAvatarSrc(session.user.avatar, session.user.uid),
)

function showGoldTip(action = '操作') {
  tipTitle.value = '提示'
  tipBody.value = `金币不足，暂时无法${action}。请联系管理员充值后再试。`
  tipOpen.value = true
}

async function refreshHallProfile() {
  try {
    const profile = await http.post('/api/member/index', {})
    if (profile.code === 0 && profile.data) applyUserProfile(profile.data)
  } catch {
    /* 资料失败不挡大厅 */
  }
}

function nearListBottom(el) {
  if (!el) return false
  return el.scrollTop + el.clientHeight >= el.scrollHeight - 48
}

async function loadPublicNotice() {
  try {
    const res = await fetchLetterList(1, 1, 'public')
    const title = res.ok ? String(res.list[0]?.title || '').trim() : ''
    noticeText.value = title || NOTICE_FALLBACK
  } catch {
    noticeText.value = NOTICE_FALLBACK
  }
}

async function loadMailList(reset = true, depth = 0) {
  if (mailLoading.value || mailLoadingMore.value) return
  if (!reset && !mailHasMore.value) return
  const page = reset ? 1 : mailPage.value + 1
  if (reset) {
    mailLoading.value = true
    mailHint.value = ''
    mailPage.value = 1
    mailTotal.value = 0
    mailList.value = []
  } else {
    mailLoadingMore.value = true
  }
  try {
    const res = await fetchLetterList(page, LIST_PAGE_SIZE, 'private')
    if (!res.ok) {
      mailHint.value = res.msg || '加载失败'
      if (reset) mailList.value = []
      return
    }
    mailTotal.value = res.total
    mailPage.value = page
    mailList.value = reset ? res.list : mailList.value.concat(res.list)
  } catch (err) {
    mailHint.value = err instanceof Error ? err.message : '加载失败'
    if (reset) mailList.value = []
  } finally {
    mailLoading.value = false
    mailLoadingMore.value = false
  }
  await nextTick()
  const el = mailScroller.value
  if (
    mailOpen.value &&
    mailHasMore.value &&
    depth < 6 &&
    el &&
    el.clientHeight > 0 &&
    el.scrollHeight <= el.clientHeight + 8
  ) {
    await loadMailList(false, depth + 1)
  }
}

function onMailScroll(ev) {
  if (nearListBottom(ev.target)) void loadMailList(false)
}

async function openMail() {
  mailOpen.value = true
  mailHint.value = ''
  await loadMailList()
}

function backLogin() {
  settingsOpen.value = false
  forceLogin()
}

function enterCreate() {
  // 进创房页不拦：页内会校验房卡/底分金币；静默拦截在 APK 上像「没反应」
  tipOpen.value = false
  goCreateRoom()
}

function openJoin() {
  if (goldEmpty.value) {
    showGoldTip('加入房间')
    return
  }
  joinMissing.value = false
  joinOpen.value = true
}

function openRules() {
  if (!hallGames.some((g) => g.gameType === rulesTab.value)) {
    rulesTab.value = hallGames[0]?.gameType || GameType.PAODEKUAI
  }
  rulesOpen.value = true
}

async function onJoin(roomId) {
  joinMissing.value = false
  tipTitle.value = '加入房间'
  const id = String(roomId ?? '').trim()
  if (!id || !/^\d{4,8}$/.test(id)) {
    tipBody.value = '请输入正确的房间号'
    tipOpen.value = true
    return
  }
  try {
    allowAutoResume()
    const data = await joinRoomWs(id)
    const gt = Number(data.gameType ?? data.game_type ?? session.room.gameType)
    if (Number.isFinite(gt) && gt > 0) {
      session.room.gameType = gt
      const g = findHallGame(gt)
      if (g) session.room.playName = g.name
    }
    joinOpen.value = false
    goGameTable()
  } catch (err) {
    if (await recoverAlreadyInRoom(err)) {
      joinOpen.value = false
      return
    }
    const msg = err instanceof Error ? err.message : '加入房间失败'
    if (/不存在|房间不存在/.test(msg)) {
      joinMissing.value = true
      return
    }
    tipBody.value = msg
    tipOpen.value = true
  }
}

async function refreshFeedbackUnread() {
  try {
    feedbackUnread.value = await fetchFeedbackUnread()
  } catch {
    /* ignore */
  }
}

async function loadFeedbackList() {
  feedbackLoading.value = true
  try {
    const res = await fetchFeedbackList(1, 50)
    if (!res.ok) {
      feedbackHint.value = res.msg || '加载失败'
      return
    }
    feedbackList.value = res.list
    feedbackUnread.value = res.unread
  } catch (err) {
    feedbackHint.value = err instanceof Error ? err.message : '加载失败'
  } finally {
    feedbackLoading.value = false
  }
}

async function openFeedback() {
  feedbackTab.value = 'write'
  feedbackText.value = ''
  feedbackSent.value = false
  feedbackHint.value = ''
  feedbackOpen.value = true
  void loadFeedbackList()
}

async function switchFeedbackTab(tab) {
  feedbackTab.value = tab
  feedbackHint.value = ''
  if (tab === 'reply') {
    await loadFeedbackList()
  }
}

async function submitFeedback() {
  const text = feedbackText.value.trim()
  if (text.length < 8) {
    feedbackHint.value = '不得少于8个字'
    feedbackSent.value = false
    return
  }
  if (feedbackSubmitting.value) return
  feedbackSubmitting.value = true
  feedbackHint.value = ''
  try {
    const res = await submitFeedbackApi(text)
    if (!res.ok) {
      feedbackHint.value = res.msg || '提交失败'
      feedbackSent.value = false
      return
    }
    feedbackSent.value = true
    feedbackText.value = ''
    void loadFeedbackList()
  } catch (err) {
    feedbackHint.value = err instanceof Error ? err.message : '提交失败'
    feedbackSent.value = false
  } finally {
    feedbackSubmitting.value = false
  }
}

async function onSelectFeedbackReply(item) {
  if (!item || Number(item.readStatus) === 1) return
  try {
    const res = await markFeedbackReplyRead(item.id)
    if (!res.ok) return
    item.readStatus = 1
    if (res.unread != null) feedbackUnread.value = res.unread
  } catch {
    /* ignore */
  }
}

function formatFeedbackTime(raw) {
  const s = String(raw || '')
  return s.length >= 16 ? s.slice(0, 16) : s
}

function formatRecordScore(score) {
  const n = Number(score)
  if (!Number.isFinite(n)) return String(score ?? '-')
  if (n > 0) return `+${n}`
  return String(n)
}

function formatRecordTime(raw) {
  if (!raw) return '-'
  const s = String(raw)
  const m = s.match(/(\d{2})-(\d{2})\s+(\d{2}:\d{2})/)
  if (m) return `${m[1]}-${m[2]} ${m[3]}`
  return s.length >= 16 ? s.slice(5, 16) : s
}

function formatRecordGame(gameType) {
  return findHallGame(Number(gameType))?.name || `玩法${gameType || ''}`
}

async function loadRecordList(reset = true, depth = 0) {
  if (recordLoading.value || recordLoadingMore.value) return
  if (!reset && !recordHasMore.value) return
  const page = reset ? 1 : recordPage.value + 1
  if (reset) {
    recordLoading.value = true
    recordHint.value = ''
    recordPage.value = 1
    recordHasMore.value = true
    recordRows.value = []
  } else {
    recordLoadingMore.value = true
  }
  try {
    const res = await fetchRecordList({
      gameType: 0,
      page,
      pageSize: LIST_PAGE_SIZE,
    })
    if (!res.ok) {
      recordHint.value = res.msg || '加载失败'
      if (reset) {
        recordRows.value = []
        recordHasMore.value = false
      }
      return
    }
    if (!res.list.length) {
      recordHasMore.value = false
      if (reset) recordRows.value = []
      return
    }
    recordHint.value = ''
    recordPage.value = page
    recordRows.value = reset ? res.list : recordRows.value.concat(res.list)
  } catch (err) {
    recordHint.value = err instanceof Error ? err.message : '加载失败'
    if (reset) {
      recordRows.value = []
      recordHasMore.value = false
    }
  } finally {
    recordLoading.value = false
    recordLoadingMore.value = false
  }
  await nextTick()
  const el = recordScroller.value
  if (
    recordOpen.value &&
    recordHasMore.value &&
    depth < 6 &&
    el &&
    el.clientHeight > 0 &&
    el.scrollHeight <= el.clientHeight + 8
  ) {
    await loadRecordList(false, depth + 1)
  }
}

function onRecordScroll(ev) {
  if (nearListBottom(ev.target)) void loadRecordList(false)
}

async function openRecord() {
  recordOpen.value = true
  recordHint.value = ''
  await loadRecordList()
}

async function openShop() {
  shopOpen.value = true
  shopHint.value = ''
  shopLoading.value = true
  try {
    const [types, tip] = await Promise.all([
      fetchRoomCardTypes(),
      fetchShopRoomCardTip().catch(() => ''),
    ])
    shopTip.value = tip
    shopPacks.value = types.map((t) => ({
      id: Number(t.id),
      name: String(t.name || ''),
      price: String(t.price ?? '0'),
      round_count: Number(t.round_count) || 0,
    }))
    if (!shopPacks.value.length) shopHint.value = '暂无房卡类型'
  } catch (err) {
    shopHint.value = err instanceof Error ? err.message : '加载失败'
    shopPacks.value = []
  } finally {
    shopLoading.value = false
  }
}

/** 接口 price 按金币展示 */
function formatGoldPrice(raw) {
  const n = Number(raw)
  if (!Number.isFinite(n)) return String(raw || '—')
  const text = Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, '')
  return `${text} 金币`
}

function syncAudioSwitches() {
  musicOn.value = !audioManager.isBgmMuted()
  soundOn.value = !audioManager.isMuted()
}

function toggleMusic() {
  const muted = audioManager.toggleBgmMuted()
  musicOn.value = !muted
  if (!muted) audioManager.playPackBGM('common-sfx')
}

function toggleSound() {
  const muted = audioManager.toggleMuted()
  soundOn.value = !muted
}

function onDock(key) {
  if (key === 'settings') {
    syncAudioSwitches()
    settingsOpen.value = true
    return
  }
  if (key === 'mail') {
    void openMail()
    return
  }
  if (key === 'rules') {
    openRules()
    return
  }
  if (key === 'feedback') {
    void openFeedback()
    return
  }
  if (key === 'record') {
    openRecord()
    return
  }
  if (key === 'shop') {
    void openShop()
    return
  }
  tipTitle.value = docks.value.find((d) => d.key === key)?.label || ''
  tipBody.value = '功能 UI 稍后补全'
  tipOpen.value = true
}

onMounted(() => {
  syncAudioSwitches()
  if (audioManager.isBgmMuted()) audioManager.stopBGM()
  else audioManager.playPackBGM('common-sfx')
  void refreshHallProfile()
  void loadPublicNotice()
  void refreshFeedbackUnread()
  void resumeRoomFromHall()
})

/** 进大厅后等鉴权：账号还在任意玩法的房间里则直接进桌，手牌由 Join 的 resume 拉出 */
async function resumeRoomFromHall() {
  try {
    await wsService.connect()
  } catch (err) {
    console.warn('[hall] ws', err)
  }
  if (await tryEnterPendingRoom()) return
  // 鉴权包稍晚到：再试一次
  await new Promise((r) => setTimeout(r, 400))
  await tryEnterPendingRoom()
}
</script>

<template>
  <!-- 对齐 cards LobbyView：顶栏 / 立绘 / 底栏；左侧仅创建+加入 -->
  <div class="lobby relative h-full w-full overflow-hidden text-white">

    <!-- UserBar：头像框 / 昵称+ID / 金币 / 房卡 -->
    <div
      class="pointer-events-none absolute inset-x-0 top-0 z-30 h-[96px] bg-[length:100%_100%] bg-no-repeat"
      :style="{ backgroundImage: `url(${art('hall', 'user_bar_bg.png')})` }"
    >
      <div class="flex h-full items-center justify-between px-3 pr-4">
        <div class="pointer-events-auto flex min-w-0 items-center gap-3">
          <div class="relative h-[80px] w-[80px] shrink-0">
            <div
              class="absolute inset-0 overflow-hidden rounded-[14px] shadow-[0_2px_6px_rgba(0,0,0,0.45)] ring-2 ring-[#ffe27a]/90"
            >
              <img :src="avatarSrc" class="h-full w-full object-cover" alt="" />
            </div>
            <img
              :src="art('hall', 'user_bar_avatar_frame_bottom.png')"
              class="pointer-events-none absolute -bottom-0.5 left-1/2 z-[1] h-[24px] w-[96px] -translate-x-1/2 object-contain"
              alt=""
            />
          </div>
          <div class="flex min-w-0 flex-col justify-center gap-1">
            <div class="flex min-w-0 items-baseline gap-2">
              <div class="truncate text-[20px] font-bold leading-tight text-white drop-shadow">
                {{ nickname }}
              </div>
              <div v-if="uidText" class="shrink-0 text-[15px] font-semibold text-[#ffe9a0]/85">
                {{ uidText }}
              </div>
            </div>
            <div class="flex items-center gap-2">
              <div
                class="relative flex h-[40px] w-[196px] items-center bg-[length:100%_100%] bg-no-repeat pl-10 pr-2"
                :style="{ backgroundImage: `url(${art('hall', 'user_bar_coin_frame.png')})` }"
              >
                <img
                  :src="art('hall', 'user_bar_coin.png')"
                  class="absolute left-0 top-1/2 h-[44px] w-[44px] -translate-y-1/2 object-contain"
                  alt=""
                />
                <span class="w-full text-right text-[17px] font-bold text-[#ffe9a0]">{{ goldText }}</span>
              </div>
              <div
                class="relative flex h-[40px] w-[216px] items-center bg-[length:100%_100%] bg-no-repeat pl-10 pr-2"
                :style="{ backgroundImage: `url(${art('hall', 'user_bar_room_card_frame.png')})` }"
              >
                <img
                  :src="art('hall', 'user_bar_room_card.png')"
                  class="absolute left-0 top-1/2 h-[44px] w-[44px] -translate-y-1/2 object-contain"
                  alt=""
                />
                <span class="flex w-full items-center justify-end gap-0.5 text-[17px] font-bold text-white">
                  <img
                    :src="art('hall', 'user_bar_digits', 'room_card_label.png')"
                    class="h-[16px] w-auto object-contain opacity-95"
                    alt="房卡"
                  />
                  <span>{{ cardsText }}张</span>
                </span>
              </div>
            </div>
          </div>
        </div>

        <div
          class="pointer-events-none mx-3 flex h-[52px] max-w-[480px] flex-1 items-center overflow-hidden bg-[length:100%_100%] bg-no-repeat"
          :style="{ backgroundImage: `url(${art('hall', 'marquee_bg.png')})` }"
        >
          <div class="marquee-track whitespace-nowrap px-4 text-[18px] font-semibold text-[#ffe27a]">
            {{ noticeText }}
          </div>
        </div>

        <button
          type="button"
          class="pointer-events-auto h-[76px] w-[76px] shrink-0 transition hover:brightness-110 active:scale-95"
          aria-label="返回登录"
          @click="backLogin"
        >
          <img :src="art('hall', 'back.png')" class="h-full w-full object-contain" alt="返回" />
        </button>
      </div>
    </div>

    <img
      :src="art('hero', 'character.png')"
      class="pointer-events-none absolute bottom-28 right-[5%] z-10 h-[72%] object-contain drop-shadow-2xl"
      alt=""
    />

    <!-- 左侧：创建房间 + 加入房间（勿用 backdrop-blur，部分安卓 WebView 会吞点击） -->
    <div
      class="absolute left-5 top-[175px] z-40 flex w-[360px] flex-col items-center gap-4 rounded-2xl border border-white/10 bg-black/50 px-5 py-6 shadow-[0_8px_24px_rgba(0,0,0,0.35)]"
    >
      <button
        type="button"
        class="relative z-10 w-full touch-manipulation transition hover:brightness-110 active:scale-[0.98]"
        @click.stop="enterCreate"
      >
        <img
          :src="art('hall', 'room_entry_create.png')"
          class="pointer-events-none w-full object-contain"
          alt="创建好友房"
          draggable="false"
        />
      </button>
      <button
        type="button"
        class="relative z-10 w-full touch-manipulation transition hover:brightness-110 active:scale-[0.98]"
        @click.stop="openJoin"
      >
        <img
          :src="art('hall', 'room_entry_join.png')"
          class="pointer-events-none w-full object-contain"
          alt="加入房间"
          draggable="false"
        />
      </button>
    </div>

    <!-- 底栏：设置 / 邮件 / 玩法 / 反馈 / 战绩 / 商城 -->
    <div class="absolute inset-x-0 bottom-0 z-30 flex justify-center px-3 pb-1">
      <div
        class="relative flex h-[112px] w-full max-w-[1180px] items-end justify-around bg-[length:100%_100%] bg-no-repeat px-2 pb-1.5 pt-3"
        :style="{ backgroundImage: `url(${art('hall', 'menu_bg.png')})` }"
      >
        <button
          v-for="item in docks"
          :key="item.key"
          type="button"
          class="relative flex w-[118px] flex-col items-center justify-end transition hover:brightness-110 active:scale-95"
          @click="onDock(item.key)"
        >
          <div class="relative">
            <img
              :src="art('hall', item.icon)"
              class="object-contain drop-shadow-lg"
              :class="item.large ? 'h-[96px]' : 'h-[88px]'"
              :alt="item.label"
            />
            <template v-if="item.badge">
              <img
                :src="art('hall', 'menu_red_dot.png')"
                class="absolute right-0 top-0 h-[26px] w-[26px] object-contain"
                alt=""
              />
              <span
                class="absolute right-0 top-0 flex h-[26px] w-[26px] items-center justify-center text-[13px] font-bold text-white"
              >
                {{ item.badge }}
              </span>
            </template>
          </div>
        </button>
      </div>
    </div>

    <JoinRoomModal
      :open="joinOpen"
      :missing="joinMissing"
      @close="joinOpen = false; joinMissing = false"
      @clear-missing="joinMissing = false"
      @join="onJoin"
    />

    <ModalShell :open="rulesOpen" width="800px" min-height="600px" @close="rulesOpen = false">
      <h2 class="mb-4 text-[26px] font-extrabold tracking-wide text-[#5a3a18]">玩法规则</h2>
      <div class="mb-4 flex w-full flex-wrap justify-center gap-2.5">
        <button
          v-for="item in hallGames"
          :key="item.gameType"
          type="button"
          class="rounded-full px-4 py-2 text-[15px] font-bold transition active:scale-95"
          :class="
            rulesTab === item.gameType
              ? 'bg-[#e0a030] text-white shadow'
              : 'bg-[#fff4d8] text-[#5a3a18] hover:brightness-105'
          "
          @click="rulesTab = item.gameType"
        >
          {{ item.name }}
        </button>
      </div>
      <div class="min-h-[320px] w-full flex-1 overflow-auto px-1 text-left">
        <p class="mb-3 text-[18px] font-extrabold text-[#8b3a12]">{{ rulesGame.name }}</p>
        <div v-for="(block, idx) in rulesNotes" :key="idx" class="mb-3">
          <p class="mb-1 text-[16px] font-bold text-[#5a3a18]">{{ block.title }}</p>
          <p
            v-for="(line, li) in block.lines"
            :key="li"
            class="mb-1 text-[15px] leading-relaxed text-[#4a3018]/88"
          >
            {{ line }}
          </p>
        </div>
        <p v-if="!rulesNotes.length" class="text-center text-[16px] text-[#5a3a18]/55">暂无规则说明</p>
      </div>
    </ModalShell>

    <ModalShell :open="settingsOpen" width="620px" min-height="560px" @close="settingsOpen = false">
      <img :src="art('hall_modals', 'settings', 'title_setting.png')" class="mb-10 h-12 object-contain" alt="设置" />
      <div class="flex w-[440px] flex-col gap-9">
        <div class="flex items-center justify-between">
          <img :src="art('hall_modals', 'settings', 'music1.png')" class="h-12 object-contain" alt="音乐" />
          <button type="button" class="h-14 w-28 transition active:scale-95" @click="toggleMusic">
            <img
              :src="art('hall_modals', 'settings', musicOn ? 'on.png' : 'off.png')"
              class="h-full w-full object-contain"
              alt=""
            />
          </button>
        </div>
        <div class="flex items-center justify-between">
          <img :src="art('hall_modals', 'settings', 'music2.png')" class="h-12 object-contain" alt="音效" />
          <button type="button" class="h-14 w-28 transition active:scale-95" @click="toggleSound">
            <img
              :src="art('hall_modals', 'settings', soundOn ? 'on.png' : 'off.png')"
              class="h-full w-full object-contain"
              alt=""
            />
          </button>
        </div>
      </div>
      <button
        type="button"
        class="mt-12 h-16 w-56 transition hover:brightness-110 active:scale-95"
        @click="backLogin"
      >
        <img :src="art('hall_modals', 'settings', 'log_out.png')" class="h-full w-full object-contain" alt="退出登录" />
      </button>
    </ModalShell>

    <ModalShell :open="mailOpen" width="620px" min-height="480px" :scrollable="false" @close="mailOpen = false">
      <img
        :src="art('hall_modals', 'mail', 'message_pressed.png')"
        class="mb-4 h-12 shrink-0 object-contain"
        alt="消息"
      />
      <div
        ref="mailScroller"
        class="flex h-[360px] w-full flex-col overflow-y-auto rounded-xl border border-[#c9a06a]/45 bg-[#fff8ea]/90"
        @scroll="onMailScroll"
      >
        <p v-if="mailLoading && !mailList.length" class="p-5 text-center text-[15px] text-[#5a3a18]/70">加载中…</p>
        <p v-else-if="!mailList.length" class="p-5 text-center text-[15px] text-[#5a3a18]/70">暂无私信</p>
        <template v-else>
          <div
            v-for="item in mailList"
            :key="item.id"
            class="border-b border-[#c9a06a]/25 px-4 py-3.5 text-center text-[17px] font-semibold text-[#8b3a12]"
          >
            {{ item.title || '无标题' }}
          </div>
          <p v-if="mailLoadingMore" class="py-3 text-center text-[15px] text-[#5a3a18]/60">加载中…</p>
          <p v-else-if="!mailHasMore" class="py-3 text-center text-[15px] text-[#5a3a18]/45">没有更多了</p>
        </template>
      </div>
      <p v-if="mailHint" class="mt-2 text-center text-[15px] text-[#c0392b]">{{ mailHint }}</p>
    </ModalShell>

    <ModalShell :open="shopOpen" width="860px" min-height="560px" @close="shopOpen = false">
      <img :src="art('hall_modals', 'shop', 'shop_special_title.png')" class="mb-2 h-12 object-contain" alt="商城" />
      <p class="mb-4 text-center text-[18px] font-semibold text-[#8b3a12]/90">
        我的房卡 · 充值 / 购卡请联系管理员
      </p>
      <p v-if="shopLoading" class="py-10 text-center text-[17px] text-[#5a3a18]/70">加载中…</p>
      <div v-else class="grid w-full grid-cols-3 gap-5 px-1">
        <div
          v-for="p in shopPacks"
          :key="p.id"
          class="flex flex-col items-center rounded-xl border border-[#c9a06a]/50 bg-[#fff8ea]/90 p-4 shadow-sm"
        >
          <img
            :src="art('hall_modals', 'shop', 'room_card_large_backup.png')"
            class="h-20 object-contain"
            alt=""
          />
          <div class="mt-1 text-[20px] font-black text-[#8b3a12]">{{ p.name }}</div>
          <div class="text-[17px] font-bold text-[#c0392b]">
            {{ formatGoldPrice(p.price) }}
          </div>
          <div class="mt-1 text-[15px] text-[#5a3a18]/75">{{ p.round_count }}局 / 张</div>
        </div>
      </div>
      <p v-if="shopHint" class="mt-3 text-center text-[16px] text-[#c0392b]">{{ shopHint }}</p>
      <p v-if="shopTip" class="mt-5 max-w-[90%] text-center text-[18px] font-semibold text-[#8b3a12]">
        {{ shopTip }}
      </p>
    </ModalShell>

    <!-- 反馈（对齐 Cocos FeedBackDialog：宽条选中 Tab + 输入框 + 大按钮提交 / 答复气泡） -->
    <ModalShell :open="feedbackOpen" width="920px" min-height="540px" @close="feedbackOpen = false">
      <!-- 顶栏 Tab：选中用宽条，未选中用小字 -->
      <div class="mb-5 flex w-full max-w-[780px] items-end justify-center gap-1">
        <template v-if="feedbackTab === 'write'">
          <button type="button" class="h-[51px] transition active:scale-[0.98]" @click="switchFeedbackTab('write')">
            <img
              :src="art('hall_modals', 'feedback', 'feedback_pressed.png')"
              class="h-full w-auto object-contain"
              alt="反馈"
            />
          </button>
          <button
            type="button"
            class="relative mb-1 h-[41px] transition active:scale-95"
            @click="switchFeedbackTab('reply')"
          >
            <img
              :src="art('hall_modals', 'feedback', 'reply.png')"
              class="h-full w-auto object-contain"
              alt="答复"
            />
            <img
              v-if="feedbackUnread > 0"
              :src="art('hall', 'menu_red_dot.png')"
              class="absolute -right-2 -top-2 h-[18px] w-[18px] object-contain"
              alt=""
            />
          </button>
        </template>
        <template v-else>
          <button
            type="button"
            class="mb-1 h-[41px] transition active:scale-95"
            @click="switchFeedbackTab('write')"
          >
            <img
              :src="art('hall_modals', 'feedback', 'feedback.png')"
              class="h-full w-auto object-contain"
              alt="反馈"
            />
          </button>
          <button type="button" class="relative h-[52px] transition active:scale-[0.98]" @click="switchFeedbackTab('reply')">
            <img
              :src="art('hall_modals', 'feedback', 'reply_pressed.png')"
              class="h-full w-auto object-contain"
              alt="答复"
            />
            <img
              v-if="feedbackUnread > 0"
              :src="art('hall', 'menu_red_dot.png')"
              class="absolute -right-1 -top-1 h-[18px] w-[18px] object-contain"
              alt=""
            />
          </button>
        </template>
      </div>

      <!-- 意见反馈 -->
      <div v-if="feedbackTab === 'write'" class="flex w-full flex-1 flex-col items-center">
        <div
          class="relative h-[143px] w-[677px] max-w-full bg-[length:100%_100%] bg-center bg-no-repeat px-7 py-4"
          :style="{ backgroundImage: `url(${art('hero', 'hall_dialog', 'feedback', 'player_feedback_box.png')})` }"
        >
          <textarea
            v-model="feedbackText"
            maxlength="200"
            class="h-full w-full resize-none bg-transparent text-[18px] leading-8 text-[#4a3018] outline-none placeholder:text-[#754433]/60"
            placeholder="请输入您的反馈意见"
            @input="feedbackSent = false"
          />
        </div>

        <div class="mt-3 flex w-[677px] max-w-full items-center justify-between px-1">
          <img
            :src="art('hall_modals', 'feedback', 'min_8_chars.png')"
            class="h-8 object-contain"
            alt="不得少于8个字"
          />
          <img
            v-if="feedbackSent"
            :src="art('hall_modals', 'feedback', 'thanks_for_feedback.png')"
            class="h-8 object-contain"
            alt="感谢反馈"
          />
        </div>

        <p v-if="feedbackHint" class="mt-2 text-[16px] font-semibold text-[#c0392b]">{{ feedbackHint }}</p>

        <button
          type="button"
          class="relative mt-6 h-[108px] w-[240px] transition hover:brightness-110 active:scale-95 disabled:opacity-50"
          :disabled="feedbackSubmitting"
          @click="submitFeedback"
        >
          <img
            :src="art('hall_modals', 'buttons', 'big_button.png')"
            class="h-full w-full object-contain"
            alt=""
          />
          <img
            :src="art('hall_modals', 'feedback', 'submit_sm.png')"
            class="pointer-events-none absolute left-1/2 top-1/2 h-[34px] w-auto -translate-x-1/2 -translate-y-1/2 object-contain"
            alt="提交"
          />
        </button>
      </div>

      <!-- 客服答复：气泡列表（对齐 system_reply_box） -->
      <div v-else class="flex min-h-[260px] w-full max-w-[780px] flex-1 flex-col items-center overflow-y-auto px-1">
        <p v-if="feedbackLoading" class="py-10 text-center text-[14px] text-[#5a3a18]/70">加载中…</p>
        <template v-else-if="feedbackReplies.length">
          <button
            v-for="item in feedbackReplies"
            :key="item.id"
            type="button"
            class="relative mb-4 h-[181px] w-[742px] max-w-full shrink-0 bg-[length:100%_100%] bg-center bg-no-repeat px-8 pb-5 pt-8 text-left transition active:scale-[0.99]"
            :style="{ backgroundImage: `url(${art('hero', 'hall_dialog', 'feedback', 'system_reply_box.png')})` }"
            @click="onSelectFeedbackReply(item)"
          >
            <img
              v-if="!item.readStatus"
              :src="art('hall', 'menu_red_dot.png')"
              class="absolute right-6 top-3 h-[18px] w-[18px] object-contain"
              alt=""
            />
            <div class="h-full overflow-y-auto">
              <p class="mb-1 text-[14px] text-[#5a3a18]/55">{{ formatFeedbackTime(item.replyAt || item.createAt) }}</p>
              <p class="whitespace-pre-wrap text-[17px] leading-7 text-[#3d2810]">{{ item.reply }}</p>
            </div>
          </button>
        </template>
        <div
          v-else
          class="flex h-[181px] w-[742px] max-w-full items-center justify-center bg-[length:100%_100%] bg-center bg-no-repeat px-8"
          :style="{ backgroundImage: `url(${art('hero', 'hall_dialog', 'feedback', 'system_reply_box.png')})` }"
        >
          <p class="text-center text-[18px] font-semibold text-[#5a3a18]/75">暂无客服回复</p>
        </div>
      </div>
    </ModalShell>

    <!-- 战绩：标题固定，仅列表区滚动 -->
    <ModalShell
      :open="recordOpen"
      width="920px"
      min-height="560px"
      :scrollable="false"
      @close="recordOpen = false"
    >
      <div class="flex min-h-0 w-full flex-1 flex-col overflow-hidden">
        <div class="mb-4 flex w-full shrink-0 items-center justify-center">
          <img
            :src="art('misc', 'playway', 'record_selected.png')"
            class="h-12 object-contain"
            alt="战绩"
          />
        </div>

        <div
          class="flex min-h-0 w-full flex-1 flex-col overflow-hidden rounded-xl border border-[#c9a06a]/40 bg-[#fff8ea]/85"
        >
          <div
            class="grid shrink-0 grid-cols-[1fr_1.2fr_1.5fr_1fr] gap-2 border-b border-[#c9a06a]/35 px-4 py-3.5 text-center text-[17px] font-bold text-[#8b3a12]"
          >
            <span>房号</span>
            <span>玩法</span>
            <span>结束时间</span>
            <span>输赢分</span>
          </div>

          <div
            ref="recordScroller"
            class="min-h-0 flex-1 overflow-y-auto px-2 py-2"
            @scroll="onRecordScroll"
          >
            <p
              v-if="recordLoading && !filteredRecords.length"
              class="flex h-full min-h-[220px] items-center justify-center text-[20px] font-semibold text-[#8a5a20]/70"
            >
              加载中…
            </p>
            <p
              v-else-if="recordHint && !filteredRecords.length"
              class="flex h-full min-h-[220px] items-center justify-center text-[18px] font-semibold text-[#c0392b]"
            >
              {{ recordHint }}
            </p>
            <p
              v-else-if="!filteredRecords.length"
              class="flex h-full min-h-[220px] items-center justify-center text-[20px] font-semibold text-[#8a5a20]/70"
            >
              暂无战绩记录
            </p>
            <template v-else>
              <div
                v-for="row in filteredRecords"
                :key="`${row.recordId}-${row.seatNo}`"
                class="mb-2 grid grid-cols-[1fr_1.2fr_1.5fr_1fr] items-center gap-2 rounded-lg bg-white/55 px-3 py-3 text-center text-[17px] text-[#4a3018]"
              >
                <span class="font-semibold">{{ row.roomNum }}</span>
                <span>{{ formatRecordGame(row.gameType) }}</span>
                <span>{{ formatRecordTime(row.endTime) }}</span>
                <span
                  class="font-bold"
                  :class="Number(row.score) > 0 ? 'text-emerald-700' : Number(row.score) < 0 ? 'text-rose-600' : ''"
                >
                  {{ formatRecordScore(row.score) }}
                </span>
              </div>
              <p v-if="recordLoadingMore" class="py-3 text-center text-[16px] text-[#8a5a20]/60">加载中…</p>
              <p v-else-if="!recordHasMore" class="py-3 text-center text-[16px] text-[#8a5a20]/45">没有更多了</p>
              <p v-if="recordHint" class="pb-2 text-center text-[15px] text-[#c0392b]">{{ recordHint }}</p>
            </template>
          </div>
        </div>
      </div>
    </ModalShell>

    <ModalShell :open="tipOpen" width="540px" min-height="320px" @close="tipOpen = false">
      <p class="mb-3 text-[26px] font-bold text-[#5a3a18]">{{ tipTitle }}</p>
      <p class="text-center text-[20px] font-semibold text-[#5a3a18]">{{ tipBody }}</p>
      <button
        type="button"
        class="mt-8 h-14 w-48 transition hover:brightness-110"
        @click="tipOpen = false"
      >
        <img :src="art('guest_login', 'orange_btn_sm.png')" class="h-full w-full object-contain" alt="知道了" />
      </button>
    </ModalShell>
  </div>
</template>
