import { requestClient } from '#/api/request';
import { session } from '#/api/session';
export namespace AuthApi {
  export interface LoginParams {
    email?: string;
    username?: string;
    password?: string;
  }
}
export async function loginApi(data: AuthApi.LoginParams) {
  return requestClient.post('/studio/login', {
    email: data.email || data.username,
    password: data.password,
  });
}
export async function logoutApi() {
  return requestClient.post('/studio/logout');
}
export async function getAccessCodesApi() {
  return session.value?.permissions || [];
}
