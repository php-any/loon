import { fileURLToPath } from 'node:url';
import { defineConfig, viteCssLayerPlugin } from '@vben/vite-config';

import ElementPlus from 'unplugin-element-plus/vite';

export default defineConfig(async ({ command }) => {
  return {
    application: {},
    vite: {
      ...(command === 'build'
        ? {
            base: '/assets/admin/',
            build: {
              outDir: fileURLToPath(
                new URL('../../../../public/admin', import.meta.url),
              ),
              emptyOutDir: true,
            },
          }
        : {}),
      plugins: [
        // element-plus 的 css 包进 @layer el，使 Tailwind 工具类可覆盖组件样式
        viteCssLayerPlugin({ layerName: 'el', packageName: 'element-plus' }),
        ElementPlus({ format: 'esm' }),
      ],
      server: {
        proxy: {
          '/api': {
            changeOrigin: false,
            // PHP API：保留 Host，确保同源和 CSRF 检查有效。
            target: process.env.LOOM_API_TARGET || 'http://127.0.0.1:18087',
            ws: true,
          },
        },
      },
    },
  };
});
