import { RequestClient } from '@vben/request';
import { ElMessage } from 'element-plus';
import { session, sessionReady, loginLocation } from './session';

export const requestClient = new RequestClient({
  baseURL: '/api',
  withCredentials: true,
});
requestClient.addRequestInterceptor({
  fulfilled(config) {
    if (
      session.value &&
      !['get', 'head', 'options'].includes(config.method || 'get')
    ) {
      config.headers['X-CSRF-Token'] = session.value.user.csrf;
    }
    return config;
  },
});
requestClient.addResponseInterceptor({
  fulfilled: (response) => response.data,
  rejected: async (error) => {
    const status = error.response?.status;
    const url = error.config?.url || '';
    const checkingSession = url === '/admin/session';
    if (status === 401 && !url.includes('/studio/login')) {
      session.value = null;
      sessionReady.value = true;
      const current =
        import.meta.env.VITE_ROUTER_HISTORY === 'hash'
          ? location.hash.slice(1)
          : location.pathname + location.search;
      if (!checkingSession && !current.startsWith('/auth/')) {
        location.assign(loginLocation(current));
      }
    }
    if (!(checkingSession && status === 401)) {
      ElMessage.error(
        error.response?.data?.error ||
          error.response?.data?.message ||
          '请求失败，请稍后重试',
      );
    }
    throw error;
  },
});
export const baseRequestClient = requestClient;
