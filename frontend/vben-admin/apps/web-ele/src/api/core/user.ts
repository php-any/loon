import type { UserInfo } from '@vben/types';
import { menuPath } from './menu';
import { requestClient } from '#/api/request';
import { session, sessionReady, type PhpSession } from '#/api/session';
export async function getUserInfoApi(): Promise<UserInfo> {
  const data = await requestClient.get<PhpSession>('/admin/session');
  session.value = data;
  sessionReady.value = true;
  return {
    userId: String(data.user.id),
    username: data.user.email,
    realName: data.user.name,
    avatar: `${import.meta.env.BASE_URL}vben-avatar.webp`,
    roles: data.permissions.includes('*') ? ['super'] : ['member'],
    homePath: menuPath(
      data.menus.find((menu) => menu.path.startsWith('/admin'))?.path ||
        '/account',
    ),
  } as UserInfo;
}
