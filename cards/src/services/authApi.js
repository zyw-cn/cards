import { http } from './http'
import { applyUserProfile, persistAuthTokens, persistToken, session } from './session'
import { wsService } from './gameSocket'
import { allowAutoResume } from './roomResume'

function ok(message) {
  return { ok: true, message }
}

function fail(message) {
  return { ok: false, message }
}

export function isEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
}

/** 仅显式开启时走本地演示，避免挡住真实注册登录 */
function useAuthDemo() {
  return import.meta.env.DEV && import.meta.env.VITE_AUTH_DEMO === 'true'
}

export async function fetchCaptcha() {
  return http.post('/api/login/captcha', {})
}

export async function sendEmailCode(payload) {
  const email = payload.email.trim().toLowerCase()
  if (!isEmail(email)) return fail('请输入正确邮箱')
  if (!payload.captcha.trim()) return fail('请输入图形验证码')
  if (!payload.captchaKey) return fail('请先获取图形验证码')

  if (useAuthDemo()) {
    return ok('本地演示：验证码已发送（任意 6 位即可）')
  }

  try {
    const res = await http.post('/api/message/send', {
      mark: payload.mark,
      email,
      captcha: payload.captcha.trim(),
      captcha_key: payload.captchaKey,
    })
    if (res.code !== 0) return fail(res.msg || '验证码发送失败')
    return ok(res.msg || '验证码已发送，请查收邮件')
  } catch (err) {
    return fail(err instanceof Error ? err.message : '验证码发送失败')
  }
}

export async function loginByEmail(email, password) {
  const e = email.trim().toLowerCase()
  const pwd = password
  if (!isEmail(e)) return fail('请输入正确邮箱')
  if (pwd.length < 6) return fail('密码至少 6 位')

  if (useAuthDemo()) {
    persistToken(`local_${Date.now()}`)
    applyUserProfile({
      uid: 1,
      nickname: e.split('@')[0] || '玩家',
      gold: 888800,
      cards: 999,
      level: 1,
      token: session.user.token,
    })
    return ok('本地演示登录成功')
  }

  try {
    const res = await http.post('/api/login/index', { email: e, password: pwd })
    if (res.code !== 0 || !res.data) return fail(res.msg || '登录失败')

    const access = String(res.data.access_token ?? res.data.token ?? '')
    const refresh = String(res.data.refresh_token ?? res.data.refreshToken ?? '')
    persistAuthTokens({ accessToken: access, refreshToken: refresh || undefined })
    applyUserProfile({ ...res.data, token: access })
    allowAutoResume()

    try {
      const profile = await http.post('/api/member/index', {})
      if (profile.code === 0 && profile.data) applyUserProfile(profile.data)
    } catch {
      /* 资料失败不阻断 */
    }

    void wsService.connect().catch((err) => console.warn('[auth] ws', err))

    return ok(res.msg || '登录成功')
  } catch (err) {
    return fail(err instanceof Error ? err.message : '登录失败')
  }
}

export async function registerByEmail(payload) {
  const nick = String(payload.nickname || '').trim()
  const e = payload.email.trim().toLowerCase()
  const code = payload.emailCode.trim()
  const pwd = payload.password
  const rep = payload.confirm

  if (!nick) return fail('请输入昵称')
  if ([...nick].length > 16) return fail('昵称不能超过16个字')
  if (!isEmail(e)) return fail('请输入正确邮箱')
  if (!/^\d{6}$/.test(code)) return fail('请输入 6 位邮箱验证码')
  if (pwd.length < 6) return fail('密码至少 6 位')
  if (pwd !== rep) return fail('两次密码不一致')

  if (useAuthDemo()) {
    return ok('本地演示：注册成功，请登录')
  }

  try {
    const res = await http.post('/api/login/register', {
      email: e,
      password: pwd,
      confirm_password: rep,
      code,
      nickname: nick,
      invite_code: '',
    })
    if (res.code !== 0) return fail(res.msg || '注册失败')
    return ok(res.msg || '注册成功')
  } catch (err) {
    return fail(err instanceof Error ? err.message : '注册失败')
  }
}

export async function resetPasswordByEmail(payload) {
  const e = payload.email.trim().toLowerCase()
  const code = payload.emailCode.trim()
  const pwd = payload.password
  const rep = payload.confirm

  if (!isEmail(e)) return fail('请输入正确邮箱')
  if (!/^\d{6}$/.test(code)) return fail('请输入 6 位邮箱验证码')
  if (pwd.length < 6) return fail('密码至少 6 位')
  if (pwd !== rep) return fail('两次密码不一致')

  if (useAuthDemo()) {
    return ok('本地演示：密码已重置，请登录')
  }

  try {
    const res = await http.post('/api/login/forget', {
      email: e,
      code,
      password: pwd,
      confirm_password: rep,
    })
    if (res.code !== 0) return fail(res.msg || '重置失败')
    return ok(res.msg || '密码已重置')
  } catch (err) {
    return fail(err instanceof Error ? err.message : '重置失败')
  }
}
