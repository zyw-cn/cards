<script setup>
import { computed, onUnmounted, reactive, ref } from 'vue'
import { art } from '../utils/art'
import ServerCaptchaImage from '../components/ServerCaptchaImage.vue'
import {
  loginByEmail,
  registerByEmail,
  resetPasswordByEmail,
  sendEmailCode,
} from '../services/authApi'
import { gameState, goLobby } from '../services/gameState'
import { wsService } from '../services/gameSocket'
import { tryEnterPendingRoom } from '../services/roomResume'
import { session } from '../services/session'


const busy = ref(false)
const sending = ref(false)
const hint = ref('')
const hintOk = ref(false)
const regSec = ref(0)
const forgotSec = ref(0)
const regCaptchaRef = ref(null)
const forgotCaptchaRef = ref(null)

let regTimer = null
let forgotTimer = null

const loginForm = reactive({ email: '', password: '' })
const regForm = reactive({
  nickname: '',
  email: '',
  captcha: '',
  emailCode: '',
  password: '',
  confirm: '',
})
const forgotForm = reactive({
  email: '',
  captcha: '',
  emailCode: '',
  password: '',
  confirm: '',
})

const title = computed(() => {
  if (session.loginSubView === 'REGISTER') return '注册账号'
  if (session.loginSubView === 'FORGET_PASSWORD') return '忘记密码'
  return ''
})

const subtitle = computed(() => {
  if (session.loginSubView === 'REGISTER') return '使用邮箱完成注册'
  if (session.loginSubView === 'FORGET_PASSWORD') return '通过邮箱验证码重置密码'
  return ''
})

function switchView(view) {
  hint.value = ''
  session.loginSubView = view
}

function startCountdown(kind) {
  if (kind === 'reg') {
    regSec.value = 60
    if (regTimer) clearInterval(regTimer)
    regTimer = setInterval(() => {
      if (regSec.value <= 1) {
        clearInterval(regTimer)
        regTimer = null
        regSec.value = 0
        return
      }
      regSec.value -= 1
    }, 1000)
  } else {
    forgotSec.value = 60
    if (forgotTimer) clearInterval(forgotTimer)
    forgotTimer = setInterval(() => {
      if (forgotSec.value <= 1) {
        clearInterval(forgotTimer)
        forgotTimer = null
        forgotSec.value = 0
        return
      }
      forgotSec.value -= 1
    }, 1000)
  }
}

async function onLogin() {
  if (busy.value) return
  busy.value = true
  hint.value = ''
  const res = await loginByEmail(loginForm.email, loginForm.password)
  hintOk.value = res.ok
  hint.value = res.message
  busy.value = false
  if (!res.ok) return
  if (gameState.currentScreen === 'GAME_TABLE') return
  goLobby()
  try {
    await wsService.connect()
    await tryEnterPendingRoom()
  } catch (err) {
    console.warn('[login] resume room', err)
  }
}

async function onSendRegCode() {
  if (sending.value || regSec.value > 0) return
  sending.value = true
  const res = await sendEmailCode({
    email: regForm.email,
    mark: 'REGISTER',
    captcha: regForm.captcha,
    captchaKey: regCaptchaRef.value?.getKey() || '',
  })
  hintOk.value = res.ok
  hint.value = res.message
  await regCaptchaRef.value?.refresh()
  regForm.captcha = ''
  if (res.ok) startCountdown('reg')
  sending.value = false
}

async function onRegister() {
  if (busy.value) return
  busy.value = true
  const res = await registerByEmail(regForm)
  hintOk.value = res.ok
  hint.value = res.message
  if (!res.ok) {
    await regCaptchaRef.value?.refresh()
    regForm.captcha = ''
  } else {
    loginForm.email = regForm.email
    switchView('FORM')
    hintOk.value = true
    hint.value = res.message
  }
  busy.value = false
}

async function onSendForgotCode() {
  if (sending.value || forgotSec.value > 0) return
  sending.value = true
  const res = await sendEmailCode({
    email: forgotForm.email,
    mark: 'FORGET',
    captcha: forgotForm.captcha,
    captchaKey: forgotCaptchaRef.value?.getKey() || '',
  })
  hintOk.value = res.ok
  hint.value = res.message
  await forgotCaptchaRef.value?.refresh()
  forgotForm.captcha = ''
  if (res.ok) startCountdown('forgot')
  sending.value = false
}

async function onForgot() {
  if (busy.value) return
  busy.value = true
  const res = await resetPasswordByEmail(forgotForm)
  hintOk.value = res.ok
  hint.value = res.message
  if (!res.ok) {
    await forgotCaptchaRef.value?.refresh()
    forgotForm.captcha = ''
  } else {
    switchView('FORM')
    hintOk.value = true
    hint.value = res.message
  }
  busy.value = false
}

onUnmounted(() => {
  if (regTimer) clearInterval(regTimer)
  if (forgotTimer) clearInterval(forgotTimer)
})
</script>

<template>
  <div class="relative h-full w-full overflow-hidden">

    <img
      :src="art('hero', 'character.png')"
      class="float-y pointer-events-none absolute bottom-0 left-[4%] z-10 h-[92%] max-w-none object-contain drop-shadow-2xl"
      alt=""
    />

    <div class="absolute right-[5%] top-[8%] z-20 flex w-[440px] max-h-[88%] flex-col items-center">
      <img :src="art('misc', 'LOGO2.png')" class="mb-4 h-[72px] object-contain drop-shadow-xl" alt="贝密游戏" />

      <div
        class="relative w-full max-h-full overflow-y-auto overscroll-contain rounded-2xl border border-white/15 bg-black/45 px-7 py-6 backdrop-blur-md"
        style="touch-action: pan-y"
      >
        <img
          v-if="session.loginSubView === 'FORM'"
          :src="art('misc', 'login.png')"
          class="mx-auto mb-4 h-8 object-contain"
          alt="账号登录"
        />
        <h1
          v-else
          class="mb-1 text-center text-[26px] font-extrabold tracking-wide text-[#ffe9a8]"
          style="text-shadow: 0 2px 0 #4a2810"
        >
          {{ title }}
        </h1>
        <p v-if="subtitle" class="mb-4 text-center text-sm text-white/70">{{ subtitle }}</p>

        <!-- 登录 -->
        <form
          v-if="session.loginSubView === 'FORM'"
          class="flex flex-col gap-3"
          @submit.prevent="onLogin"
        >
          <label class="auth-row">
            <img :src="art('misc', 'account.png')" class="auth-row__icon" alt="" />
            <input
              v-model="loginForm.email"
              type="email"
              inputmode="email"
              autocomplete="username"
              autocapitalize="none"
              autocorrect="off"
              spellcheck="false"
              enterkeyhint="next"
              class="auth-field"
              placeholder="请输入邮箱"
            />
          </label>
          <label class="auth-row">
            <img :src="art('misc', 'password.png')" class="auth-row__icon" alt="" />
            <input
              v-model="loginForm.password"
              type="password"
              autocomplete="current-password"
              enterkeyhint="done"
              class="auth-field"
              placeholder="密码至少 6 位"
            />
          </label>

          <p v-if="hint" class="text-sm" :class="hintOk ? 'text-emerald-300' : 'text-rose-300'">{{ hint }}</p>

          <div class="flex items-center justify-between gap-3">
            <button type="button" class="text-sm text-amber-200/90" @click="switchView('FORGET_PASSWORD')">
              忘记密码？
            </button>
            <button type="button" class="h-9 transition hover:brightness-110" @click="switchView('REGISTER')">
              <img :src="art('guest_login', 'btn_register.png')" class="h-full object-contain" alt="注册" />
            </button>
          </div>

          <button type="submit" class="auth-primary mx-auto mt-1" :disabled="busy" :aria-busy="busy">
            <img
              v-if="!busy"
              :src="art('misc', 'loginbtn.png')"
              class="auth-primary__bg"
              alt="登录"
            />
            <span v-else class="auth-primary__label auth-primary__label--plain">登录中…</span>
          </button>
        </form>

        <!-- 注册 -->
        <form
          v-else-if="session.loginSubView === 'REGISTER'"
          class="flex flex-col gap-2.5"
          @submit.prevent="onRegister"
        >
          <input
            v-model="regForm.nickname"
            type="text"
            required
            maxlength="16"
            placeholder="昵称（必填）"
            autocomplete="nickname"
            class="auth-input"
          />
          <input v-model="regForm.email" type="email" placeholder="邮箱" class="auth-input" autocomplete="email" />
          <div class="flex gap-2">
            <input
              v-model="regForm.captcha"
              type="text"
              maxlength="8"
              placeholder="图形验证码（防刷）"
              autocomplete="off"
              class="auth-input auth-input--short mb-0 flex-1"
            />
            <ServerCaptchaImage ref="regCaptchaRef" />
          </div>
          <div class="flex gap-2">
            <input
              v-model="regForm.emailCode"
              type="tel"
              maxlength="6"
              placeholder="邮箱验证码"
              class="auth-input auth-input--short mb-0 flex-1"
            />
            <button
              type="button"
              class="auth-code-btn"
              :style="{ backgroundImage: `url(${art('guest_login', 'orange_btn_sm.png')})` }"
              :disabled="regSec > 0 || sending"
              @click="onSendRegCode"
            >
              {{ regSec > 0 ? `${regSec}s` : sending ? '发送中' : '发送验证码' }}
            </button>
          </div>
          <input v-model="regForm.password" type="password" placeholder="密码至少 6 位" class="auth-input" />
          <input v-model="regForm.confirm" type="password" placeholder="确认密码" class="auth-input" />
          <p v-if="hint" class="text-sm" :class="hintOk ? 'text-emerald-300' : 'text-rose-300'">{{ hint }}</p>
          <button type="submit" class="auth-primary mx-auto mt-2" :disabled="busy">
            <img :src="art('guest_login', 'orange_btn_sm.png')" class="auth-primary__bg" alt="" />
            <span class="auth-primary__label">{{ busy ? '提交中…' : '注 册' }}</span>
          </button>
          <button type="button" class="mt-1 text-sm text-amber-200" @click="switchView('FORM')">
            已有账号？去登录
          </button>
        </form>

        <!-- 忘记密码 -->
        <form v-else class="flex flex-col gap-2.5" @submit.prevent="onForgot">
          <input
            v-model="forgotForm.email"
            type="email"
            placeholder="注册邮箱"
            class="auth-input"
            autocomplete="email"
          />
          <div class="flex gap-2">
            <input
              v-model="forgotForm.captcha"
              type="text"
              maxlength="8"
              placeholder="图形验证码"
              autocomplete="off"
              class="auth-input auth-input--short mb-0 flex-1"
            />
            <ServerCaptchaImage ref="forgotCaptchaRef" />
          </div>
          <div class="flex gap-2">
            <input
              v-model="forgotForm.emailCode"
              type="tel"
              maxlength="6"
              placeholder="邮箱验证码"
              class="auth-input auth-input--short mb-0 flex-1"
            />
            <button
              type="button"
              class="auth-code-btn"
              :style="{ backgroundImage: `url(${art('guest_login', 'orange_btn_sm.png')})` }"
              :disabled="forgotSec > 0 || sending"
              @click="onSendForgotCode"
            >
              {{ forgotSec > 0 ? `${forgotSec}s` : sending ? '发送中' : '获取验证码' }}
            </button>
          </div>
          <input v-model="forgotForm.password" type="password" placeholder="新密码至少 6 位" class="auth-input" />
          <input v-model="forgotForm.confirm" type="password" placeholder="确认新密码" class="auth-input" />
          <p v-if="hint" class="text-sm" :class="hintOk ? 'text-emerald-300' : 'text-rose-300'">{{ hint }}</p>
          <button type="submit" class="auth-primary mx-auto mt-2" :disabled="busy">
            <img :src="art('guest_login', 'orange_btn_sm.png')" class="auth-primary__bg" alt="" />
            <span class="auth-primary__label">{{ busy ? '提交中…' : '重置密码' }}</span>
          </button>
          <button type="button" class="mt-1 text-sm text-amber-200" @click="switchView('FORM')">
            返回登录
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.auth-row {
  position: relative;
  display: flex;
  height: 60px;
  width: 100%;
  align-items: center;
  gap: 0.5rem;
  padding: 0 1rem 0 0.85rem;
  cursor: text;
  border-radius: 999px;
  border: 1.5px solid rgba(255, 248, 230, 0.95);
  background: linear-gradient(180deg, #f3e6d0 0%, #dfc4a0 48%, #d0b086 100%);
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.55),
    inset 0 -1px 1px rgba(90, 50, 10, 0.12);
}

.auth-row__icon {
  width: 22px;
  height: 22px;
  flex-shrink: 0;
  object-fit: contain;
  opacity: 0.85;
}

.auth-field {
  height: 100%;
  width: 100%;
  min-width: 0;
  flex: 1;
  border: 0;
  padding: 0;
  background: transparent;
  font-size: 17px;
  line-height: 60px;
  font-weight: 600;
  color: #3a2418;
  outline: none;
  -webkit-appearance: none;
  appearance: none;
  -webkit-user-select: text;
  user-select: text;
  touch-action: manipulation;
}

.auth-field::placeholder {
  color: rgba(58, 36, 24, 0.45);
  font-weight: 500;
}

.auth-field:-webkit-autofill,
.auth-field:-webkit-autofill:hover,
.auth-field:-webkit-autofill:focus {
  -webkit-text-fill-color: #3a2418;
  caret-color: #3a2418;
  transition: background-color 99999s ease-out;
  box-shadow: 0 0 0 1000px transparent inset;
}

.auth-input {
  width: 100%;
  height: 56px;
  margin-bottom: 0;
  border: 1.5px solid rgba(255, 248, 230, 0.95);
  border-radius: 999px;
  padding: 0 1rem;
  font-size: 16px;
  line-height: 56px;
  color: #3a2418;
  font-weight: 600;
  outline: none;
  background: linear-gradient(180deg, #f3e6d0 0%, #dfc4a0 48%, #d0b086 100%);
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.55),
    inset 0 -1px 1px rgba(90, 50, 10, 0.12);
  -webkit-appearance: none;
  appearance: none;
  -webkit-user-select: text;
  user-select: text;
  touch-action: manipulation;
}

.auth-input::placeholder {
  color: rgba(58, 36, 24, 0.45);
}

.auth-input--short {
  flex: 1;
  min-width: 0;
}

.auth-input:-webkit-autofill,
.auth-input:-webkit-autofill:hover,
.auth-input:-webkit-autofill:focus {
  -webkit-text-fill-color: #3a2418;
  caret-color: #3a2418;
  transition: background-color 99999s ease-out;
  box-shadow: 0 0 0 1000px #dfc4a0 inset;
}

.auth-code-btn {
  min-width: 6.2rem;
  height: 3.25rem;
  flex-shrink: 0;
  border: 0;
  border-radius: 0.65rem;
  padding: 0 0.6rem;
  font-size: 0.95rem;
  font-weight: 700;
  color: #fff;
  text-shadow: 0 1px 0 rgba(80, 40, 0, 0.45);
  background-size: 100% 100%;
  background-position: center;
  background-repeat: no-repeat;
}

.auth-code-btn:disabled {
  opacity: 0.55;
}

.auth-primary {
  position: relative;
  display: flex;
  width: 300px;
  height: 70px;
  align-items: center;
  justify-content: center;
  border: 0;
  background: transparent;
  padding: 0;
  cursor: pointer;
}

.auth-primary:disabled {
  opacity: 0.65;
}

.auth-primary__bg {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: contain;
  pointer-events: none;
}

.auth-primary__label {
  position: relative;
  z-index: 1;
  display: flex;
  height: 100%;
  align-items: center;
  justify-content: center;
  font-size: 1.25rem;
  font-weight: 800;
  color: #fff;
  text-shadow: 0 2px 0 rgba(80, 40, 0, 0.45);
  pointer-events: none;
}

.auth-primary__label--plain {
  width: 100%;
  border-radius: 999px;
  background: linear-gradient(180deg, #ffb24a, #e07a20);
}
</style>
