<script setup>
import { computed, defineComponent, h, onMounted, reactive, ref, watch } from 'vue'
import AppDialog from '../components/AppDialog.vue'
import RoomNumberDigits from '../components/RoomNumberDigits.vue'
import {
  createRoomModes,
  flowerCreateRoomRules,
  flowerMaxPlayers,
  flowerMinPlayers,
  flowerRules,
  hzmjRules,
  landlordRules,
  laiziRules,
  menMaxFromFollow,
  niuCreateRoomRules,
  niuMaxPlayers,
  niuMinPlayers,
  pdkRules,
} from '../config/createRules'
import { GameType, hallGames } from '../config/gameTypes'
import { parseRuleKvList } from '../config/ruleKv'
import { createRoomHttp, joinRoomWs } from '../services/roomApi'
import { fetchMyRoomCards, fetchRoomCardTypes } from '../services/roomCardApi'
import { goGameTable, goLobby } from '../services/gameState'
import { applyCreatedRoom, session } from '../services/session'
import { allowAutoResume, recoverAlreadyInRoom } from '../services/roomResume'
import { art } from '../utils/art'

const RuleRow = defineComponent({
  name: 'RuleRow',
  props: {
    title: { type: String, required: true },
    block: { type: Boolean, default: false },
  },
  setup(props, { slots }) {
    return () =>
      h('div', { class: 'gf-rule-row mb-3' }, [
        h('p', { class: 'gf-rule-row__title' }, props.title),
        h(
          'div',
          {
            class: props.block
              ? 'gf-rule-row__body gf-rule-row__body--block'
              : 'gf-rule-row__body',
          },
          slots.default?.(),
        ),
      ])
  },
})

const Choice = defineComponent({
  name: 'Choice',
  props: {
    name: { type: String, required: true },
    label: { type: String, required: true },
    checked: { type: Boolean, required: true },
    disabled: { type: Boolean, default: false },
  },
  emits: ['pick'],
  setup(props, { emit }) {
    return () =>
      h(
        'label',
        {
          class: [
            'gf-choice',
            props.checked ? 'gf-choice--on' : '',
            props.disabled ? 'gf-choice--off' : '',
          ],
        },
        [
          h('input', {
            type: 'radio',
            name: props.name,
            class: 'gf-choice__input',
            checked: props.checked,
            disabled: props.disabled,
            onChange: () => {
              if (!props.disabled) emit('pick')
            },
          }),
          h('span', { class: 'gf-choice__text' }, props.label),
        ],
      )
  },
})

const selectedType = ref(session.pendingCreateGameType || GameType.PAODEKUAI)
const formError = ref('')
const creating = ref(false)
const confirmOpen = ref(false)
const createdOpen = ref(false)
const createdRoomId = ref('')
const createdMeta = ref('')
const copyHint = ref('')

/** @type {import('vue').Ref<Array<{id:number,name:string,price:string,round_count:number,quantity?:number}>>} */
const cardOptions = ref([])
const cardsLoading = ref(false)

onMounted(async () => {
  if (session.pendingCreateGameType) {
    selectedType.value = session.pendingCreateGameType
    session.pendingCreateGameType = 0
  }
  await loadRoomCards()
})

const form = reactive({
  cardTypeId: 0,
  df: 1,
  askMode: 2,
  bombMultiple: 10,
  modeIndex: 0,
  playerCount: 3,
  maxFollow: 5,
  lucky: 2,
  playerNum: 4,
})

const selectedGame = computed(() => hallGames.find((g) => g.gameType === selectedType.value) ?? hallGames[0])
const isLandlordLike = computed(
  () => selectedType.value === GameType.LANDLORD || selectedType.value === GameType.LAIZI_LANDLORD,
)
const ddzCreateRules = computed(() =>
  selectedType.value === GameType.LAIZI_LANDLORD ? laiziRules : landlordRules,
)

const niuBottomOptions = computed(() => parseRuleKvList(niuCreateRoomRules.bottom_points))
const flowerBottomOptions = computed(() => parseRuleKvList(flowerCreateRoomRules.bottom_points))
const menCap = computed(() => menMaxFromFollow(Number(form.maxFollow) || 5))

const selectedCard = computed(() => cardOptions.value.find((c) => c.id === form.cardTypeId) || null)
const selectedCardQty = computed(() => Number(selectedCard.value?.quantity ?? 0))

const btnOrangeLong = art('ui', 'dizhu', 'btn_orange_long.png')
const btnGreenLong = art('ui', 'dizhu', 'btn_green_long.png')
const backImg = art('hall', 'back.png')

function same(a, b) {
  return String(a) === String(b)
}

async function loadRoomCards() {
  cardsLoading.value = true
  try {
    const [types, mine] = await Promise.all([fetchRoomCardTypes(), fetchMyRoomCards()])
    const qtyMap = new Map(mine.list.map((row) => [Number(row.type_id), Number(row.quantity) || 0]))
    cardOptions.value = types.map((t) => ({
      id: Number(t.id),
      name: String(t.name || ''),
      price: String(t.price ?? '0'),
      round_count: Number(t.round_count) || 0,
      quantity: qtyMap.get(Number(t.id)) ?? 0,
    }))
    pickDefaultCard()
  } catch (err) {
    formError.value = err instanceof Error ? err.message : '加载房卡失败'
  } finally {
    cardsLoading.value = false
  }
}

function pickDefaultCard() {
  if (!cardOptions.value.length) {
    form.cardTypeId = 0
    return
  }
  const current = cardOptions.value.find((c) => c.id === form.cardTypeId && c.quantity > 0)
  if (current) return
  const owned = cardOptions.value.find((c) => c.quantity > 0)
  form.cardTypeId = (owned || cardOptions.value[0]).id
}

function resetForm() {
  formError.value = ''
  form.df = 1
  form.askMode = 2
  form.bombMultiple = 10
  form.modeIndex = 0
  form.playerCount = 3
  form.maxFollow = 5
  form.lucky = 2
  form.playerNum = 4
  form.df = 1
  if (selectedType.value === GameType.NIU) {
    form.df = niuBottomOptions.value[0]?.value ?? '1'
  }
  if (selectedType.value === GameType.ZJH) {
    form.df = flowerBottomOptions.value[0]?.value ?? '1'
  }
  if (selectedType.value === GameType.HZMJ) {
    form.df = hzmjRules.dfOptions?.[0]?.value ?? 1
  }
  pickDefaultCard()
}

watch(selectedType, () => resetForm(), { immediate: true })

function selectGame(item) {
  selectedType.value = item.gameType
}

function backLobby() {
  goLobby()
}

function clamp(n, min, max) {
  return Math.min(max, Math.max(min, Math.round(n) || min))
}

function normalizeNiuCount() {
  form.playerCount = clamp(Number(form.playerCount), niuMinPlayers, niuMaxPlayers)
}

function changeNiuCount(offset) {
  form.playerCount = Number(form.playerCount) + offset
  normalizeNiuCount()
}

function normalizeFlowerCount() {
  form.playerCount = clamp(Number(form.playerCount), flowerMinPlayers, flowerMaxPlayers)
}

function changeFlowerCount(offset) {
  form.playerCount = Number(form.playerCount) + offset
  normalizeFlowerCount()
}

function cardRuleFields() {
  const card = selectedCard.value
  const rounds = Number(card?.round_count) || 6
  const df = Number(form.df) || 1
  return {
    room_card_type_id: Number(form.cardTypeId) || 0,
    room_card_name: card?.name || '',
    total_play_count: rounds,
    zjs: rounds,
    pay_amount: 0,
    pay_mode_text: '房卡',
    entry_points: df * 5,
  }
}

function buildRule() {
  const type = selectedType.value
  const card = cardRuleFields()
  if (type === GameType.LANDLORD || type === GameType.LAIZI_LANDLORD) {
    const rules = type === GameType.LAIZI_LANDLORD ? laiziRules : landlordRules
    const ask = rules.askModes.find((x) => same(form.askMode, x.value))
    const df = Number(form.df) || 1
    return {
      ...card,
      df,
      ask_mode: form.askMode,
      ask_mode_text: ask?.label || '抢庄地主',
      seats: rules.seats,
    }
  }
  if (type === GameType.PAODEKUAI) {
    const df = Number(form.df) || 1
    return {
      ...card,
      df,
      bomb_multiple: form.bombMultiple,
      seats: pdkRules.seats,
    }
  }
  if (type === GameType.NIU) {
    const mode = createRoomModes[form.modeIndex] || createRoomModes[0]
    const dfNum = Number(form.df)
    const df = Number.isFinite(dfNum) ? dfNum : form.df
    return {
      ...card,
      mode_id: mode.id,
      mode_key: mode.key,
      mode_name: mode.name,
      seats: form.playerCount,
      bottom_points: form.df,
      df,
      fee: 0,
      win: niuCreateRoomRules.win,
      desc: niuCreateRoomRules.desc,
    }
  }
  if (type === GameType.ZJH) {
    const dfNum = Number(form.df)
    const df = Number.isFinite(dfNum) ? dfNum : form.df
    const maxFollow = Number(form.maxFollow) || 5
    return {
      ...card,
      mode_key: 'winner',
      mode_name: '赢家当庄',
      seats: form.playerCount,
      bottom_points: form.df,
      df,
      ante: df,
      fee: 0,
      max_follow: maxFollow,
      men_max: menMaxFromFollow(maxFollow),
    }
  }
  return {
    ...card,
    lucky_num: form.lucky,
    seats: form.playerNum,
    df: Number(form.df) || 1,
    bottom_points: Number(form.df) || 1,
    banker_mode: 'grab',
    entry_points: (Number(form.df) || 1) * 5,
  }
}

/** 创建前核对用的规则摘要 */
const ruleSummaryLines = computed(() => {
  const game = selectedGame.value
  const type = selectedType.value
  const card = selectedCard.value
  const lines = [
    { label: '玩法', value: game?.name || '—' },
    {
      label: '房卡',
      value: card
        ? `${card.name}（${card.round_count}局）· 余${Number(card.quantity) || 0}张`
        : '未选择',
    },
  ]
  if (type === GameType.LANDLORD || type === GameType.LAIZI_LANDLORD) {
    const rules = type === GameType.LAIZI_LANDLORD ? laiziRules : landlordRules
    const ask = rules.askModes.find((x) => same(form.askMode, x.value))
    lines.push(
      { label: '人数', value: `${rules.seats}人` },
      { label: '底分', value: `${form.df}金币` },
      { label: '规则', value: ask?.label || '—' },
    )
  } else if (type === GameType.PAODEKUAI) {
    lines.push(
      { label: '人数', value: `${pdkRules.seats}人` },
      { label: '底分', value: `${form.df}金币` },
      { label: '炸弹倍数', value: String(form.bombMultiple) },
    )
  } else if (type === GameType.NIU) {
    const mode = createRoomModes[form.modeIndex] || createRoomModes[0]
    lines.push(
      { label: '模式', value: mode?.name || '—' },
      { label: '人数', value: `${form.playerCount}人` },
      { label: '底分', value: `${form.df}金币` },
    )
  } else if (type === GameType.ZJH) {
    lines.push(
      { label: '人数', value: `${form.playerCount}人` },
      { label: '底分', value: `${form.df}金币` },
      { label: '最大跟注', value: String(form.maxFollow) },
      { label: '闷牌上限', value: String(menCap.value) },
    )
  } else if (type === GameType.HZMJ) {
    const lucky = hzmjRules.luckyOptions?.find((x) => same(form.lucky, x.value))
    lines.push(
      { label: '人数', value: `${form.playerNum}人` },
      { label: '底分', value: `${form.df}金币` },
      { label: '奖码', value: lucky?.label || String(form.lucky) },
    )
  }
  return lines
})

function ensureCanCreate() {
  if (!form.cardTypeId) {
    formError.value = '请选择房卡'
    return false
  }
  if (selectedCardQty.value < 1) {
    formError.value = '该房卡不足，请选择其他类型或联系管理员'
    return false
  }
  const df = Number(form.df ?? 1)
  const need = (Number.isFinite(df) ? df : 1) * 5
  if (need > 0 && Number(session.user.gold) < need) {
    formError.value = `金币数量至少是底分的5倍（底分 ${df}，需至少 ${need}）`
    return false
  }
  return true
}

function openCreateConfirm() {
  const game = selectedGame.value
  if (!game || creating.value) return
  formError.value = ''
  if (!ensureCanCreate()) return
  confirmOpen.value = true
}

function closeCreateConfirm() {
  if (creating.value) return
  confirmOpen.value = false
}

async function confirmCreate() {
  const game = selectedGame.value
  if (!game || creating.value) return
  formError.value = ''
  copyHint.value = ''
  if (!ensureCanCreate()) {
    confirmOpen.value = false
    return
  }

  const rule = buildRule()
  creating.value = true
  try {
    const created = await createRoomHttp({
      gameType: game.gameType,
      num: Number(rule.seats ?? 3),
      rule,
    })
    const roomId = created.roomId
    const gt = created.gameType > 0 ? created.gameType : game.gameType
    applyCreatedRoom({
      roomId,
      gameType: gt,
      playName: game.name,
    })
    allowAutoResume()
    await joinRoomWs(roomId)
    // 已在原房：直接进桌，不弹「创建成功」
    if (created.alreadyIn) {
      confirmOpen.value = false
      const { goGameTable } = await import('../services/gameState')
      goGameTable()
      return
    }
    await loadRoomCards()
    createdRoomId.value = roomId
    const rounds = rule.total_play_count || selectedCard.value?.round_count || ''
    createdMeta.value = `${game.name} · ${rounds}局 · 底分 ${rule.df ?? 1}金币`
    confirmOpen.value = false
    createdOpen.value = true
  } catch (err) {
    if (await recoverAlreadyInRoom(err)) {
      confirmOpen.value = false
      return
    }
    formError.value = err instanceof Error ? err.message : '创建房间失败'
    confirmOpen.value = false
    await loadRoomCards().catch(() => {})
  } finally {
    creating.value = false
  }
}

async function copyRoomId() {
  const text = createdRoomId.value
  if (!text) return
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
    } else {
      const input = document.createElement('input')
      input.value = text
      input.setAttribute('readonly', 'readonly')
      input.className = 'fixed left-0 top-0 opacity-0'
      document.body.appendChild(input)
      input.select()
      document.execCommand('copy')
      document.body.removeChild(input)
    }
    copyHint.value = `已复制：${text}`
  } catch {
    copyHint.value = '复制失败，请手动记下房号'
  }
}

function enterCreatedRoom() {
  createdOpen.value = false
  const rid = String(createdRoomId.value || session.room.roomId || '').trim()
  if (!rid || !/^\d{4,8}$/.test(rid)) {
    formError.value = '缺少房间号，无法进入牌桌'
    return
  }
  session.room.roomId = rid
  goGameTable()
}

function closeCreated() {
  createdOpen.value = false
}
</script>

<template>
  <div class="relative flex h-full w-full overflow-hidden text-white">

    <div class="relative z-10 flex h-full w-full flex-col">
      <header class="flex h-[52px] shrink-0 items-center justify-between px-4">
        <button type="button" class="gf-back flex items-center gap-1 rounded-full px-1 py-0.5" @click="backLobby">
          <img :src="backImg" alt="返回" class="h-10 w-10 object-contain" />
        </button>
        <p class="text-[18px] font-extrabold tracking-wide text-amber-100">
          创建房间 · {{ selectedGame?.name }}
        </p>
        <span class="w-10" />
      </header>

      <div class="grid min-h-0 flex-1 grid-cols-[168px_minmax(0,1fr)] items-stretch gap-4 px-5 pb-4">
        <nav
          class="flex min-h-0 flex-col items-stretch gap-2 overflow-y-auto rounded-2xl border border-white/10 bg-black/40 px-2.5 py-3"
        >
          <p class="mb-0.5 px-1 text-[13px] font-semibold tracking-wide text-amber-100/90">棋牌种类</p>
          <button
            v-for="item in hallGames"
            :key="item.gameType"
            type="button"
            class="gf-game-btn w-full px-2 py-1 text-center text-[14px] font-extrabold text-white transition-transform active:scale-[0.97]"
            :class="selectedType === item.gameType ? 'gf-game-btn--on' : 'gf-game-btn--off'"
            :style="{
              backgroundImage: `url(${selectedType === item.gameType ? btnOrangeLong : btnGreenLong})`,
            }"
            @click="selectGame(item)"
          >
            {{ item.name }}
          </button>
        </nav>

        <section class="gf-rules-panel relative flex h-full min-h-0 w-full flex-col overflow-hidden text-[#3d2a14]">
          <div class="pointer-events-none absolute inset-0 gf-rules-panel__bg" />
          <div class="pointer-events-none absolute inset-x-0 top-0 z-0 h-12 rounded-t-[18px] bg-gradient-to-b from-white/40 to-transparent" />
          <div class="relative z-[1] min-h-0 flex-1 overflow-y-auto px-5 py-4">
            <p class="gf-tip mb-4">
              创建消耗 1 张房卡 · 未开局解散自动返还 · 局数由房卡决定
            </p>

            <RuleRow title="房卡局数">
              <p v-if="cardsLoading" class="text-[13px] text-[#8a5a28]/75">加载中…</p>
              <template v-else-if="cardOptions.length">
                <Choice
                  v-for="item in cardOptions"
                  :key="item.id"
                  name="cardType"
                  :label="`${item.name}（余${item.quantity || 0}）`"
                  :checked="same(form.cardTypeId, item.id)"
                  :disabled="!(item.quantity > 0)"
                  @pick="form.cardTypeId = item.id"
                />
              </template>
              <p v-else class="text-[13px] text-rose-700">暂无可用房卡类型</p>
            </RuleRow>

            <template v-if="isLandlordLike">
              <RuleRow title="底分">
                <Choice
                  v-for="item in ddzCreateRules.dfOptions"
                  :key="item.value"
                  name="df"
                  :label="item.label"
                  :checked="same(form.df, item.value)"
                  @pick="form.df = item.value"
                />
              </RuleRow>
              <RuleRow title="规则">
                <Choice
                  v-for="item in ddzCreateRules.askModes"
                  :key="item.value"
                  name="askMode"
                  :label="item.label"
                  :checked="same(form.askMode, item.value)"
                  @pick="form.askMode = item.value"
                />
              </RuleRow>
            </template>

            <template v-else-if="selectedType === GameType.PAODEKUAI">
              <RuleRow title="底分">
                <Choice
                  v-for="item in pdkRules.dfOptions"
                  :key="item.value"
                  name="df"
                  :label="item.label"
                  :checked="same(form.df, item.value)"
                  @pick="form.df = item.value"
                />
              </RuleRow>
              <RuleRow title="炸弹分">
                <Choice
                  v-for="item in pdkRules.bombMultipleOptions"
                  :key="item.value"
                  name="bomb"
                  :label="item.label"
                  :checked="same(form.bombMultiple, item.value)"
                  @pick="form.bombMultiple = item.value"
                />
              </RuleRow>
            </template>

            <template v-else-if="selectedType === GameType.NIU">
              <RuleRow title="模式">
                <Choice
                  v-for="(mode, idx) in createRoomModes"
                  :key="mode.id"
                  name="niuMode"
                  :label="mode.name"
                  :checked="form.modeIndex === idx"
                  @pick="form.modeIndex = idx"
                />
              </RuleRow>
              <RuleRow title="人数">
                <div class="flex items-center gap-3">
                  <button type="button" class="gf-stepper" @click="changeNiuCount(-1)">−</button>
                  <input
                    v-model.number="form.playerCount"
                    type="number"
                    min="2"
                    max="10"
                    class="gf-stepper-input"
                    @change="normalizeNiuCount"
                  />
                  <span class="text-[15px] font-semibold text-[#5a3a18]">人</span>
                  <button type="button" class="gf-stepper" @click="changeNiuCount(1)">＋</button>
                </div>
              </RuleRow>
              <RuleRow title="底分">
                <Choice
                  v-for="item in niuBottomOptions"
                  :key="item.value"
                  name="df"
                  :label="item.label"
                  :checked="same(form.df, item.value)"
                  @pick="form.df = item.value"
                />
              </RuleRow>
            </template>

            <template v-else-if="selectedType === GameType.ZJH">
              <RuleRow title="人数">
                <div class="flex items-center gap-3">
                  <button type="button" class="gf-stepper" @click="changeFlowerCount(-1)">−</button>
                  <input
                    v-model.number="form.playerCount"
                    type="number"
                    min="2"
                    max="10"
                    class="gf-stepper-input"
                    @change="normalizeFlowerCount"
                  />
                  <span class="text-[15px] font-semibold text-[#5a3a18]">人</span>
                  <button type="button" class="gf-stepper" @click="changeFlowerCount(1)">＋</button>
                </div>
              </RuleRow>
              <RuleRow title="底分">
                <Choice
                  v-for="item in flowerBottomOptions"
                  :key="item.value"
                  name="df"
                  :label="item.label"
                  :checked="same(form.df, item.value)"
                  @pick="form.df = item.value"
                />
              </RuleRow>
              <RuleRow title="最大跟注">
                <Choice
                  v-for="item in flowerRules.maxFollowOptions"
                  :key="item.value"
                  name="maxFollow"
                  :label="item.label"
                  :checked="same(form.maxFollow, item.value)"
                  @pick="form.maxFollow = item.value"
                />
              </RuleRow>
            </template>

            <template v-else-if="selectedType === GameType.HZMJ">
              <RuleRow title="底分">
                <Choice
                  v-for="item in hzmjRules.dfOptions"
                  :key="item.value"
                  name="hzmjDf"
                  :label="item.label"
                  :checked="same(form.df, item.value)"
                  @pick="form.df = item.value"
                />
              </RuleRow>
              <RuleRow title="奖码">
                <Choice
                  v-for="item in hzmjRules.luckyOptions"
                  :key="item.value"
                  name="lucky"
                  :label="item.label"
                  :checked="same(form.lucky, item.value)"
                  @pick="form.lucky = item.value"
                />
              </RuleRow>
              <RuleRow title="人数">
                <Choice
                  v-for="item in hzmjRules.playerNumOptions"
                  :key="item.value"
                  name="playerNum"
                  :label="item.label"
                  :checked="same(form.playerNum, item.value)"
                  @pick="form.playerNum = item.value"
                />
              </RuleRow>
            </template>
          </div>

          <div class="gf-rules-footer relative z-[1] flex flex-col items-center gap-2 px-5 pb-4 pt-2">
            <p v-if="formError" class="text-[14px] font-semibold text-rose-700">{{ formError }}</p>
            <button
              type="button"
              class="gf-create-btn text-[16px] font-extrabold text-white disabled:opacity-60"
              :style="{ backgroundImage: `url(${btnOrangeLong})` }"
              :disabled="creating || cardsLoading"
              @click="openCreateConfirm"
            >
              {{ creating ? '创建中…' : '创建房间' }}
            </button>
          </div>
        </section>
      </div>
    </div>

    <AppDialog
      :show="confirmOpen"
      title="确认房间规则"
      width="480px"
      min-height="260px"
      closable
      @close="closeCreateConfirm"
    >
      <p class="mb-2 text-center text-[13px] leading-5 text-[#5a3a18]/7">请核对以下规则，确认无误后再创建（将消耗 1 张房卡）</p>
      <div class="mx-auto max-h-[360px] w-full overflow-y-auto rounded-lg bg-[#fff8e8]/9 px-3 py-2 ring-1 ring-[#d4a574]/45">
        <div
          v-for="(row, idx) in ruleSummaryLines"
          :key="`${row.label}-${idx}`"
          class="flex gap-2 border-b border-[#d4a574]/25 py-1.5 last:border-b-0"
        >
          <span class="w-20 shrink-0 text-[13px] font-semibold text-[#8a5a28]">{{ row.label }}</span>
          <span class="min-w-0 flex-1 text-[13px] font-medium leading-snug text-[#4a3018]">{{ row.value }}</span>
        </div>
      </div>
      <template #actions>
        <button
          type="button"
          class="gf-btn-green gf-dialog-btn font-extrabold text-white disabled:opacity-60"
          :style="{ backgroundImage: `url(${btnGreenLong})` }"
          :disabled="creating"
          @click="closeCreateConfirm"
        >
          返回修改
        </button>
        <button
          type="button"
          class="gf-btn-orange gf-dialog-btn font-extrabold text-white disabled:opacity-60"
          :style="{ backgroundImage: `url(${btnOrangeLong})` }"
          :disabled="creating"
          @click="confirmCreate"
        >
          {{ creating ? '创建中…' : '确定创建' }}
        </button>
      </template>
    </AppDialog>

    <AppDialog :show="createdOpen" title="房间创建成功" closable @close="closeCreated">
      <p class="text-center text-[14px] text-[#5a3a18]/75">房间号</p>
      <div
        class="mx-auto mt-2 flex h-[44px] w-[240px] items-center justify-center bg-[length:100%_100%] bg-center bg-no-repeat"
        :style="{ backgroundImage: `url(${art('room', 'room_numbers1.png')})` }"
      >
        <RoomNumberDigits :value="createdRoomId" variant="glass" digit-class="h-7" />
      </div>
      <p class="mt-2 text-center text-[14px] font-semibold text-[#5a3a18]/85">
        房主：{{ session.user.nickname || '玩家' }}
      </p>
      <p v-if="createdMeta" class="mt-1.5 text-center text-[12px] text-[#5a3a18]/55">{{ createdMeta }}</p>
      <p class="mt-3 text-center text-[13px] leading-5 text-[#5a3a18]/65">把房间号告诉好友，等他们加入后即可开始游戏</p>
      <p v-if="copyHint" class="mt-1.5 text-center text-[13px] text-emerald-700">{{ copyHint }}</p>
      <template #actions>
        <button
          type="button"
          class="gf-btn-green gf-created-btn font-extrabold text-white"
          :style="{ backgroundImage: `url(${btnGreenLong})` }"
          @click="copyRoomId"
        >
          复制房间号
        </button>
        <button
          type="button"
          class="gf-btn-orange gf-created-btn font-extrabold text-white"
          :style="{ backgroundImage: `url(${btnOrangeLong})` }"
          @click="enterCreatedRoom"
        >
          进入房间
        </button>
      </template>
    </AppDialog>
  </div>
</template>

<style scoped>
.gf-rules-panel {
  min-height: 0;
  border-radius: 20px;
  border: 3px solid rgba(42, 28, 12, 0.85);
  box-shadow:
    0 10px 28px rgba(0, 0, 0, 0.35),
    inset 0 1px 0 rgba(255, 255, 255, 0.35);
}
.gf-rules-panel__bg {
  border-radius: 17px;
  background:
    linear-gradient(165deg, rgba(255, 248, 230, 0.97) 0%, rgba(244, 228, 188, 0.96) 48%, rgba(232, 208, 156, 0.95) 100%);
}
.gf-tip {
  display: inline-flex;
  max-width: 100%;
  align-items: center;
  border-radius: 999px;
  border: 1px solid rgba(196, 138, 42, 0.35);
  background: rgba(255, 255, 255, 0.55);
  padding: 0.5rem 1.05rem;
  font-size: 15px;
  font-weight: 600;
  line-height: 1.35;
  color: rgba(90, 58, 24, 0.88);
}
.gf-rule-row {
  border-radius: 14px;
  border: 1px solid rgba(180, 130, 60, 0.28);
  background: rgba(255, 252, 242, 0.72);
  padding: 0.85rem 1rem 0.95rem;
}
.gf-rule-row__title {
  margin: 0 0 0.65rem;
  font-size: 16px;
  font-weight: 800;
  letter-spacing: 0.04em;
  color: #8b3a12;
}
.gf-rule-row__body {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 0.55rem;
}
.gf-rule-row__body--block {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0.4rem;
}
.gf-choice__input {
  position: absolute;
  width: 1px;
  height: 1px;
  margin: -1px;
  padding: 0;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
.gf-choice {
  position: relative;
  display: inline-flex;
  min-height: 48px;
  cursor: pointer;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  border: 1.5px solid rgba(138, 90, 40, 0.28);
  background: rgba(255, 255, 255, 0.78);
  padding: 0.45rem 1.15rem;
  transition: transform 0.12s ease, background 0.12s ease, border-color 0.12s ease;
  touch-action: manipulation;
}
.gf-choice:active {
  transform: scale(0.97);
}
.gf-choice--on {
  border-color: rgba(192, 112, 24, 0.85);
  background: linear-gradient(180deg, #ffe2a0, #f0b24a);
  box-shadow: 0 2px 6px rgba(120, 70, 10, 0.22);
}
.gf-choice--off {
  cursor: not-allowed;
  opacity: 0.42;
}
.gf-choice__text {
  font-size: 16px;
  font-weight: 700;
  line-height: 1.2;
  color: #4a3018;
  white-space: nowrap;
}
.gf-choice--on .gf-choice__text {
  color: #5a2a00;
}
.gf-stepper {
  display: inline-flex;
  height: 48px;
  width: 48px;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: 10px;
  background: rgba(90, 58, 24, 0.12);
  font-size: 22px;
  font-weight: 800;
  color: #4a3018;
  touch-action: manipulation;
}
.gf-stepper-input {
  height: 48px;
  width: 72px;
  border: 1px solid rgba(138, 90, 40, 0.28);
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.92);
  text-align: center;
  font-size: 18px;
  font-weight: 800;
  color: #1a1208;
}
.gf-rules-footer {
  border-top: 1px solid rgba(180, 130, 60, 0.22);
  background: linear-gradient(180deg, rgba(255, 248, 230, 0) 0%, rgba(236, 214, 160, 0.55) 100%);
}
.gf-create-btn,
.gf-btn-orange,
.gf-btn-green,
.gf-game-btn {
  border: 0;
  background-repeat: no-repeat;
  background-position: center;
  background-size: 100% 100%;
}
.gf-btn-orange,
.gf-btn-green {
  width: min(100%, 280px);
  aspect-ratio: 222 / 93;
  height: auto;
  color: #fff;
  text-shadow: 0 2px 0 rgba(80, 40, 0, 0.45);
}
.gf-create-btn {
  width: 200px;
  aspect-ratio: 222 / 93;
  height: auto;
  color: #fff;
  text-shadow: 0 2px 0 rgba(80, 40, 0, 0.45);
}
.gf-btn-green {
  text-shadow: 0 2px 0 rgba(20, 60, 0, 0.35);
}
.gf-btn-orange.gf-dialog-btn,
.gf-btn-green.gf-dialog-btn {
  width: 120px;
  max-width: 120px;
  flex: 0 0 120px;
  aspect-ratio: 222 / 93;
  height: auto;
  padding-top: 0;
  padding-bottom: 0;
  font-size: 14px;
}
.gf-btn-orange.gf-created-btn,
.gf-btn-green.gf-created-btn {
  width: 132px;
  max-width: 132px;
  flex: 0 0 132px;
  aspect-ratio: 222 / 72;
  height: auto;
  padding: 0;
  font-size: 15px;
  letter-spacing: 0.02em;
}
.gf-game-btn {
  width: 100%;
  max-width: 156px;
  aspect-ratio: 222 / 72;
  height: auto;
  min-height: 52px;
  line-height: 1.15;
  letter-spacing: 0.02em;
}
.gf-game-btn--on {
  text-shadow: 0 2px 0 rgba(80, 40, 0, 0.45);
  filter: brightness(1.05);
}
.gf-game-btn--off {
  text-shadow: 0 2px 0 rgba(20, 60, 0, 0.35);
  opacity: 0.92;
  filter: saturate(0.92);
}
.gf-back {
  background: transparent;
  border: 0;
}
</style>
