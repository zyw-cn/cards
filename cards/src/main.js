import { Assets } from 'pixi.js'
import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import { isFilePage } from './services/readLocal'

// file:// 下 Pixi 默认用 fetch 拉图，安卓 WebView 会拒绝。改走 Image。
if (isFilePage()) {
  Assets.setPreferences({
    preferCreateImageBitmap: false,
    preferWorkers: false,
    crossOrigin: '',
  })
}

createApp(App).mount('#app')
