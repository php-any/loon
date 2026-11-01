# Vite Config

The project encapsulates a layer of Vite configuration and integrates some plugins for easy reuse across multiple packages and applications. The usage is as follows:

## Application

```ts
// vite.config.mts
import { defineConfig } from '@vben/vite-config';

export default defineConfig(async () => {
  return {
    application: {},
    // Vite 配置，请按照 Vite 官方文档覆盖
    vite: {},
  };
});
```

## Package

```ts
// vite.config.mts
import { defineConfig } from '@vben/vite-config';

export default defineConfig(async () => {
  return {
    library: {},
    // Vite 配置，请按照 Vite 官方文档覆盖
    vite: {},
  };
});
```
