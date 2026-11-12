import { fileURLToPath, URL } from 'node:url';
import vue from '@vitejs/plugin-vue';
import ElementPlus from 'unplugin-element-plus/vite';
import { defineConfig } from 'vite';

export default defineConfig(({ command }) => ({
  plugins: [vue(), ElementPlus({ format: 'esm' })],
  ...(command === 'build'
    ? {
        base: '/assets/chat/',
        build: {
          outDir: fileURLToPath(new URL('../../public/chat', import.meta.url)),
          emptyOutDir: true,
        },
      }
    : {}),
  server: {
    proxy: {
      '/api': {
        changeOrigin: false,
        target: process.env.LOOM_API_TARGET || 'http://127.0.0.1:18087',
        ws: true,
      },
    },
  },
}));
