import { addCollection } from '@vben/icons';
import menuIcons from '../menu-icons.json';
import type { RouteRecordStringComponent } from '@vben/types';
import { session } from '#/api/session';
addCollection(menuIcons);

export function menuPath(path: string) {
  return path.replace(/^\/admin#\/?/, '/').replace(/^\/admin$/, '/overview');
}
const pages = new Set([
  'overview',
  'workspace',
  'users',
  'customers',
  'purchases',
  'roles',
  'permissions',
  'menus',
  'settings',
  'audit',
  'orders',
  'queue',
  'schedules',
  'notifications',
  'monitor',
  'channels',
  'inbox',
  'account',
]);
export async function getAllMenusApi(): Promise<RouteRecordStringComponent[]> {
  const rows = session.value?.menus || [];
  function children(parent: number | null): RouteRecordStringComponent[] {
    return rows
      .filter((m) => Number(m.parent_id || 0) === Number(parent || 0))
      .map((m) => {
        const path = menuPath(m.path);
        const nested = children(m.id);
        const key = path.slice(1);
        const external = !!m.path && !pages.has(key);
        return {
          name: `PhpMenu${m.id}`,
          path: path || `/group/${m.id}`,
          component: nested.length
            ? 'BasicLayout'
            : pages.has(key)
              ? `/business/${key}/index`
              : '/_core/fallback/not-found',
          meta: {
            title: m.label,
            order: m.sort_order,
            icon: nested.length ? 'lucide:folder' : 'lucide:file-text',
            ...(external ? { link: `http://127.0.0.1:18087${m.path}` } : {}),
          },
          ...(nested.length
            ? { children: nested, redirect: nested[0]!.path }
            : {}),
        };
      });
  }
  const routes = children(null);
  routes.push({
    name: 'Profile',
    path: '/account',
    component: '/business/account/index',
    meta: { title: '个人中心', hideInMenu: true },
  });
  if (!rows.some((m) => menuPath(m.path) === '/inbox'))
    routes.push({
      name: 'Inbox',
      path: '/inbox',
      component: '/business/inbox/index',
      meta: { title: '消息中心', icon: 'lucide:bell' },
    });
  if (
    session.value?.permissions.some(
      (p) => p === '*' || p === 'channels.manage',
    ) &&
    !rows.some((m) => menuPath(m.path) === '/channels')
  )
    routes.push({
      name: 'Channels',
      path: '/channels',
      component: '/business/channels/index',
      meta: { title: '通知渠道', icon: 'lucide:send' },
    });
  return routes;
}

