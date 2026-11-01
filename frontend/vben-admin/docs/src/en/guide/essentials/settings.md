# Configuration

## Environment Variable Configuration

The project's environment variable configuration is located in the application directory under `.env`, `.env.development`, `.env.production`.

The rules are consistent with [Vite Env Variables and Modes](https://vitejs.dev/guide/env-and-mode.html). The format is as follows:

```bash
.env                # Loaded in all environments
.env.local          # Loaded in all environments, but ignored by git
.env.[mode]         # Only loaded in the specified mode
.env.[mode].local   # Only loaded in the specified mode, but ignored by git
```

::: tip

- Only variables starting with `VITE_` will be embedded into the client-side package. You can access them in the project code like this:

  ```ts
  console.log(import.meta.env.VITE_PORT);
  ```

- Variables starting with `VITE_GLOB_*` will be added to the `_app-config-{version}-{hash}.js` configuration file during packaging.

:::

## Environment Configuration Description

::: code-group

```bash [.env]
# Application title
VITE_APP_TITLE=Vben Admin

# Application namespace, used as a prefix for caching, store, etc., to ensure isolation
VITE_APP_NAMESPACE=vben-web-antd
```

```bash [.env.development]
# Port Number
VITE_PORT=5555

# Public Path for Resources, must start and end with /
VITE_BASE=/

# API URL
VITE_GLOB_API_URL=/api

# Whether to enable Nitro Mock service, true to enable, false to disable
VITE_NITRO_MOCK=true

# Whether to open devtools, true to open, false to close
VITE_DEVTOOLS=true

# Whether to inject global loading
VITE_INJECT_APP_LOADING=true

# Whether to generate after packaging dist.zip
VITE_ARCHIVER=true
```

```bash [.env.production]
# Public Path for Resources, must start and end with /
VITE_BASE=/

# API URL
VITE_GLOB_API_URL=https://mock-napi.vben.pro/api

# Whether to enable compression, can be set to none, brotli, gzip
VITE_COMPRESS=gzip

# Whether to enable PWA
VITE_PWA=false

# vue-router mode
VITE_ROUTER_HISTORY=hash

# Whether to inject global loading
VITE_INJECT_APP_LOADING=true

# Whether to generate dist.zip after packaging
VITE_ARCHIVER=true
```

:::

## Dynamic Configuration in Production Environment

When executing `pnpm build` in the root directory of the monorepo, a `dist/_app-config-{version}-{hash}.js` file will be automatically generated in the corresponding application and inserted into `index.html`.

`_app-config-{version}-{hash}.js` is a dynamic configuration file that allows for modifications to the configuration dynamically based on different environments after the project has been built. The content is as follows:

```ts
window._VBEN_ADMIN_PRO_APP_CONF_ = {
  VITE_GLOB_API_URL: 'https://mock-napi.vben.pro/api',
};
Object.freeze(window._VBEN_ADMIN_PRO_APP_CONF_);
Object.defineProperty(window, '_VBEN_ADMIN_PRO_APP_CONF_', {
  configurable: false,
  writable: false,
});
```

### Purpose

`_app-config-{version}-{hash}.js` is used for projects that need to dynamically modify configurations after packaging, such as API endpoints. There's no need to repackage; you can simply modify the variables in `/dist/_app-config-{version}-{hash}.js` after packaging, and refresh to update the variables in the code. A `js` file is used to ensure that the configuration file is loaded early in the order.

### Usage

To access the variables inside `_app-config-{version}-{hash}.js`, you need to use the `useAppConfig` method provided by `@vben/hooks`.

```ts
const { apiURL } = useAppConfig(import.meta.env, import.meta.env.PROD);
```

### Adding New

To add a new dynamically modifiable configuration item, simply follow the steps below:

- First, add the variable that needs to be dynamically configurable in the `.env` file or the corresponding development environment configuration file. The variable must start with `VITE_GLOB_*`, for example:

  ```bash
  VITE_GLOB_OTHER_API_URL=https://mock-napi.vben.pro/other-api
  ```

- In `packages/types/global.d.ts`, add the corresponding type definition, such as:

  ```ts
  export interface VbenAdminProAppConfigRaw {
    VITE_GLOB_API_URL: string;
    VITE_GLOB_OTHER_API_URL: string; // [!code ++]
  }

  export interface ApplicationConfig {
    apiURL: string;
    otherApiURL: string; // [!code ++]
  }
  ```

- In `packages/effects/hooks/src/use-app-config.ts`, add the corresponding configuration item, such as:

  ```ts
  export function useAppConfig(
    env: Record<string, any>,
    isProduction: boolean,
  ): ApplicationConfig {
    // 生产环境直接使用 window._VBEN_ADMIN_PRO_APP_CONF_ 全局变量
    const config = isProduction
      ? window._VBEN_ADMIN_PRO_APP_CONF_
      : (env as VbenAdminProAppConfigRaw);

    const { VITE_GLOB_API_URL, VITE_GLOB_OTHER_API_URL } = config; // [!code ++]

    return {
      apiURL: VITE_GLOB_API_URL,
      otherApiURL: VITE_GLOB_OTHER_API_URL, // [!code ++]
    };
  }
  ```

At this point, you can use the `useAppConfig` method within the project to access the newly added configuration item.

```ts
const { otherApiURL } = useAppConfig(import.meta.env, import.meta.env.PROD);
```

::: warning Warning

The `useAppConfig` method should only be used within the application and not be coupled with the internals of a package. The reason for passing `import.meta.env` and `import.meta.env.PROD` is to avoid such coupling. A pure package should avoid using variables specific to a particular build tool.

:::

## Preferences

The project offers a wide range of preference settings for dynamically configuring various features of the project:

![](/guide/preferences.png)

If you cannot find documentation for a setting, you can try configuring it yourself and then click `Copy Preferences` to override the project defaults. The configuration file is located in the application directory under `preferences.ts`, where you can override the framework's default configurations to achieve custom settings.

```ts
import { useAppConfig } from '@vben/hooks';
import { defineOverridesPreferences } from '@vben/preferences';

/**
 * @description 项目配置文件
 * 仅覆盖项目中需要修改的部分配置，其余配置会自动使用默认值
 * 注意：修改配置后请清除缓存，否则可能不生效
 */
export const overridesPreferences = defineOverridesPreferences({
  // 覆盖配置
});
```

### Extend project-level preferences

In addition to overriding the built-in framework preferences, you can also add a set of business preferences for each application. After configuration, the preferences drawer will display an extra tab for the current app, and the data will be stored together with the app `namespace`. This is useful for project-specific fields such as tenant mode, business titles, or default page size.

#### 1. Define the extension in `src/preferences.ts`

```ts
import {
  defineOverridesPreferences,
  definePreferencesExtension,
} from '@vben/preferences';

interface ProjectPreferencesExtension {
  defaultTableSize: number;
  enableFormFullscreen: boolean;
  reportTitle: string;
  tenantMode: 'multi' | 'single';
}

export const overridesPreferences = defineOverridesPreferences({
  app: {
    name: import.meta.env.VITE_APP_TITLE,
  },
});

export const preferencesExtension =
  definePreferencesExtension<ProjectPreferencesExtension>({
    tabLabel: 'preferences.antd.tabLabel',
    title: 'preferences.antd.title',
    fields: [
      {
        component: 'switch',
        defaultValue: true,
        key: 'enableFormFullscreen',
        label: 'preferences.antd.fields.enableFormFullscreen.label',
        tip: 'preferences.antd.fields.enableFormFullscreen.tip',
      },
      {
        component: 'select',
        defaultValue: 'single',
        key: 'tenantMode',
        label: 'preferences.antd.fields.tenantMode.label',
        options: [
          {
            label: 'preferences.antd.fields.tenantMode.options.single.label',
            value: 'single',
          },
          {
            label: 'preferences.antd.fields.tenantMode.options.multi.label',
            value: 'multi',
          },
        ],
      },
      {
        component: 'number',
        componentProps: {
          max: 200,
          min: 10,
          step: 10,
        },
        defaultValue: 20,
        key: 'defaultTableSize',
        label: 'preferences.antd.fields.defaultTableSize.label',
      },
      {
        component: 'input',
        defaultValue: '',
        key: 'reportTitle',
        label: 'preferences.antd.fields.reportTitle.label',
        placeholder: 'preferences.antd.fields.reportTitle.placeholder',
      },
    ],
  });
```

- `tabLabel` is the tab label, and `title` is the panel title. If `title` is omitted, `tabLabel` is used as the fallback.
- `fields` currently supports four component types: `input`, `number`, `select`, and `switch`.
- `label`, `placeholder`, `tip`, and `options[].label` can be i18n keys directly. The preferences drawer resolves them with `$t` automatically.

#### 2. Pass `extension` when initializing preferences

```ts
import { initPreferences } from '@vben/preferences';

import { overridesPreferences, preferencesExtension } from './preferences';

await initPreferences({
  namespace,
  overrides: overridesPreferences,
  extension: preferencesExtension,
});
```

The same `namespace` isolates both framework preferences and extension preferences. So even if multiple subprojects run in the same browser, their business preferences remain independent.

#### 3. Read or update extension preferences in business pages

```ts
import {
  getCustomPreferences,
  updateCustomPreferences,
  usePreferences,
} from '@vben/preferences';

interface ProjectPreferencesExtension {
  defaultTableSize: number;
  enableFormFullscreen: boolean;
  reportTitle: string;
  tenantMode: 'multi' | 'single';
}

const projectPreferences = getCustomPreferences<ProjectPreferencesExtension>();

const { customPreferences, preferencesExtension } = usePreferences();

updateCustomPreferences<ProjectPreferencesExtension>({
  defaultTableSize: 50,
  tenantMode: 'multi',
});
```

- `getCustomPreferences` returns the reactive extension-preferences object for the current app.
- `customPreferences` and `preferencesExtension` from `usePreferences` are convenient when composing reusable logic.
- Calling `resetPreferences()` also resets extension preferences back to their default values.

#### 4. Number fields validate `min` / `max` / `step` automatically

If you provide `componentProps.min`, `componentProps.max`, and `componentProps.step` for a `number` field, runtime persistence follows the same constraints. For example:

```ts
{
  component: 'number',
  componentProps: {
    min: 10,
    max: 200,
    step: 10,
  },
  defaultValue: 20,
  key: 'defaultTableSize',
  label: 'preferences.antd.fields.defaultTableSize.label',
}
```

Only values within `10 ~ 200` and increasing by `10` will be saved. Values like `15`, `205`, or invalid legacy cache values are ignored automatically.

For complete examples, see:

- `playground/src/preferences.ts`
- `playground/src/views/demos/features/preferences-extension/index.vue`

### Framework default configuration

::: details View the default configuration of the framework

```ts
const defaultPreferences: Preferences = {
  app: {
    accessMode: 'frontend',
    authPageLayout: 'panel-right',
    checkUpdatesInterval: 1,
    colorGrayMode: false,
    colorWeakMode: false,
    compact: false,
    contentCompact: 'wide',
    contentCompactWidth: 1200,
    contentPadding: 0,
    contentPaddingBottom: 0,
    contentPaddingLeft: 0,
    contentPaddingRight: 0,
    contentPaddingTop: 0,
    defaultAvatar:
      'https://unpkg.com/@vbenjs/static-source@0.1.7/source/avatar-v1.webp',
    defaultHomePath: '/dashboard/analytics',
    dynamicTitle: true,
    enableCheckUpdates: true,
    enablePreferences: true,
    enableCopyPreferences: true,
    enableRefreshToken: false,
    isMobile: false,
    layout: 'sidebar-nav',
    locale: 'zh-CN',
    loginExpiredMode: 'page',
    name: 'Vben Admin',
    preferencesButtonPosition: 'auto',
    watermark: false,
    zIndex: 200,
  },
  breadcrumb: {
    enable: true,
    hideOnlyOne: false,
    showHome: false,
    showIcon: true,
    styleType: 'normal',
  },
  copyright: {
    companyName: 'Vben',
    companySiteLink: 'https://www.vben.pro',
    date: '2024',
    enable: true,
    icp: '',
    icpLink: '',
    settingShow: true,
  },
  footer: {
    enable: false,
    fixed: false,
    height: 32,
  },
  header: {
    enable: true,
    height: 50,
    hidden: false,
    menuAlign: 'start',
    mode: 'fixed',
  },
  logo: {
    enable: true,
    fit: 'contain',
    source: 'https://unpkg.com/@vbenjs/static-source@0.1.7/source/logo-v1.webp',
    // sourceDark: 'https://unpkg.com/@vbenjs/static-source@0.1.7/source/logo-dark.webp', // 可选：深色主题标志
  },
  navigation: {
    accordion: true,
    split: true,
    styleType: 'rounded',
  },
  shortcutKeys: {
    enable: true,
    globalLockScreen: true,
    globalLogout: true,
    globalPreferences: true,
    globalSearch: true,
  },
  sidebar: {
    autoActivateChild: false,
    collapsed: false,
    collapsedButton: true,
    collapsedShowTitle: false,
    collapseWidth: 60,
    enable: true,
    expandOnHover: true,
    extraCollapse: false,
    extraCollapsedWidth: 60,
    fixedButton: true,
    hidden: false,
    mixedWidth: 80,
    width: 224,
  },
  tabbar: {
    draggable: true,
    enable: true,
    height: 38,
    keepAlive: true,
    maxCount: 0,
    middleClickToClose: false,
    persist: true,
    showIcon: true,
    showMaximize: true,
    showMore: true,
    styleType: 'chrome',
    wheelable: true,
  },
  theme: {
    builtinType: 'default',
    colorDestructive: 'hsl(348 100% 61%)',
    colorPrimary: 'hsl(212 100% 45%)',
    colorSuccess: 'hsl(144 57% 58%)',
    colorWarning: 'hsl(42 84% 61%)',
    mode: 'dark',
    radius: '0.5',
    semiDarkHeader: false,
    semiDarkSidebar: false,
  },
  transition: {
    enable: true,
    loading: true,
    name: 'fade-slide',
    progress: true,
  },
  widget: {
    fullscreen: true,
    globalSearch: true,
    languageToggle: true,
    lockScreen: true,
    notification: true,
    refresh: true,
    sidebarToggle: true,
    themeToggle: true,
  },
};
```

:::

::: details View the default configuration type of the framework

```ts
interface AppPreferences {
  /** 权限模式 */
  accessMode: AccessModeType;
  /** 登录和注册页面布局 */
  authPageLayout: AuthPageLayoutType;
  /** 检查更新的间隔 */
  checkUpdatesInterval: number;
  /** 是否启用灰色模式 */
  colorGrayMode: boolean;
  /** 是否启用色弱模式 */
  colorWeakMode: boolean;
  /** 是否启用紧凑模式 */
  compact: boolean;
  /** 是否启用内容紧凑模式 */
  contentCompact: ContentCompactType;
  /** 内容紧凑宽度 */
  contentCompactWidth: number;
  /** 内容内边距 */
  contentPadding: number;
  /** 内容底部内边距 */
  contentPaddingBottom: number;
  /** 内容左侧内边距 */
  contentPaddingLeft: number;
  /** 内容右侧内边距 */
  contentPaddingRight: number;
  /** 内容顶部内边距 */
  contentPaddingTop: number;
  // /** 默认应用头像 */
  defaultAvatar: string;
  /** 默认首页路径 */
  defaultHomePath: string;
  // /** 启用动态标题 */
  dynamicTitle: boolean;
  /** 是否启用更新检查 */
  enableCheckUpdates: boolean;
  /** 是否显示偏好设置 */
  enablePreferences: boolean;
  /** 是否显示复制偏好设置按钮 */
  enableCopyPreferences: boolean;
  /**
   * @zh_CN 是否启用 refreshToken
   */
  enableRefreshToken: boolean;
  /** 是否为移动设备 */
  isMobile: boolean;
  /** 布局方式 */
  layout: LayoutType;
  /** 支持的语言 */
  locale: SupportedLanguagesType;
  /** 登录过期模式 */
  loginExpiredMode: LoginExpiredModeType;
  /** 应用名称 */
  name: string;
  /** 偏好设置按钮位置 */
  preferencesButtonPosition: PreferencesButtonPositionType;
  /**
   * @zh_CN 是否启用水印
   */
  watermark: boolean;
  /** 堆叠层级 z-index */
  zIndex: number;
}
interface BreadcrumbPreferences {
  /** 是否启用面包屑导航 */
  enable: boolean;
  /** 仅有一个面包屑时是否隐藏 */
  hideOnlyOne: boolean;
  /** 是否显示面包屑中的首页图标 */
  showHome: boolean;
  /** 是否显示面包屑中的图标 */
  showIcon: boolean;
  /** 面包屑样式 */
  styleType: BreadcrumbStyleType;
}

interface CopyrightPreferences {
  /** 版权所有公司名称 */
  companyName: string;
  /** 版权所有公司的网站链接 */
  companySiteLink: string;
  /** 版权日期 */
  date: string;
  /** 是否显示版权 */
  enable: boolean;
  /** ICP 备案号 */
  icp: string;
  /** ICP 备案链接 */
  icpLink: string;
  /** 是否在设置面板中显示 */
  settingShow?: boolean;
}

interface FooterPreferences {
  /** 是否显示页脚 */
  enable: boolean;
  /** 是否固定页脚 */
  fixed: boolean;
  /** 页脚高度 */
  height: number;
}

interface HeaderPreferences {
  /** 是否启用页头 */
  enable: boolean;
  /** 页头高度 */
  height: number;
  /** 是否通过 CSS 隐藏页头 */
  hidden: boolean;
  /** 页头菜单对齐方式 */
  menuAlign: LayoutHeaderMenuAlignType;
  /** 页头显示模式 */
  mode: LayoutHeaderModeType;
}

interface LogoPreferences {
  /** 是否显示标志 */
  enable: boolean;
  /** 标志图片适配方式 */
  fit: 'contain' | 'cover' | 'fill' | 'none' | 'scale-down';
  /** 标志 URL */
  source: string;
  /** 深色主题标志 URL，可选；未设置时使用 source */
  sourceDark?: string;
}

interface NavigationPreferences {
  /** 导航菜单手风琴模式 */
  accordion: boolean;
  /** 是否拆分导航菜单，仅在 layout=mixed-nav 时生效 */
  split: boolean;
  /** 导航菜单样式 */
  styleType: NavigationStyleType;
}
interface SidebarPreferences {
  /** 点击目录时自动激活子菜单 */
  autoActivateChild: boolean;
  /** 是否折叠侧边栏 */
  collapsed: boolean;
  /** 是否显示侧边栏折叠按钮 */
  collapsedButton: boolean;
  /** 侧边栏折叠时是否显示标题 */
  collapsedShowTitle: boolean;
  /** 侧边栏折叠宽度 */
  collapseWidth: number;
  /** 是否显示侧边栏 */
  enable: boolean;
  /** 菜单自动展开状态 */
  expandOnHover: boolean;
  /** 是否折叠侧边栏扩展区域 */
  extraCollapse: boolean;
  /** 侧边栏扩展区域折叠宽度 */
  extraCollapsedWidth: number;
  /** 是否显示侧边栏固定按钮 */
  fixedButton: boolean;
  /** 是否通过 CSS 隐藏侧边栏 */
  hidden: boolean;
  /** 混合侧边栏宽度 */
  mixedWidth: number;
  /** 侧边栏宽度 */
  width: number;
}

interface ShortcutKeyPreferences {
  /** 是否全局启用快捷键 */
  enable: boolean;
  /** 是否启用全局锁屏快捷键 */
  globalLockScreen: boolean;
  /** 是否启用全局退出登录快捷键 */
  globalLogout: boolean;
  /** 是否启用全局偏好设置快捷键 */
  globalPreferences: boolean;
  /** 是否启用全局搜索快捷键 */
  globalSearch: boolean;
}

interface TabbarPreferences {
  /** 是否启用多标签拖拽 */
  draggable: boolean;
  /** 是否启用多标签 */
  enable: boolean;
  /** 标签页高度 */
  height: number;
  /** 是否启用标签页缓存 */
  keepAlive: boolean;
  /** 最大标签页数量 */
  maxCount: number;
  /** 是否在鼠标中键点击时关闭标签页 */
  middleClickToClose: boolean;
  /** 是否持久化标签页 */
  persist: boolean;
  /** 是否启用多标签图标 */
  showIcon: boolean;
  /** 是否显示最大化按钮 */
  showMaximize: boolean;
  /** 是否显示更多按钮 */
  showMore: boolean;
  /** 标签页样式 */
  styleType: TabsStyleType;
  /** 是否启用鼠标滚轮响应 */
  wheelable: boolean;
}
interface ThemePreferences {
  /** 内置主题名称 */
  builtinType: BuiltinThemeType;
  /** 危险操作颜色 */
  colorDestructive: string;
  /** 主色 */
  colorPrimary: string;
  /** 成功颜色 */
  colorSuccess: string;
  /** 警告颜色 */
  colorWarning: string;
  /** 当前主题 */
  mode: ThemeModeType;
  /** 圆角 */
  radius: string;
  /** 是否启用半深色页头，仅在 theme='light' 时生效 */
  semiDarkHeader: boolean;
  /** 是否启用半深色侧边栏，仅在 theme='light' 时生效 */
  semiDarkSidebar: boolean;
}

interface TransitionPreferences {
  /** 是否启用页面过渡动画 */
  enable: boolean;
  // /** 是否启用页面加载状态 */
  loading: boolean;
  /** 页面过渡动画 */
  name: PageTransitionType | string;
  /** 是否启用页面加载进度动画 */
  progress: boolean;
}

interface WidgetPreferences {
  /** 是否启用全屏组件 */
  fullscreen: boolean;
  /** 是否启用全局搜索组件 */
  globalSearch: boolean;
  /** 是否启用语言切换组件 */
  languageToggle: boolean;
  /** 是否启用锁屏功能 */
  lockScreen: boolean;
  /** 是否显示通知组件 */
  notification: boolean;
  /** 是否显示刷新按钮 */
  refresh: boolean;
  /** 是否显示侧边栏显隐组件 */
  sidebarToggle: boolean;
  /** 是否显示主题切换组件 */
  themeToggle: boolean;
}
interface Preferences {
  /** 全局配置 */
  app: AppPreferences;
  /** 页头配置 */
  breadcrumb: BreadcrumbPreferences;
  /** 版权配置 */
  copyright: CopyrightPreferences;
  /** 页脚配置 */
  footer: FooterPreferences;
  /** 面包屑配置 */
  header: HeaderPreferences;
  /** 标志配置 */
  logo: LogoPreferences;
  /** 导航配置 */
  navigation: NavigationPreferences;
  /** 快捷键配置 */
  shortcutKeys: ShortcutKeyPreferences;
  /** 侧边栏配置 */
  sidebar: SidebarPreferences;
  /** 标签栏配置 */
  tabbar: TabbarPreferences;
  /** 主题配置 */
  theme: ThemePreferences;
  /** 动画配置 */
  transition: TransitionPreferences;
  /** 组件配置 */
  widget: WidgetPreferences;
}
```

:::

::: warning Warning

- The `overridesPreferences` method only needs to override a part of the configurations in the project. There's no need to override configurations that are not needed; they will automatically use the default settings.
- Any configuration item can be overridden. You just need to override it within the `overridesPreferences` method. Do not modify the default configuration file.
- Please clear the cache after changing the configuration, otherwise it may not take effect.

:::
