/**
 * 对照 src 引用，列出 CDN 会用到的 public 大图和音频，并删除 public 里没被引用的文件。
 * 小图在 src/assets/art，打进包，不算 CDN。
 */
import fs from 'node:fs'
import path from 'node:path'

const root = process.cwd()
const artresDir = path.join(root, 'public', 'artres')
const assetsDir = path.join(root, 'public', 'assets')
const bundledDir = path.join(root, 'src', 'assets', 'art')

function walk(dir, acc = []) {
  if (!fs.existsSync(dir)) return acc
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    const abs = path.join(dir, ent.name)
    if (ent.isDirectory()) walk(abs, acc)
    else acc.push(abs)
  }
  return acc
}

function relPosix(file, base) {
  return path.relative(base, file).replaceAll('\\', '/')
}

function readSrc() {
  const files = walk(path.join(root, 'src')).filter((f) => /\.(vue|js|ts)$/.test(f))
  files.push(path.join(root, 'index.html'))
  return files.map((f) => fs.readFileSync(f, 'utf8')).join('\n')
}

const PREFIX = {
  art: [],
  dizhu: ['ui', 'dizhu'],
  settle: ['dizhu_settle', 'slices'],
  artMj: ['ui', 'mahjong'],
  artOp: ['ui', 'chi_peng_gang'],
}

function scanCalls(source) {
  const out = []
  for (const name of Object.keys(PREFIX)) {
    const token = `${name}(`
    let i = 0
    while ((i = source.indexOf(token, i)) !== -1) {
      const prev = i === 0 ? '' : source[i - 1]
      if (/[\w$]/.test(prev)) {
        i += token.length
        continue
      }
      let j = i + token.length
      let depth = 1
      let buf = ''
      while (j < source.length && depth) {
        const c = source[j]
        if (c === '(') depth += 1
        else if (c === ')') depth -= 1
        if (depth) buf += c
        j += 1
      }
      const parts = []
      const strRe = /'([^']*)'|"([^"]*)"|`([^`$]*)`/g
      let m
      while ((m = strRe.exec(buf))) parts.push(m[1] ?? m[2] ?? m[3])
      out.push({ name, parts, dynamic: buf.includes('${'), buf })
      i = j
    }
  }
  return out
}

function expandDynamic(call, into) {
  const buf = call.buf
  if (buf.includes("'misc'") && buf.includes("'digits'")) {
    for (let n = 0; n <= 9; n += 1) into.add(`misc/digits/${n}.png`)
  }
  if (buf.includes("'room'") && buf.includes("'number'")) {
    for (let n = 0; n <= 9; n += 1) {
      into.add(`room/number/${n}.png`)
      into.add(`room/number/jsq${n}.png`)
    }
  }
  if (buf.includes("'hall_modals'") && buf.includes("'join_room'")) {
    for (let n = 0; n <= 9; n += 1) into.add(`hall_modals/join_room/${n}.png`)
  }
  if (buf.includes('table_corner')) {
    for (let n = 1; n <= 4; n += 1) into.add(`room/bg/table_corner${n}.png`)
  }
  if (buf.includes('sezi_right_')) {
    for (let n = 1; n <= 6; n += 1) into.add(`hzmj/effect/sezi_right_${n}.png`)
  }
}

const source = readSrc()
const artRels = new Set()
for (const call of scanCalls(source)) {
  if (call.dynamic) expandDynamic(call, artRels)
  if (!call.parts.length) continue
  const rel = [...PREFIX[call.name], ...call.parts].filter(Boolean).join('/')
  if (/\.(png|jpe?g|webp|gif)$/i.test(rel)) artRels.add(rel)
}

artRels.add('icon1.png')

const assetRels = new Set()
const assetRe = /\b(?:assetUrl|hzmjAsset)\(\s*(?:`([^`$]+)`|'([^']+)'|"([^"]+)")/g
let am
while ((am = assetRe.exec(source))) {
  let rel = am[1] ?? am[2] ?? am[3]
  rel = rel.replace(/^\$\{UI\}\//, 'hzmj/ui/').replace(/^\$\{HZMJ_ASSET_ROOT\}\//, 'hzmj/')
  rel = rel.replace(/^\$\{INFO2D\}\//, 'hzmj/ui/GameRoomInfo2DUI/')
  rel = rel.replace(/^\$\{INFO\}\//, 'hzmj/ui/GameRoomInfoUI/')
  rel = rel.replace(/^\$\{HALL\}\//, 'hzmj/ui/GameHallUI/')
  if (rel.includes('${')) continue
  assetRels.add(rel.replace(/^\/+/, ''))
}

const sheetJson = [...source.matchAll(/json:\s*'([^']+\.json)'/g)].map((m) => m[1])
for (const rel of sheetJson) assetRels.add(rel)

for (const rel of [...assetRels]) {
  if (!rel.endsWith('.json')) continue
  const abs = path.join(assetsDir, rel)
  if (!fs.existsSync(abs)) continue
  try {
    const json = JSON.parse(fs.readFileSync(abs, 'utf8'))
    const image = json?.meta?.image
    if (typeof image === 'string' && image) {
      assetRels.add(path.posix.join(path.posix.dirname(rel), image))
    }
  } catch {
    /* ignore */
  }
}

const bundled = new Set(walk(bundledDir).map((f) => relPosix(f, bundledDir)))
const cdnArt = [...artRels].filter((rel) => !bundled.has(rel)).sort()
const cdnAssets = [...assetRels].filter((rel) => !rel.startsWith('audio/')).sort()

const audioKeep = new Set()
const packRe =
  /id:\s*'([^']+)'[\s\S]*?dir:\s*'([^']+)'(?:[\s\S]*?spriteJson:\s*'([^']+)')?(?:[\s\S]*?bgmFile:\s*'([^']+)')?/g
const packs = []
let pm
const manifest = fs.readFileSync(path.join(root, 'src', 'config', 'assetManifest.ts'), 'utf8')
const blockRe = /'([a-z0-9-]+)':\s*\{[\s\S]*?dir:\s*'([^']+)'([\s\S]*?)\n\s*\}/g
while ((pm = blockRe.exec(manifest))) {
  const dir = pm[2]
  const body = pm[3]
  const sprite = body.match(/spriteJson:\s*'([^']+)'/)?.[1]
  const bgm = body.match(/bgmFile:\s*'([^']+)'/)?.[1]
  packs.push({ id: pm[1], dir, sprite, bgm })
}

const quoted = new Set([...source.matchAll(/'([A-Za-z0-9_-]+)'/g)].map((m) => m[1]))

for (const pack of packs) {
  if (pack.bgm) audioKeep.add(`${pack.dir}/${pack.bgm}`)
  let spriteKeys = new Set()
  if (pack.sprite) {
    audioKeep.add(pack.sprite)
    const abs = path.join(assetsDir, pack.sprite)
    if (fs.existsSync(abs)) {
      const json = JSON.parse(fs.readFileSync(abs, 'utf8'))
      spriteKeys = new Set(Object.keys(json.spritemap || {}))
      for (const name of json.resources || []) {
        audioKeep.add(`${pack.dir}/${name}`)
      }
    }
  }
  const dirAbs = path.join(assetsDir, pack.dir)
  if (!fs.existsSync(dirAbs)) continue
  for (const file of walk(dirAbs)) {
    const rel = relPosix(file, assetsDir)
    if (!rel.endsWith('.mp3')) continue
    const stem = path.posix.basename(rel, '.mp3')
    if (spriteKeys.has(stem)) continue
    if (!pack.sprite || quoted.has(stem)) audioKeep.add(rel)
  }
}

const artresFiles = walk(artresDir)
const assetFiles = walk(assetsDir)
const keepArt = new Set(cdnArt.filter((rel) => fs.existsSync(path.join(artresDir, rel))))
const keepAsset = new Set(
  [...cdnAssets, ...audioKeep].filter((rel) => fs.existsSync(path.join(assetsDir, rel))),
)

const missingArt = cdnArt.filter((rel) => !fs.existsSync(path.join(artresDir, rel)))
const missingAsset = [...cdnAssets, ...audioKeep].filter((rel) => !fs.existsSync(path.join(assetsDir, rel)))

let removed = 0
let removedBytes = 0
function removeUnused(files, base, keep) {
  for (const file of files) {
    const rel = relPosix(file, base)
    if (keep.has(rel)) continue
    removedBytes += fs.statSync(file).size
    fs.rmSync(file)
    removed += 1
  }
}
removeUnused(artresFiles, artresDir, keepArt)
removeUnused(assetFiles, assetsDir, keepAsset)

function pruneEmpty(dir) {
  if (!fs.existsSync(dir)) return
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    if (ent.isDirectory()) pruneEmpty(path.join(dir, ent.name))
  }
  if (dir !== artresDir && dir !== assetsDir && fs.readdirSync(dir).length === 0) fs.rmdirSync(dir)
}
pruneEmpty(artresDir)
pruneEmpty(assetsDir)

const lines = [
  '# CDN 实际用到的文件（相对 OSS 根目录）',
  '',
  '## 大图 artres/',
  ...[...keepArt].sort().map((rel) => `artres/${rel}`),
  '',
  '## 运行时图片 assets/（牌面图集、桌布、麻将界面）',
  ...[...keepAsset].filter((rel) => !rel.startsWith('audio/')).sort().map((rel) => `assets/${rel}`),
  '',
  '## 音频 assets/',
  ...[...keepAsset].filter((rel) => rel.startsWith('audio/')).sort().map((rel) => `assets/${rel}`),
  '',
  `# 删除未引用 ${removed} 个，约 ${Math.round(removedBytes / 1024 / 1024)} MB`,
]
if (missingArt.length || missingAsset.length) {
  lines.push('', '# 代码引用了但 public 里没有', ...missingArt.map((r) => `MISSING artres/${r}`), ...missingAsset.map((r) => `MISSING assets/${r}`))
}
fs.writeFileSync(path.join(root, 'cdn-used.txt'), `${lines.join('\n')}\n`)
console.log(lines.slice(-8).join('\n'))
console.log('keep art', keepArt.size, 'keep assets', keepAsset.size)
