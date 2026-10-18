---
name: background-art
description: >-
  Specifies export sizes and safe zones for landscape hall/create-room
  background PNGs. Use when the user asks how to design backgrounds, image
  dimensions, 出图尺寸, 背景图, index_bg, hall.png, or adding a new page backdrop.
---

# 横屏背景出图

## 尺寸

| 用途 | 像素 | 说明 |
|---|---|---|
| UI 对齐稿 | **1334 × 750** | 与舞台一致 |
| 实机导出（推荐） | **2668 × 1500** | 2x，手机更清晰 |
| 宽屏余量（可选） | **1920 × 750** 或 **3840 × 1500** | 左右多画天空/海，宽屏 `object-cover` 少裁主体 |

比例 **1334:750 ≈ 16:9**。一张完整横图，不要拆成多块去叠。

## 构图

- 重要内容放在正中约 **1200 × 680** 安全区。
- 上下左右留边，给刘海、Home 条和宽屏裁切。
- 大厅：`bg/index_bg.png`；创建房间：`bg/hall.png`。
- 文件放 `src/assets/images/bg/`，由 `App.vue` 全屏铺一层，页面内不再重复引用。

## 检查

- [ ] 单张 PNG，不是四宫格拼底
- [ ] 主体不贴边
- [ ] 没有和第二层相同背景叠在 1334 画布上
