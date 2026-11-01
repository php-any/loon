<script lang="ts" setup>
import type { NotificationItem } from '@vben/layouts';

import { computed, ref, watch, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import { ElNotification } from 'element-plus';

import { AuthenticationLoginExpiredModal } from '@vben/common-ui';
import { VBEN_DOC_URL, VBEN_GITHUB_URL } from '@vben/constants';
import { useWatermark } from '@vben/hooks';
import { BookOpenText, CircleHelp, SvgGithubIcon } from '@vben/icons';
import {
  BasicLayout,
  LockScreen,
  Notification,
  UserDropdown,
} from '@vben/layouts';
import { preferences, usePreferences } from '@vben/preferences';
import { useAccessStore, useUserStore } from '@vben/stores';
import { openWindow } from '@vben/utils';

import { $t } from '#/locales';
import { useAuthStore } from '#/store';
import { requestClient } from '#/api/request';
import { loginLocation } from '#/api/session';
import LoginForm from '#/views/_core/authentication/login.vue';

interface InboxItem { id: number; title: string; body: string; created: number; read_at: number | null }
interface InboxData { items: InboxItem[]; unread: number }
const notifications = ref<NotificationItem[]>([]);
const unreadCount = ref(0);
const notificationStatus = ref('正在连接通知服务');
let newestNotification: number | null = null;
function applyNotifications(data: InboxData) {
  const fresh = data.items.filter(item => newestNotification !== null && Number(item.id) > newestNotification && !item.read_at);
  for (const item of fresh.slice(0, 3).reverse()) {
    ElNotification({ title: item.title, message: item.body, type: 'success', duration: 8000, onClick: () => router.push('/inbox') });
  }
  newestNotification = Math.max(newestNotification ?? 0, ...data.items.map(item => Number(item.id)));
  unreadCount.value = Number(data.unread);
  notifications.value = data.items.map(item => ({
    id: item.id, title: item.title, message: item.body,
    date: new Date(Number(item.created) * 1000).toLocaleString(),
    isRead: !!item.read_at, avatar: preferences.app.defaultAvatar,
  }));
}
async function loadNotifications() {
  applyNotifications(await requestClient.get<InboxData>('/admin/operations/inbox'));
}
let notificationSocket: WebSocket | undefined;
let reconnectTimer: ReturnType<typeof setTimeout> | undefined;
let heartbeatTimer: ReturnType<typeof setTimeout> | undefined;
let stopped = false;
let reconnectDelay = 1000;
function connectNotifications() {
  if (stopped) return;
  const url = new URL('/api/admin/notifications/socket', window.location.href);
  url.protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
  const socket = new WebSocket(url);
  notificationSocket = socket;
  const armWatchdog = () => {
    clearTimeout(heartbeatTimer);
    heartbeatTimer = setTimeout(() => socket.close(), 12000);
  };
  armWatchdog();
  socket.onmessage = (event) => {
    if (stopped || notificationSocket !== socket) return;
    armWatchdog();
    try {
      const message = JSON.parse(event.data);
      if (message.type === 'notifications') {
        applyNotifications(message.data);
        notificationStatus.value = '实时通知已连接';
        reconnectDelay = 1000;
      } else if (message.type === 'unauthorized') {
        stopped = true;
        notifications.value = [];
        unreadCount.value = 0;
        notificationStatus.value = '登录已失效，请重新登录';
        socket.close();
        window.location.assign(loginLocation());
      } else if (message.type === 'unavailable') {
        notificationStatus.value = '通知服务暂不可用，正在重连';
        socket.close();
      }
    } catch { socket.close(); }
  };
  socket.onerror = () => { notificationStatus.value = '通知连接中断，正在重连'; };
  socket.onclose = () => {
    clearTimeout(heartbeatTimer);
    if (stopped || notificationSocket !== socket) return;
    notificationStatus.value = '通知连接中断，正在重连';
    reconnectTimer = setTimeout(connectNotifications, reconnectDelay + Math.random() * 500);
    reconnectDelay = Math.min(reconnectDelay * 2, 30000);
  };
}
onMounted(connectNotifications);
onUnmounted(() => {
  stopped = true;
  clearTimeout(reconnectTimer);
  clearTimeout(heartbeatTimer);
  notificationSocket?.close();
});
const router = useRouter();
const userStore = useUserStore();
const authStore = useAuthStore();
const accessStore = useAccessStore();
const { destroyWatermark, updateWatermark } = useWatermark();
const { isDark } = usePreferences();
const showDot = computed(() =>
  unreadCount.value > 0,
);

const menus = computed(() => [
  {
    handler: () => {
      router.push({ name: 'Profile' });
    },
    icon: 'lucide:user',
    text: $t('page.auth.profile'),
  },
  {
    handler: () => {
      openWindow(VBEN_DOC_URL, {
        target: '_blank',
      });
    },
    icon: BookOpenText,
    text: $t('ui.widgets.document'),
  },
  {
    handler: () => {
      openWindow(VBEN_GITHUB_URL, {
        target: '_blank',
      });
    },
    icon: SvgGithubIcon,
    text: 'GitHub',
  },
  {
    handler: () => {
      openWindow(`${VBEN_GITHUB_URL}/issues`, {
        target: '_blank',
      });
    },
    icon: CircleHelp,
    text: $t('ui.widgets.qa'),
  },
]);

const avatar = computed(() => {
  return userStore.userInfo?.avatar ?? preferences.app.defaultAvatar;
});

async function handleLogout() {
  await authStore.logout(false);
}

function handleNoticeClear() {
  notifications.value = [];
}

async function markRead(id: number | string) {
  await requestClient.post('/admin/operations/inbox', {
    action: 'read',
    id: Number(id),
  });
  await loadNotifications();
}
function remove(id: number | string) {
  notifications.value = notifications.value.filter((item) => item.id !== id);
}
async function handleMakeAll() {
  await requestClient.post('/admin/operations/inbox', { action: 'read_all' });
  await loadNotifications();
}
const viewAll = () => router.push('/inbox');

const handleClick = (item: NotificationItem) => {
  // 如果通知项有链接，点击时跳转
  if (item.link) {
    navigateTo(item.link, item.query, item.state);
  }
};

function navigateTo(
  link: string,
  query?: Record<string, any>,
  state?: Record<string, any>,
) {
  if (link.startsWith('http://') || link.startsWith('https://')) {
    // 外部链接，在新标签页打开
    window.open(link, '_blank');
  } else {
    // 内部路由链接，支持 query 参数和 state
    router.push({
      path: link,
      query: query || {},
      state,
    });
  }
}

watch(
  () => ({
    enable: preferences.app.watermark,
    content: preferences.app.watermarkContent,
    isDark: isDark.value,
  }),
  async ({ enable, content, isDark: isDarkValue }) => {
    if (enable) {
      const watermarkColor = isDarkValue
        ? 'rgba(255, 255, 255, 0.12)'
        : 'rgba(0, 0, 0, 0.12)';

      await updateWatermark({
        advancedStyle: {
          colorStops: [
            {
              color: watermarkColor,
              offset: 0,
            },
            {
              color: watermarkColor,
              offset: 1,
            },
          ],
          type: 'linear',
        },
        content:
          content ||
          `${userStore.userInfo?.username} - ${userStore.userInfo?.realName}`,
      });
    } else {
      destroyWatermark();
    }
  },
  {
    immediate: true,
  },
);
</script>

<template>
  <BasicLayout
    :avatar
    :text="userStore.userInfo?.realName"
    @clear-preferences-and-logout="handleLogout"
    @logout="handleLogout"
  >
    <template #user-dropdown>
      <UserDropdown
        :avatar
        :menus
        :text="userStore.userInfo?.realName"
        :description="userStore.userInfo?.username"
        tag-text=""
        @clear-preferences-and-logout="handleLogout"
        @logout="handleLogout"
      />
    </template>
    <template #notification>
      <Notification
        :title="notificationStatus"
        :dot="showDot"
        :notifications="notifications"
        @clear="handleNoticeClear"
        @read="(item) => item.id && markRead(item.id)"
        @remove="(item) => item.id && remove(item.id)"
        @make-all="handleMakeAll"
        @on-click="handleClick"
        @view-all="viewAll"
      />
    </template>
    <template #extra>
      <AuthenticationLoginExpiredModal
        v-model:open="accessStore.loginExpired"
        :avatar
      >
        <LoginForm />
      </AuthenticationLoginExpiredModal>
    </template>
    <template #lock-screen>
      <LockScreen :avatar @to-login="handleLogout" />
    </template>
  </BasicLayout>
</template>

