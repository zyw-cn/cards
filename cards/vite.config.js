import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath, URL } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { defineConfig, loadEnv } from 'vite'

const srcDir = fileURLToPath(new URL('./src', import.meta.url))

function isRemoteCdn(raw) {
  const cdn = (raw || '').trim().toLowerCase()
  if (!cdn || cdn === 'local' || cdn === 'local/') return false
  if (cdn.includes('example.com')) return false
  return true
}

/**
 * 生产包去掉 public 里的大图和音频镜像。
 * 小图在 src/assets，由 Vite 打进 assets/ 下的哈希文件，这里不动。
 */
function externalizeOss(cdn, outDir) {
  const base = cdn.endsWith('/') ? cdn : `${cdn}/`
  const root = path.resolve(outDir)
  return {
    name: 'externalize-oss',
    apply: 'build',
    transformIndexHtml(html) {
      return html.replace('href="./artres/icon1.png"', `href="${base}artres/icon1.png"`)
    },
    closeBundle() {
      fs.rmSync(path.join(root, 'artres'), { recursive: true, force: true })
      for (const name of ['audio', 'hzmj', 'images', 'sheets']) {
        fs.rmSync(path.join(root, 'assets', name), { recursive: true, force: true })
      }
    },
  }
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, fileURLToPath(new URL('.', import.meta.url)), '')
  const cdn = (env.VITE_CDN_URL || '').trim()
  const remoteAssets = isRemoteCdn(cdn)
  /** HBuilder 5+App / 本地 file 协议：必须用相对路径，否则资源 404 */
  const forHBuilder =
    process.env.HBUILDER === '1' || process.env.npm_lifecycle_event === 'build:hbuilder'
  const outDir = forHBuilder ? 'dist-hbuilder' : 'dist'

  return {
    base: forHBuilder ? './' : '/',
    build: {
      outDir,
      // Pixi/玩法包本身较大；牌桌已 async 拆包，首屏仍可能接近阈值
      chunkSizeWarningLimit: 1200,
      rolldownOptions: {
        checks: { pluginTimings: false },
      },
    },
    plugins: [vue(), remoteAssets && externalizeOss(cdn, outDir)].filter(Boolean),
    resolve: {
      alias: {
        '@': srcDir,
      },
    },
    server: {
      host: true,
      port: 5174,
      strictPort: true,
      hmr: {
        protocol: 'ws',
        clientPort: 5174,
      },
      proxy: {
        '/api': {
          target: 'http://127.0.0.1:9051',
          changeOrigin: true,
        },
      },
    },
  }
})
