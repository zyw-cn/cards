# web-ui（重建）

以 **1280×720 等比缩放舞台** 展示贝密 UI。

## 启动

```bash
npm install
npm run dev
```

默认：`http://localhost:5174`

## 用 HBuilder 打安卓包

本仓库是 **Vite + Vue3 H5**，不是 uni-app。流程是：先打出静态页，再用 HBuilderX 的 **5+ App** 壳打包。

### 1. 前端出包

```bash
# 编辑 .env.hbuilder（或环境变量）填接口地址，例如：
# VITE_API_BASE_URL=https://...
# VITE_WS_BASE_URL=wss://...

npm run build:hbuilder
```

产物在 `dist-hbuilder/`（`base: './'`，适合 WebView 本地加载）。

### 2. HBuilderX 建 5+ App 工程

1. 打开 [HBuilderX](https://www.dcloud.io/hbuilderx.html)（官方）  
2. 文件 → 新建 → 项目 → **5+App**（或「App」空白模板）  
3. 把 `dist-hbuilder/` **里面的全部文件**拷进该工程根目录（覆盖原有 `index.html` 等）  
4. 打开工程里的 `manifest.json`（可视化）：
   - 应用名称、AppID（DCloud）
   - **图标**：各尺寸应用图标  
   - **启动图**：安卓启动界面  
   - **模块权限**按需勾选（若用到摄像头等）  
   - **横屏**：应用 → 横竖屏 → 横屏  
5. 发行 → 原生 App-云打包 → Android → 打包

本地打包需配 Android SDK；云打包用 DCloud 账号即可。

### 说明

- Icon / 启动页在 **HBuilder 的 manifest 可视化界面**配置，不要用 Capacitor。  
- 内网 http/ws 调试时，在 manifest 里允许明文流量（安卓网络权限 / cleartext）。  
- 改完前端后重新 `npm run build:hbuilder`，再覆盖拷贝进 HBuilder 工程后重新打包。
