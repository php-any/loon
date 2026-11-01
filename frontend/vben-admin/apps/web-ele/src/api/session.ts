import { ref } from 'vue';
export interface PhpSession {
  user: { id: number; name: string; email: string; csrf: string };
  permissions: string[];
  menus: {
    id: number;
    parent_id: number | null;
    label: string;
    path: string;
    sort_order: number;
  }[];
  verified: boolean;
}
// 认证由 PHP 的 HttpOnly cookie 管理；绝不持久化持有者令牌。
export const session = ref<null | PhpSession>(null);
export const sessionReady = ref(false);
export function can(permission: string) {
  return !!session.value?.permissions.some(
    (code) => code === '*' || code === permission,
  );
}

export function loginLocation(redirect?: string) {
  const login =
    import.meta.env.VITE_ROUTER_HISTORY === 'hash'
      ? `${import.meta.env.VITE_BASE}#/auth/login`
      : '/auth/login';
  return redirect ? `${login}?redirect=${encodeURIComponent(redirect)}` : login;
}
