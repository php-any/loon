import {
  appCopyrightPreferences,
  defineOverridesPreferences,
} from '@vben/preferences';

/**
 * @description 项目配置文件
 * 只需要覆盖项目中的一部分配置，不需要的配置不用覆盖，会自动使用默认配置
 * !!! 更改配置后请清空缓存，否则可能不生效
 */
export const overridesPreferences = defineOverridesPreferences({
  // 覆盖配置
  app: {
    name: import.meta.env.VITE_APP_TITLE,
    accessMode: 'backend',
    defaultHomePath: '/overview',
    defaultAvatar: `${import.meta.env.BASE_URL}vben-avatar.webp`,
    enableRefreshToken: false,
  },
  copyright: appCopyrightPreferences,
  logo: { source: `${import.meta.env.BASE_URL}vben-logo.webp` },
});
