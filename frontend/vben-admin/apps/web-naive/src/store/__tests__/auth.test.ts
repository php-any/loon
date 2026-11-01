import { LOGIN_PATH } from '@vben/constants';

import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useAuthStore } from '../auth';

const mocks = vi.hoisted(() => ({
  accessStore: {
    setAccessCodes: vi.fn(),
    setAccessToken: vi.fn(),
    setIsAccessChecked: vi.fn(),
    setLoginExpired: vi.fn(),
  },
  currentRoute: { value: { fullPath: '/dashboard', path: '/dashboard' } },
  resetAllStores: vi.fn(),
  routerReplace: vi.fn(),
  userStore: { setUserInfo: vi.fn() },
}));

vi.mock('vue-router', () => ({
  useRouter: () => ({
    currentRoute: mocks.currentRoute,
    replace: mocks.routerReplace,
  }),
}));

vi.mock('@vben/stores', () => ({
  resetAllStores: mocks.resetAllStores,
  useAccessStore: () => mocks.accessStore,
  useUserStore: () => mocks.userStore,
}));

vi.mock('@vben/preferences', () => ({
  preferences: { app: { defaultHomePath: '/dashboard' } },
}));

vi.mock('#/adapter/naive', () => ({
  notification: { success: vi.fn() },
}));

vi.mock('#/api', () => ({
  getAccessCodesApi: vi.fn(),
  getUserInfoApi: vi.fn(),
  loginApi: vi.fn(),
  logoutApi: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('#/locales', () => ({ $t: (key: string) => key }));

describe('authStore.logout', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('adds no redirect when the current route is already the login page', async () => {
    mocks.currentRoute.value = {
      fullPath: `${LOGIN_PATH}?redirect=%2Fdashboard`,
      path: LOGIN_PATH,
    };

    await useAuthStore().logout();

    expect(mocks.routerReplace).toHaveBeenCalledWith({
      path: LOGIN_PATH,
      query: {},
    });
  });

  it('still carries the return path when logging out from a business page', async () => {
    mocks.currentRoute.value = {
      fullPath: '/dashboard',
      path: '/dashboard',
    };

    await useAuthStore().logout();

    expect(mocks.routerReplace).toHaveBeenCalledWith({
      path: LOGIN_PATH,
      query: { redirect: encodeURIComponent('/dashboard') },
    });
  });

  it('keeps the URL bounded when logout runs repeatedly on the login page', async () => {
    // 回归测试：退出登录曾将之前的 fullPath 再次嵌入 redirect，
    // 导致重复退出时 URL 无限增长。
    let fullPath = LOGIN_PATH;

    for (let i = 0; i < 5; i += 1) {
      mocks.currentRoute.value = { fullPath, path: LOGIN_PATH };
      mocks.routerReplace.mockClear();

      await useAuthStore().logout();

      const navigated = mocks.routerReplace.mock.calls[0]?.[0] as {
        query?: Record<string, string>;
      };
      const redirect = navigated.query?.redirect;

      // vue-router 写入 URL 时会再次编码查询参数值
      fullPath =
        redirect === undefined
          ? LOGIN_PATH
          : `${LOGIN_PATH}?redirect=${encodeURIComponent(redirect)}`;
    }

    expect(fullPath).toBe(LOGIN_PATH);
  });
});
