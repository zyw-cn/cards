# 牌桌 Header / Footer 共用切图

组件：
- `src/components/table/TableHeader.vue` — 顶栏：返回、解散、房间局数、规则、托管、设置
- `src/components/table/TableFooter.vue` — 底栏遮罩：左半形象图、身份标、昵称、豆子、倍数，聊天靠右

**不要从 game 拷图**，放到 `public/assets/images/common/hud/`。

## Header

| 文件名 | 用途 |
|---|---|
| `btn_back.png` | 返回 |
| `btn_dismiss.png` | 解散房间 |
| `btn_rule.png` | 游戏规则 |
| `btn_trust.png` / `btn_trust_on.png` | 托管 |
| `btn_settings.png` | 设置 |
| `hud_bar.png`（可选） | 顶栏背景 |

## Footer

底栏从左到右（**遮罩为纯色，不是图**）：

```
[ 形象图 占遮罩 1/2 ][地主/农民标]  昵称(垂直居中)  豆子  倍数          [聊天]
```

| 文件名 | 用途 | 建议 |
|---|---|---|
| `footer_figure.png` | 左侧形象，宽约占遮罩一半，可高出遮罩 | 宽约 667 |
| `badge_landlord.png` | 地主标记 | 约 40×40 |
| `badge_farmer.png` | 农民标记 | 约 40×40 |
| `icon_bean.png` | 豆子 | 约 24×24 |
| `icon_multi.png` | 倍数图标（可选） | 约 24×24 |
| `btn_chat.png` | 聊天 | 约 56×56 |

遮罩默认 `rgba(0,0,0,0.62)`，可用 `maskColor` 覆盖。缺图时身份显示文字，豆子为圆点，聊天为文字按钮。

## 接入

```vue
<TableHeader placement="top" :room-id="..." :js="..." :zjs="..." ... />
<TableFooter
  :nickname="gameState.user.nickname"
  :gold="gameState.user.gold"
  :multiplier="match.multiple"
  :role="selfIsLandlord ? 'landlord' : 'farmer'"
  @chat="..."
/>
```

麻将可 `:show-trust="false"`、`:role="''"`、`:show-multiplier="false"`。

## Tip 通知飘字

目录：`src/assets/images/common/tip/`（配置见 `src/config/tableTips.ts`）

| 文件名 | 用途 |
|---|---|
| `jiaodizhu_tip.png` | 叫地主 |
| `qiangdizhu_tip.png` | 抢地主 |
| `buqiang_tip.png` | 不抢 |
| `jiabei_tip.png` | 加倍 |
| `bujiabei_tip.png` | 不加倍 |
| `buyao_tip.png` | 不要 |
| `baojing_tip.png` | 报警 |
| `ready_tip.png` | 准备 |
| `tongyijiesan_tip.png` | 同意解散 |
| `butongyijiesan_tip.png` | 不同意解散 |
| `tuoguan_tip.png` | 托管 |
| `dbgsjp_tip.png` | 您没有大过上家的牌 |
| `sjdbjdz_tip.png` | 三家都不叫地主 |
| `cpbfhgz_tip.png` | 您的出牌不符合游戏规则 |
| `cxfp_tip.png` | 系统重新发牌 |
