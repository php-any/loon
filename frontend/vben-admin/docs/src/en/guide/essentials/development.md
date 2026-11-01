# Local Development {#development}

::: tip Code Acquisition

If you haven't acquired the code yet, you can start by reading the documentation from [Quick Start](../introduction/quick-start.md).

:::

## Prerequisites

For a better development experience, we provide some tool configurations and project descriptions to facilitate your development.

### Required Basic Knowledge

This project requires some basic frontend knowledge. Please ensure you are familiar with the basics of Vue to handle common issues. It is recommended to learn the following topics before development. Understanding these will be very helpful for the project:

- [Vue3](https://vuejs.org/)
- [Tailwind CSS](https://tailwindcss.com/)
- [TypeScript](https://www.typescriptlang.org/)
- [Vue Router](https://router.vuejs.org/)
- [Vitejs](https://vitejs.dev/)
- [Pnpm](https://pnpm.io/)
- [Turbo](https://turbo.build/)

### Tool Configuration

If you are using [vscode](https://code.visualstudio.com/) (recommended) as your IDE, you can install the following tools to improve development efficiency and code formatting:

- [Vue - Official](https://marketplace.visualstudio.com/items?itemName=Vue.volar) - Official Vue plugin (essential).
- [Tailwind CSS](https://marketplace.visualstudio.com/items?itemName=bradlc.vscode-tailwindcss) - Tailwind CSS autocomplete plugin.
- [CSS Variable Autocomplete](https://marketplace.visualstudio.com/items?itemName=vunguyentuan.vscode-css-variables) - CSS variable autocomplete plugin.
- [Iconify IntelliSense](https://marketplace.visualstudio.com/items?itemName=antfu.iconify) - Iconify icon plugin.
- [i18n Ally](https://marketplace.visualstudio.com/items?itemName=Lokalise.i18n-ally) - i18n plugin.
- [ESLint](https://marketplace.visualstudio.com/items?itemName=dbaeumer.vscode-eslint) - Script code linting.
- [Oxc](https://marketplace.visualstudio.com/items?itemName=oxc.oxc-vscode) - Oxlint / Oxfmt integration.
- [Stylelint](https://marketplace.visualstudio.com/items?itemName=stylelint.vscode-stylelint) - CSS formatting.
- [Code Spell Checker](https://marketplace.visualstudio.com/items?itemName=streetsidesoftware.code-spell-checker) - Spelling checker.
- [DotENV](https://marketplace.visualstudio.com/items?itemName=mikestead.dotenv) - .env file highlighting.

## Npm Scripts

Npm scripts are common configurations used in the project to perform common tasks such as starting the project, building the project, and more. The following scripts can be found in the `package.json` file at the root of the project.

The execution command is: `pnpm run [script]` or `npm run [script]`.

```json
{
  "scripts": {
    // 构建项目
    "build": "cross-env NODE_OPTIONS=--max-old-space-size=8192 turbo build",
    // 构建项目并进行分析
    "build:analyze": "turbo build:analyze",
    // 构建本地 Docker 镜像
    "build:docker": "./scripts/deploy/build-local-docker-image.sh",
    // 单独构建 web-antd 应用
    "build:antd": "pnpm run build --filter=@vben/web-antd",
    // 单独构建 web-antdv-next 应用
    "build:antdv-next": "pnpm run build --filter=@vben/web-antdv-next",
    // 单独构建文档
    "build:docs": "pnpm run build --filter=@vben/docs",
    // 单独构建 web-ele 应用
    "build:ele": "pnpm run build --filter=@vben/web-ele",
    // 单独构建 web-naive 应用
    "build:naive": "pnpm run build --filter=@vben/web-naive",
    // 单独构建 web-tdesign 应用
    "build:tdesign": "pnpm run build --filter=@vben/web-tdesign",
    // 单独构建演示场应用
    "build:play": "pnpm run build --filter=@vben/playground",
    // Changeset 版本管理
    "changeset": "pnpm exec changeset",
    // 检查项目中的各类问题
    "check": "pnpm run check:circular && pnpm run check:dep && pnpm run check:type && pnpm check:cspell",
    // 检查循环依赖
    "check:circular": "vsh check-circular",
    // 检查拼写
    "check:cspell": "cspell lint **/*.ts **/README.md .changeset/*.md --no-progress",
    // 检查依赖
    "check:dep": "vsh check-dep",
    // 检查类型
    "check:type": "turbo run typecheck",
    // 清理项目，删除 node_modules、dist、.turbo 等
    "clean": "node ./scripts/clean.mjs",
    // 提交代码
    "commit": "czg",
    // 启动项目，默认运行整个仓库中所有包的 dev 脚本
    "dev": "turbo-run dev",
    // 启动 web-antd 应用
    "dev:antd": "pnpm -F @vben/web-antd run dev",
    // 启动 web-antdv-next 应用
    "dev:antdv-next": "pnpm -F @vben/web-antdv-next run dev",
    // 启动文档
    "dev:docs": "pnpm -F @vben/docs run dev",
    // 启动 web-ele 应用
    "dev:ele": "pnpm -F @vben/web-ele run dev",
    // 启动 web-naive 应用
    "dev:naive": "pnpm -F @vben/web-naive run dev",
    // 启动 web-tdesign 应用
    "dev:tdesign": "pnpm -F @vben/web-tdesign run dev",
    // 启动演示场应用
    "dev:play": "pnpm -F @vben/playground run dev",
    // 格式化代码
    "format": "vsh lint --format",
    // 检查代码规范
    "lint": "vsh lint",
    // 安装依赖后执行所有包的 stub 脚本
    "postinstall": "pnpm -r run stub --if-present",
    // 仅允许使用 pnpm
    "preinstall": "npx only-allow pnpm",
    // 安装 lefthook
    "prepare": "pnpm exec lefthook install",
    // 预览应用
    "preview": "turbo-run preview",
    // 检查包规范
    "publint": "vsh publint",
    // 删除所有 node_modules、yarn.lock、package-lock.json，然后重新安装依赖
    "reinstall": "pnpm clean --del-lock && pnpm install",
    // 运行端到端测试
    "test:e2e": "turbo run test:e2e",
    // 运行 vitest 单元测试
    "test:unit": "vitest run --dom",
    // 更新项目依赖
    "update:deps": "npx taze -r -w",
    // 生成 Changeset 并管理版本
    "version": "pnpm exec changeset version && pnpm install --no-frozen-lockfile"
  }
}
```

## Running the Project Locally

To run the documentation locally and make adjustments, you can execute the following command. This command allows you to select the application you want to develop:

```bash
pnpm dev
```

If you want to run a specific application directly, you can execute the following commands:

To run the `web-antd` application:

```bash
pnpm dev:antd
```

To run the `web-antdv-next` application:

```bash
pnpm dev:antdv-next
```

To run the `web-naive` application:

```bash
pnpm dev:naive
```

To run the `web-ele` application:

```bash
pnpm dev:ele
```

To run the `web-tdesign` application:

```bash
pnpm dev:tdesign
```

To run the `docs` application:

```bash
pnpm dev:docs
```

### Distinguishing Build Environments

In actual business development, multiple environments are usually distinguished during the build process, such as the test environment `test` and the production environment `build`.

At this point, you can modify three files and add corresponding script configurations to distinguish between production environments.

Take the addition of the test environment `test` to `@vben/web-antd` as an example:

- `apps\web-antd\package.json`

```json
"scripts": {
  "build:prod": "pnpm vite build --mode production",
  "build:test": "pnpm vite build --mode test",
  "build:analyze": "pnpm vite build --mode analyze",
  "dev": "pnpm vite --mode development",
  "preview": "vite preview",
  "typecheck": "vue-tsc --noEmit --skipLibCheck"
}
```

Add the command `"build:test"` and change the original `"build"` to `"build:prod"` to avoid building packages for two environments simultaneously.

- `package.json`

```json
"scripts": {
    "build": "cross-env NODE_OPTIONS=--max-old-space-size=8192 turbo build",
    "build:analyze": "turbo build:analyze",
    "build:antd": "pnpm run build --filter=@vben/web-antd",
    "build-test:antd": "pnpm run build --filter=@vben/web-antd build:test",

    ······
}
```

Add the command to build the test environment in the root directory `package.json`.

- `turbo.json`

```json
"tasks": {
    "build": {
      "dependsOn": ["^build"],
      "outputs": [
        "dist/**",
        "dist.zip",
        ".vitepress/dist.zip",
        ".vitepress/dist/**"
      ]
    },

    "build-test:antd": {
      "dependsOn": ["@vben/web-antd#build:test"],
      "outputs": ["dist/**"]
    },

    "@vben/web-antd#build:test": {
      "dependsOn": ["^build"],
      "outputs": ["dist/**"]
    },

    ······
```

Add the relevant dependent commands in `turbo.json`.

## Public Static Resources

If you need to use public static resources in the project, such as images and static HTML, and want to import them directly during development through `src="/xxx.png"`.

You need to put the resources in the `public/static` directory of the corresponding project. The import path for the resources should be `src="/static/xxx.png"`.

## DevTools

The project has a built-in [Vue DevTools](https://github.com/vuejs/devtools-next) plugin, which can be used during development. It is disabled by default, but can be enabled in the `.env.development` file. After enabling it, restart the project:

```bash
VITE_DEVTOOLS=true
```

Once enabled, a Vue DevTools icon will appear at the bottom of the page during project runtime. Click it to open the DevTools.

![Vue DevTools](/guide/devtools.png)

## Running Documentation Locally

To run the documentation locally and make adjustments, you can execute the following command:

```bash
pnpm dev:docs
```

## Troubleshooting

If you encounter dependency-related issues, you can try reinstalling the dependencies:

```bash
# Execute this command at the root of the project.
# This command will delete all node_modules, yarn.lock, and package-lock.json files
# and then reinstall dependencies (this process will be noticeably slower).
pnpm reinstall
```
