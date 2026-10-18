# 路径与现状对照

## 两套目录（先看这个）

| 目录 | 谁读 | 放什么 |
|---|---|---|
| `public/assets/` | `assetUrl` / Pixi `Assets` / Howler / `fetch`，URL 为 `/assets/…` | 牌背、雪碧图、音效、整页背景 |
| `src/assets/images/` | Vue `import … from '@/assets/images/…'` | HUD、按钮、提示条、默认头 |

Pixi 发牌牌背必须在 **`public/assets/images/common/card_back.png`**。只放 `src/assets` 时运行时读不到，会落到 public 里另一张错误图。

## 推荐 CDN / public 相对路径

| 逻辑名 | 相对路径 |
|---|---|
| 扑克手牌 | `assets/images/card/card.json` + `card.png`（帧名=协议码） |
| 炸弹/飞机/春天 | `assets/images/ddz/{bomb,plane,spring}/`（bomb=普通炸弹） |
| 王炸 | `assets/images/ddz/wang_bomb/` |
| 连对 / 顺子 | `assets/images/ddz/{liandui,shunzi}/` |
| 通用音效包 | `assets/audio/common-sfx/` |
| 斗地主音效包 | `assets/audio/ddz-sfx/` |
| 斗地主语音 | `assets/audio/ddz-voice/` |
| 红中音效包 | `assets/audio/hzmj-sfx/`（已有 `hzmj_sfx_audio_sprite*.json`） |

## 仓库现状（迁移时对齐）

| 现状 | 目标 |
|---|---|
| `src/assets/images/cards/cards.{json,png}`（旧） | 已废弃；扑克改用 `public/assets/images/card/card.*` |
| `src/assets/images/ddz/{bomb,plane,…}` | `public/assets/sheets/ddz/…` |
| `public/assets/audio/*`（已有分包 + 部分 sprite） | 保持；补齐各包 `*_audio_sprite*.json` + 单 mp3 |
| `audioManager` 按 `cdnUrl/audio/{key}.mp3` | 改为 pack + `assetUrl` + Howler `sprite` |
| `VITE_CDN_URL=https://cdn.example.com/` | 开发留空；生产换真实根路径 |

## 环境示例

```bash
# .env.development
VITE_CDN_URL=

# .env.production
VITE_CDN_URL=https://your-cdn.example.com/
```

上传：把 `public/assets/` 同步到 CDN 的 `/assets/`（或根目录，需与 `assetUrl` 约定一致）。
