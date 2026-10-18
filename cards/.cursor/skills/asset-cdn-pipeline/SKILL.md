---
name: asset-cdn-pipeline
description: >-
  Plans and implements spritesheet (atlas) and audio-sprite loading with a
  local-to-CDN path switch. Use when loading hand cards, Pixi Assets, Howler
  sprites, VITE_CDN_URL, public/assets, uploading to CDN, or the user mentions
  雪碧图、合并音效、本地资源、CDN 素材.
---

# 素材：雪碧图 + 合并音效 + 本地/CDN

## 原则

1. **路径契约不变**：代码只认逻辑路径（如 `sheets/cards`、`audio/ddz-sfx`），不写死完整域名。
2. **本地目录 = CDN 镜像**：`public/assets/**` 与线上 CDN 前缀后的相对路径一致，上传即拷贝。
3. **素材由项目方提供**：禁止从旧项目 `game` 批量复制图片/音效进仓库。Agent 只维护清单、加载器与相对路径；缺文件时加载失败可提示，不要擅自拷贝填充。
4. **手牌/特效只走 atlas**（多帧）或约定单图 key；禁止运行时按散图滥载扑克/麻将手牌。
5. **短音效用 audio sprite**：同包一次加载，按 key 切片播放；BGM/超长语音可单独文件。
6. **切换只改环境变量**：`VITE_CDN_URL` 为空或 `local` → 本地；有值 → CDN。

## URL 解析（唯一入口）

```ts
// services/assetUrl.ts（约定实现）
import { gameState } from './gameState'

/** 相对路径：sheets/cards.json、audio/ddz-sfx/pack.mp3 */
export function assetUrl(relPath: string): string {
  const rel = relPath.replace(/^\/+/, '')
  const cdn = (gameState.cdnUrl || '').trim()
  if (!cdn || cdn.includes('example.com') || cdn === 'local/') {
    return `/assets/${rel}` // Vite public/
  }
  const base = cdn.endsWith('/') ? cdn : `${cdn}/`
  return `${base}${rel.startsWith('assets/') ? rel : `assets/${rel}`}`
}
```

开发：`.env` 设 `VITE_CDN_URL=` 或 `local/`。上线：设真实 CDN 根（含或不含 `assets/`，与上面规则对齐后固定一种）。

## 目录契约

**运行时 URL（Pixi / 音效 / fetch）→ `public/assets/`。Vue `import` 切图 → `src/assets/images/`。** 细则见 [path-map.md](path-map.md) 与 `.cursor/rules/asset-folders.mdc`。

```
public/assets/                    # 本地；未来原样上传 CDN
  images/common/card_back.png     # 发牌牌背（必须独立 PNG）
  images/card/card.json + card.png  # 扑克手牌（帧名=协议码）
  images/ddz/
    bomb|plane|spring|liandui|shunzi|wang_bomb/  # 斗地主特效 atlas
  audio/
    common-sfx/
    ddz-sfx/
    ddz-voice/
    …

src/assets/images/                # 仅 Vue 打包引用
  card/card.json                  # DOM 手牌切图坐标（与 public 同帧名）
  common/hub|button|tip|head/     # HUD、按钮、提示、默认头
```

扑克与斗地主特效主图集都在 **`public/assets/images/`**，不走 `sheets/`。HUD 切图清单见 [docs/ddz-table-hud.md](../../../docs/ddz-table-hud.md)。


## 纹理（Pixi v8）

```ts
import { Assets } from 'pixi.js'
import { assetUrl } from '@/services/assetUrl'

await Assets.load(assetUrl('sheets/cards.json'))
const tex = Assets.get('card/0.png') // 与 cards.json frames 键一致
```

- 进牌桌前按玩法 `preloadSheets(['sheets/cards', …])`。
- 手牌精灵：`new Sprite(Assets.get(frameKey))`，不要 `Texture.from(单图 url)`。

## 音效（Howler sprite）

包格式（已有 hzmj 示例）：`{ resources: ['….mp3'], spritemap: { key: { start, end, loop } } }`。

```ts
// playEffect('deal') → 先查当前 pack 的 spritemap，命中则 howl.play('deal')
// 未打包的 key → 可选回落同目录散文件（迁移期），上线后去掉回落
```

| 包名 | 用途 |
|---|---|
| `common-sfx` | 大厅/通用 |
| `ddz-sfx` | 斗地主效果 |
| `ddz-voice` | 出牌语音（可后合并） |
| `niu-sfx` / `flower-sfx` / `hzmj-sfx` | 各玩法 |
| `hzmj-voice-*` | 麻将语音 |

BGM：sprite 内若含 `bgm` 可用；更长大曲仍建议独立 `bgm.mp3`，用 `playBGM`。

## 加载时机

| 阶段 | 加载 |
|---|---|
| 大厅 | 仅 `common-sfx`（点击音）；**不要**预载手牌 atlas / 玩法特效 |
| 进某玩法牌桌 | 该玩法 sheets + sfx（+ voice）；单图特效（如连对/顺子）无 json |
| 创房点选玩法（可选） | `preload` 暖场，不阻塞 UI |
| 离桌 | 可 `unload` 玩法包，保留 common |

与 Action Queue 配合：preload 完成后再 `enqueue` 发牌动画，避免首帧缺纹理。

## 上线清单

- [ ] `public/assets` 与 CDN 相对路径一致
- [ ] 所有业务调用经 `assetUrl` / `audioManager` / `Assets.load(assetUrl(…))`
- [ ] `.env.production` 配置真实 `VITE_CDN_URL`
- [ ] CORS：CDN 对游戏域放行（尤其音频）
- [ ] 缓存：atlas/json 用 hash 文件名或 query 版本号，换皮可刷新
- [ ] 抽检：手牌帧名、sprite key 与旧 catalog 语义一致

## 禁止

- 从 `../game` 或其它工程**批量复制** PNG/MP3/atlas 进 `cards`
- 业务代码写死 `https://cdn.../xxx.png`
- 手牌循环请求 54 张散图
- 音效每个 key 都新建 Howl 打散文件（合并包就绪后）
- 本地路径与 CDN 路径两套命名

细节见 [path-map.md](path-map.md)。
