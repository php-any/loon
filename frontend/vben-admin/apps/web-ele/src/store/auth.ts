import type { Recordable } from '@vben/types';
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { resetAllStores, useAccessStore, useUserStore } from '@vben/stores';
import { defineStore } from 'pinia';
import { getUserInfoApi, loginApi, logoutApi } from '#/api';
import { session, sessionReady, loginLocation } from '#/api/session';
export const useAuthStore = defineStore('auth', () => {
  const accessStore = useAccessStore();
  const userStore = useUserStore();
  const router = useRouter();
  const loginLoading = ref(false);
  async function fetchUserInfo() {
    const user = await getUserInfoApi();
    userStore.setUserInfo(user);
    accessStore.setAccessCodes(session.value?.permissions || []);
    return user;
  }
  async function initialize() {
    if (sessionReady.value) return;
    // 丢弃缓存的演示凭据和路由，以服务端为准。
    resetAllStores();
    try {
      await fetchUserInfo();
    } catch (error: any) {
      if (error.response?.status !== 401) throw error;
    }
    sessionReady.value = true;
  }
  async function authLogin(
    params: Recordable<any>,
    onSuccess?: () => Promise<void> | void,
  ) {
    loginLoading.value = true;
    try {
      await loginApi(params);
      const userInfo = await fetchUserInfo();
      accessStore.setIsAccessChecked(false);
      accessStore.setLoginExpired(false);
      const redirect = router.currentRoute.value.query.redirect;
      let target = userInfo.homePath || '/account';
      if (typeof redirect === 'string') {
        const decoded = decodeURIComponent(redirect);
        if (
          decoded.startsWith('/') &&
          !decoded.startsWith('//') &&
          !decoded.startsWith('/auth')
        )
          target = decoded;
      }
      onSuccess ? await onSuccess() : await router.replace(target);
      return { userInfo };
    } finally {
      loginLoading.value = false;
    }
  }
  async function logout(redirect = true) {
    try {
      await logoutApi();
    } catch (error: any) {
      if (error.response?.status !== 401) throw error;
    }
    const path = router.currentRoute.value.fullPath;
    session.value = null;
    sessionReady.value = true;
    resetAllStores();
    location.assign(
      loginLocation(redirect && !path.startsWith('/auth') ? path : undefined),
    );
  }
  function $reset() {
    loginLoading.value = false;
  }
  return { $reset, authLogin, fetchUserInfo, initialize, loginLoading, logout };
});
