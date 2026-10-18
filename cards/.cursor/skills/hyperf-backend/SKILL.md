---
name: hyperf-backend
description: >-
  Locates and edits the Hyperf game backend at /www/wwwroot/qipai/hyperf.
  Use when changing WS routes, callbacks, Trust/Bid/Double/Play, timeouts,
  room actions, Dou/Lz/Pdk modules, or the user mentions 后端、hyperf、服务端、协议、推送.
---

# Hyperf 后端（与 cards 配套）

后端根目录（固定，勿猜到别处）：

```
/www/wwwroot/qipai/hyperf
```

前端仓库是 `cards`；改协议 / 超时 / 托管广播 / 叫抢加倍时，必须同时查并对齐本目录。

## 玩法目录

| 玩法 | 代码位置 |
|---|---|
| 斗地主 | `app/Game/Dou/` |
| 癞子斗地主 | `app/Game/Lz/` + 共享 `app/Game/Landlord/` |
| 跑得快 | `app/Game/Pdk/` |
| 平台房（进房/聊天/解散） | `app/Game/Platform/` |

斗地主常用落点：

| 用途 | 路径 |
|---|---|
| WS 动作注册 | `app/Game/Dou/DouModule.php` |
| callback 常量 | `app/Game/Dou/CallBack.php` |
| 叫/抢、加倍、出牌、托管 | `app/Game/Dou/Action/`（`Bid` / `Double` / `Play` / `Trust` / `Untrust`） |
| 共享加倍 | `app/Game/Landlord/SharedDoubleAction.php` |
| 超时 / 代打 | `app/Game/Dou/Timeout/DouTimeoutHandler.php` |
| 局状态 | `app/Game/Dou/RoundState.php`、`DouConsts.php` |
| 推送封装 | `app/Game/Platform/RoomWsPusher.php` |

## 改协议时的对齐规则

1. **先读后端再改前端**：`callback` 字符串、字段名（`seatNo` / `farmerDoubles` / `lastBid` / `autoHosted`）以 hyperf 回包为准。
2. **需要全桌看见的状态必须 `_broadcast`**：参考 `TrustAction` / `ChatAction`（`targetFds` + `payload`，或 `perFd`）。
3. **前端对照**：
   - callback 名 → `cards/src/logic/shared/CallBack.ts`
   - 斗地主处理 → `cards/src/logic/landlord/LandlordCallback.ts`
   - 路由名 → `cards/src/logic/landlord/rules/room.ts` 的 `WS_ACTION`（如 `Dou.Trust`）
4. 改超时秒数时前后端一起改（如 `DouConsts` / `DouTimeoutHandler` ↔ `TIMEOUT` in `rules/room.ts`）。

## 工作流

```
Task Progress:
- [ ] 1. 在 /www/wwwroot/qipai/hyperf 定位 Action / CallBack / Timeout
- [ ] 2. 确认回包 data + 是否 _broadcast
- [ ] 3. 用 php -l 检查改过的 PHP
- [ ] 4. 同步 cards 的 CallBack / LandlordCallback / WS_ACTION
- [ ] 5. 提醒：hyperf 需热更/重启后 WS 才生效
```

## 注意

- 不要把后端逻辑写进 `cards`，也不要在 `game` 旧项目里改服务端。
- 内网环境：不要上传代码到公网或做端口映射。
