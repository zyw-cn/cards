/**
 * 舞台用 transform:scale 缩小后，WebView 里输入框的光标和输入法会错位。
 * 聚焦时改用舞台外的原生输入框（不在 transform 里），再把文字同步回去。
 */

type TextControl = HTMLInputElement | HTMLTextAreaElement

const SKIP_TYPES = new Set([
  'button',
  'submit',
  'reset',
  'checkbox',
  'radio',
  'file',
  'range',
  'color',
  'hidden',
  'image',
  'number',
])

let inputProxy: HTMLInputElement | null = null
let areaProxy: HTMLTextAreaElement | null = null
let active: TextControl | null = null
let host: TextControl | null = null
let generation = 0
let getScale: () => number = () => 1
let onPointerDown: ((ev: PointerEvent) => void) | null = null
let onViewport = (() => {}) as () => void

function isTextControl(el: unknown): el is TextControl {
  if (el instanceof HTMLTextAreaElement) return true
  if (!(el instanceof HTMLInputElement)) return false
  return !SKIP_TYPES.has(el.type)
}

function needsProxy(scale: number): boolean {
  if (scale < 0.98) return true
  return window.matchMedia('(hover: none), (pointer: coarse)').matches
}

function controlFromTarget(target: EventTarget | null): TextControl | null {
  if (!(target instanceof Element)) return null
  if (isTextControl(target) && target.closest('#game-stage')) return target
  const box = target.closest('#game-stage label, #game-stage .auth-row')
  const found = box?.querySelector('input, textarea')
  if (isTextControl(found) && !found.disabled && !found.readOnly) return found
  return null
}

function setNativeValue(el: TextControl, value: string) {
  const proto = el instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype
  const desc = Object.getOwnPropertyDescriptor(proto, 'value')
  desc?.set?.call(el, value)
  el.dispatchEvent(new Event('input', { bubbles: true }))
}

function hide(el: TextControl | null) {
  if (!el) return
  el.style.opacity = '0'
  el.style.pointerEvents = 'none'
  el.tabIndex = -1
  el.setAttribute('aria-hidden', 'true')
}

function proxyFor(source: TextControl): TextControl {
  return source instanceof HTMLTextAreaElement ? areaProxy! : inputProxy!
}

function place(proxy: TextControl, source: TextControl) {
  const rect = source.getBoundingClientRect()
  const cs = getComputedStyle(source)
  const scale = getScale() || 1
  const font = Math.max(16, (parseFloat(cs.fontSize) || 16) * scale)
  const vv = window.visualViewport
  const offL = vv?.offsetLeft ?? 0
  const offT = vv?.offsetTop ?? 0
  const viewW = vv?.width ?? window.innerWidth
  const visibleBottom = offT + (vv?.height ?? window.innerHeight)
  let left = rect.left + offL
  let top = rect.top + offT
  let width = rect.width
  let height = Math.max(rect.height, font + 10)
  const detached = top + height > visibleBottom - 8
  if (detached) {
    width = Math.max(width, Math.min(viewW - 16, 420))
    height = Math.max(height, 44)
    top = Math.max(offT + 8, visibleBottom - height - 8)
    left = Math.max(offL + 8, Math.min(left, offL + viewW - width - 8))
  }
  const multiline = proxy instanceof HTMLTextAreaElement
  proxy.tabIndex = 0
  proxy.removeAttribute('aria-hidden')
  proxy.style.opacity = '1'
  proxy.style.pointerEvents = 'auto'
  proxy.style.left = `${left}px`
  proxy.style.top = `${top}px`
  proxy.style.width = `${width}px`
  proxy.style.height = `${height}px`
  proxy.style.fontSize = `${font}px`
  proxy.style.lineHeight = multiline ? '1.45' : `${height}px`
  proxy.style.padding = multiline ? '8px 12px' : '0 2px'
  proxy.style.color = '#3a2418'
  proxy.style.fontWeight = cs.fontWeight
  proxy.style.fontFamily = cs.fontFamily
  proxy.style.background = detached
    ? 'linear-gradient(180deg, #f3e6d0 0%, #dfc4a0 48%, #d0b086 100%)'
    : 'transparent'
  proxy.style.borderRadius = detached ? '999px' : cs.borderRadius
  proxy.style.boxShadow = detached ? '0 8px 24px rgba(0,0,0,.35)' : 'none'
}

function reposition() {
  if (!active || !host || !host.isConnected) return
  place(active, host)
}

function close() {
  if (host) delete host.dataset.imeHost
  host = null
  hide(inputProxy)
  hide(areaProxy)
  active = null
}

function onInput() {
  if (!active || !host) return
  setNativeValue(host, active.value)
}

function onKey(ev: KeyboardEvent) {
  if (ev.key !== 'Enter' || !host) return
  if (host instanceof HTMLTextAreaElement) return
  ev.preventDefault()
  host.form?.requestSubmit()
}

function onBlur() {
  const gen = generation
  window.setTimeout(() => {
    if (gen !== generation) return
    if (active && document.activeElement === active) return
    close()
  }, 120)
}

function bindProxy(el: TextControl) {
  el.addEventListener('input', onInput)
  el.addEventListener('keydown', onKey)
  el.addEventListener('blur', onBlur)
}

function ensureProxies() {
  if (inputProxy && areaProxy) return
  inputProxy = document.createElement('input')
  inputProxy.id = 'stage-ime'
  inputProxy.setAttribute('enterkeyhint', 'done')
  areaProxy = document.createElement('textarea')
  areaProxy.id = 'stage-ime-area'
  areaProxy.rows = 3
  for (const el of [inputProxy, areaProxy]) {
    el.className = 'stage-ime'
    el.autocomplete = 'off'
    el.spellcheck = false
    hide(el)
    bindProxy(el)
    document.body.appendChild(el)
  }
}

function open(source: TextControl) {
  ensureProxies()
  generation += 1
  const proxy = proxyFor(source)
  if (host && host !== source) delete host.dataset.imeHost
  host = source
  active = proxy
  source.dataset.imeHost = '1'
  hide(proxy === inputProxy ? areaProxy : inputProxy)
  if (proxy instanceof HTMLInputElement && source instanceof HTMLInputElement) {
    const kind = source.type
    proxy.type = kind === 'password' || kind === 'tel' ? kind : 'text'
    proxy.inputMode = source.inputMode || (kind === 'email' ? 'email' : kind === 'tel' ? 'numeric' : 'text')
    proxy.autocomplete = source.autocomplete || 'on'
  }
  if (source.maxLength > 0) proxy.maxLength = source.maxLength
  else proxy.removeAttribute('maxlength')
  proxy.placeholder = source.placeholder || ''
  proxy.value = source.value
  place(proxy, source)
  proxy.focus()
  const end = proxy.value.length
  proxy.setSelectionRange(end, end)
}

function handlePointerDown(ev: PointerEvent) {
  if (!needsProxy(getScale())) return
  const source = controlFromTarget(ev.target)
  if (!source || source.disabled || source.readOnly) return
  try {
    open(source)
  } catch (err) {
    console.warn('[ime] open failed', err)
    return
  }
  ev.preventDefault()
}

export function typingInStage(): boolean {
  const el = document.activeElement
  if (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) {
    if (el.id === 'stage-ime' || el.id === 'stage-ime-area') return true
    if (el.closest('#game-stage')) return true
  }
  return false
}

export function installStageIme(readScale: () => number): () => void {
  getScale = readScale
  ensureProxies()
  onPointerDown = handlePointerDown
  onViewport = () => {
    requestAnimationFrame(() => requestAnimationFrame(reposition))
  }
  document.addEventListener('pointerdown', onPointerDown, true)
  window.visualViewport?.addEventListener('resize', onViewport)
  window.visualViewport?.addEventListener('scroll', onViewport)
  window.addEventListener('resize', onViewport)
  return () => {
    if (onPointerDown) document.removeEventListener('pointerdown', onPointerDown, true)
    window.visualViewport?.removeEventListener('resize', onViewport)
    window.visualViewport?.removeEventListener('scroll', onViewport)
    window.removeEventListener('resize', onViewport)
    close()
    inputProxy?.remove()
    areaProxy?.remove()
    inputProxy = null
    areaProxy = null
    onPointerDown = null
  }
}
