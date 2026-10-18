---
name: landscape-stage
description: >-
  Lays out the 1334x750 landscape stage, safe-area scaling, and a single
  full-screen background. Use when editing App.vue, style.css, viewport,
  letterboxing, black bars, overlapping backgrounds, or page shells
  (LOGIN/LOBBY/CREATE_ROOM/GAME_TABLE).
---

# 横屏舞台与背景层

## 原则

- 设计坐标固定 **1334×750**。所有页面 UI 画在这块画布上。
- 画布放进 **安全区**（`env(safe-area-inset-*)` + `100dvh` + `visualViewport`）。
- 缩放用 **contain**：`scale = min(playW/1334, playH/750)`。禁止 `Math.max` cover，否则顶底会被裁。
- **全屏背景只铺一层**，在 `App.vue` 的 `.stage-shell` 上 `object-cover`。页面组件里不要再放同一张 `index_bg` / `hall.png`。

## 背景映射

| `gameState.currentScreen` | 全屏背景 |
|---|---|
| `LOGIN` / `LOBBY` | `src/assets/images/bg/index_bg.png` |
| `CREATE_ROOM` | `src/assets/images/bg/hall.png` |
| `GAME_TABLE`（斗地主 / 癞子） | `src/assets/images/bg/ddz_bg.png` |
| `GAME_TABLE`（其它未配） | 无图，铺 `#0d5c3d` |

换页只改 `bleedBg`，不要在子视图再叠一层。

## 禁止

```vue
<!-- ❌ 子页面再铺一张，会和 App 全屏背景重叠发虚 -->
<img :src="indexBg" class="absolute inset-0 h-full w-full object-cover" />
```

```ts
// ❌ 铺满裁切
Math.max(w / 1334, h / 750)
```

## 允许

子页面根节点透明，只放 UI。大厅入口卡、创建房间表单都叠在 App 那一层背景上。
