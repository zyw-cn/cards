import { resolveToken, resolveRefreshToken, persistAuthTokens, session } from './session'
import { forceLogin } from './authRedirect'

export class HttpError extends Error {
  constructor(message, status, body) {
    super(message)
    this.name = 'HttpError'
    this.status = status
    this.body = body
  }
}

function joinUrl(base, path) {
  if (/^https?:\/\//i.test(path)) return path
  const normalizedPath = path.startsWith('/') ? path : `/${path}`
  if (!base) return normalizedPath
  return `${base}${normalizedPath}`
}

async function parseBody(res) {
  const text = await res.text()
  if (!text) return null
  try {
    return JSON.parse(text)
  } catch {
    return text
  }
}

function asApiResponse(raw, httpStatus) {
  if (raw && typeof raw === 'object' && 'code' in raw) {
    return {
      code: Number(raw.code),
      msg: typeof raw.msg === 'string' ? raw.msg : '',
      data: raw.data,
    }
  }
  return {
    code: httpStatus >= 200 && httpStatus < 300 ? 0 : httpStatus,
    msg: httpStatus >= 200 && httpStatus < 300 ? 'ok' : 'request_failed',
    data: raw,
  }
}

function isAuthFail(httpStatus, api) {
  if (httpStatus === 401) return true
  const code = Number(api?.code)
  return code === 401 || (code >= 1001 && code <= 1005)
}

let refreshPromise = null

/** 用 refresh_token 换新 access_token；失败则清会话 */
async function refreshAccessToken() {
  const refresh = resolveRefreshToken()
  if (!refresh) return false
  if (refreshPromise) return refreshPromise

  refreshPromise = (async () => {
    const url = joinUrl(session.apiBase, '/api/login/refresh-token')
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ refresh_token: refresh }),
        credentials: 'omit',
      })
      const raw = await parseBody(res)
      const api = asApiResponse(raw, res.status)
      if (!res.ok || api.code !== 0 || !api.data) return false
      const access = String(api.data.access_token ?? api.data.token ?? '')
      if (!access) return false
      // 后端 refresh 目前只发新 access；有新 refresh 则一并更新
      persistAuthTokens({
        accessToken: access,
        refreshToken: api.data.refresh_token ?? api.data.refreshToken,
      })
      return true
    } catch {
      return false
    } finally {
      refreshPromise = null
    }
  })()

  return refreshPromise
}

class HttpClient {
  async post(path, body, { _retried } = {}) {
    const url = joinUrl(session.apiBase, path)
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    }
    const token = resolveToken()
    if (token) headers.Authorization = `Bearer ${token}`

    const res = await fetch(url, {
      method: 'POST',
      headers,
      body: JSON.stringify(body ?? {}),
      credentials: 'omit',
    })

    const raw = await parseBody(res)
    const api = asApiResponse(raw, res.status)
    const skipRefresh = String(path).includes('/login/refresh-token') || String(path).includes('/login/index')

    if (isAuthFail(res.status, api) && !_retried && !skipRefresh) {
      const ok = await refreshAccessToken()
      if (ok) return this.post(path, body, { _retried: true })
      forceLogin()
      throw new HttpError('unauthorized', 401, raw)
    }

    if (res.status === 401) {
      forceLogin()
      throw new HttpError('unauthorized', 401, raw)
    }

    if (!res.ok) {
      throw new HttpError(`http_${res.status}`, res.status, raw)
    }

    if (isAuthFail(res.status, api)) {
      forceLogin()
      throw new HttpError('unauthorized', 401, raw)
    }
    return api
  }
}

export const http = new HttpClient()
export { refreshAccessToken }
