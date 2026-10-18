---
name: migrate-game-logic
description: >-
  Migrates business logic from legacy ../game into this cards app with
  lightweight architecture (no Pinia/Vant/axios/vue-router). Use when
  copying from game, refactoring rooms, porting rules/callbacks, Event Bus,
  Action Queue, GameStateManager, Pixi table mapping, or the user says
  迁移、重构 game、只复制业务逻辑、去 Pinia.
---

# game → cards 业务逻辑迁移

引用或粘贴 `../game`（或历史路径含 `qipai/game`）的代码时，回答开头先写：

**检测到旧项目【game】资产，正在为您执行轻量化架构重构...**

## 目标

- **只迁业务逻辑**：牌型/胡牌/洗牌、创房规则、WS 回调语义、出牌校验、座位映射。
- **不迁旧壳**：Vant、Pinia、vue-router、axios、房间大 Vue、本地贴图路径、DOM 飞牌。
- **不迁素材**：禁止从 `game`（或任意旧目录）把 PNG/JPG/MP3/atlas 等**一股脑复制**进 `cards`。美术与音效由项目方提供，放入约定的 `public/assets/` 或 CDN；代码只认相对路径与帧名/音效 key。
- 输出必须是可落地的完整 **TS / Vue 3 `<script setup>`**，不要伪代码。

## 依赖对照（强制）

| 旧 (game) | 新 (cards) |
|---|---|
| `van-*` / Vant | 原生 HTML + Tailwind |
| `axios` / `request.js` | `@/services/http` |
| Pinia / `useXxxStore` / `storeToRefs` | `@/services/gameState`（`reactive`） |
| `router.push` / `replace` | `gameState.currentScreen = 'LOGIN' \| 'LOBBY' \| 'CREATE_ROOM' \| 'GAME_TABLE' \| 'SETTLE'` |
| `@game/...` 或 `game/src/...` | `@/`（本仓库 `src/`） |
| 本地 `playAudio('x.mp3')` | `audioManager.playEffect('key')` |
| DOM / Vue 画牌桌 | Pixi v8 场景；动画用 GSAP |

禁止新增：`pinia`、`vue-router`、`axios`、任何第三方 UI 库。

## 无状态管理器规范

1. **事件驱动**：不用 Pinia。全局 Event Bus（或 `CustomEvent` / 轻量 mitt 式单例）解耦。WebSocket 监听器只发事件 + payload，不直接改 Pixi 节点。
2. **数据源唯一**：对局内存只写在轻量单例（续写 `gameState`，或按玩法拆 `GameStateManager` 挂到同一中枢）。Pixi **只读映射**，禁止自行改底层手牌/出牌人等数据。
3. **Action Queue**：WS 动作先入队，Pixi 逐个取出跑 GSAP，禁止并发叠动画导致丢失/重叠。可参考旧 `trustedPlayQueue` 语义，落成通用队列而非照搬文件。

## 推荐目录

```
src/
  core/           # eventBus、actionQueue（若尚未存在则新建）
  services/       # gameState、http、audioManager、ws
  logic/shared/   # 共用牌权重、回调基类
  logic/<play>/   # 各玩法纯 TS 规则（landlord / pdk / niu / hzmj / …）
  pixi/           # 牌桌视图：订阅事件 + 读 gameState + 消费队列
  components/     # 外围 UI（登录/大厅/创房），禁止画牌
  config/         # gameTypes、createRules、ruleKv
```

旧路径映射：

| game | cards |
|---|---|
| `games/<play>/rules/*` | `logic/<play>/` |
| `games/<play>/utils/*`（纯算法） | `logic/<play>/` 或 `logic/shared/` |
| `utils/card.js`、`serverCard.js` | `logic/shared/` |
| `*Callback.js` / `GameCallback` | `logic/<play>/` + 注册到 Event Bus |
| `shared/fx/*`、房间 `.vue` | **不整文件复制**；用 Pixi+GSAP 重写表现 |

## 迁移工作流（每次）

```
Task Progress:
- [ ] 1. 锁定玩法与源文件清单（只列 rules/utils/callback）
- [ ] 2. 抄语义 → 改 TS 强类型；去掉 Vue/Pinia/DOM 依赖
- [ ] 3. 写入逻辑层；校验函数纯函数化（输入牌/状态 → 输出结果）
- [ ] 4. WS：解析 payload → 更新 gameState → emit 事件 → enqueue 动画动作
- [ ] 5. Pixi：订阅事件/消费队列，不反向写状态
- [ ] 6. 外围 UI 若需：原生 + Tailwind；切屏只用 currentScreen
- [ ] 7. 自检下方「禁止清单」与资源加载方式
```

### 资源

- 手牌/特效：**雪碧图 atlas**；音效：**合并 audio sprite**。本地与 CDN 同相对路径，经 `assetUrl` 切换（见 skill `asset-cdn-pipeline`）。
- 纹理：Pixi `Assets.load(assetUrl('sheets/…'))`；禁止业务里打散单图 URL。
- 音效：只走 `audioManager`（按 pack key 播 sprite）。

## 禁止清单（每次开发必过）

- [ ] 未引入 Pinia / vue-router / axios / Vant
- [ ] 未把旧房间 `.vue` 整页粘进 cards
- [ ] **未从 game 复制图片/音效/atlas 文件**（素材由项目方提供）
- [ ] 手牌/出牌人等只在 `gameState`（或约定的 Manager）修改
- [ ] Pixi 未直接改对局数据
- [ ] 网络驱动的表现动作走 Action Queue
- [ ] 飞牌/筹码用 `gsap.to` / `timeline`，目标为 Pixi `DisplayObject`
- [ ] 未在子页面重复铺 `index_bg` / `hall.png`（背景由 App 舞台负责）

## 相关规则

- `.cursor/rules/migrate-from-game.mdc`
- `.cursor/rules/project-architecture.mdc`
- `.cursor/rules/cdn-logic-reuse.mdc`
- `.cursor/rules/pixi-gsap-cdn.mdc`
- `.cursor/rules/vue-tailwind-ui.mdc`

细节示例见 [examples.md](examples.md)。
