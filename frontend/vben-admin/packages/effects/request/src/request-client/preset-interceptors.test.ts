import axios from 'axios';
import MockAdapter from 'axios-mock-adapter';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { authenticateResponseInterceptor } from './preset-interceptors';
import { RequestClient } from './request-client';

describe('authenticateResponseInterceptor', () => {
  let mock: MockAdapter;

  beforeEach(() => {
    mock = new MockAdapter(axios);
  });

  afterEach(() => {
    mock.reset();
  });

  function createClient(
    doRefreshToken: () => Promise<string>,
    getToken: () => null | string = () => null,
  ) {
    const client = new RequestClient();
    const reAuthenticate = vi.fn(async () => {});
    // 应用通过请求拦截器附加当前令牌，
    // 重试请求也通过此方式获取刷新后的令牌。
    client.addRequestInterceptor({
      fulfilled: (config) => {
        const token = getToken();
        if (token) {
          config.headers.Authorization = `Bearer ${token}`;
        }
        return config;
      },
    });
    client.addResponseInterceptor(
      authenticateResponseInterceptor({
        client,
        doReAuthenticate: reAuthenticate,
        doRefreshToken,
        enableRefreshToken: true,
        formatToken: (token) => (token ? `Bearer ${token}` : null),
      }),
    );
    return { client, reAuthenticate };
  }

  it('fails the queued requests when the token refresh fails', async () => {
    const refreshToken = vi.fn(
      () =>
        new Promise<string>((_resolve, reject) => {
          setTimeout(() => {
            reject(new Error('refresh failed'));
          }, 100);
        }),
    );
    const { client, reAuthenticate } = createClient(refreshToken);

    const calls: string[] = [];
    mock.onGet('/initiator').reply(() => {
      calls.push('/initiator');
      return [401];
    });
    // 请求在刷新尚未完成时到达，因此进入队列等待。
    mock.onGet('/queued').reply(
      () =>
        new Promise((resolve) => {
          setTimeout(() => {
            calls.push('/queued');
            resolve([401]);
          }, 20);
        }),
    );

    const results = await Promise.allSettled([
      client.get('/initiator'),
      client.get('/queued'),
    ]);

    expect(results.map((result) => result.status)).toEqual([
      'rejected',
      'rejected',
    ]);
    // 一次刷新失败不得触发队列中的另一次刷新，所有
    // 排队请求都应失败，不能在没有令牌时重放。
    expect(refreshToken).toHaveBeenCalledTimes(1);
    expect(reAuthenticate).toHaveBeenCalledTimes(1);
    expect(calls).toEqual(['/initiator', '/queued']);
  });

  it('replays the queued requests with the refreshed token', async () => {
    let currentToken: null | string = null;
    const refreshToken = vi.fn(
      () =>
        new Promise<string>((resolve) => {
          setTimeout(() => {
            currentToken = 'new-token';
            resolve('new-token');
          }, 100);
        }),
    );
    const { client, reAuthenticate } = createClient(
      refreshToken,
      () => currentToken,
    );

    const initiatorHeaders: (null | string)[] = [];
    const queuedHeaders: (null | string)[] = [];

    mock.onGet('/initiator').reply((config) => {
      const authorization = (config.headers?.Authorization as string) ?? null;
      initiatorHeaders.push(authorization);
      return authorization === 'Bearer new-token' ? [200, {}] : [401];
    });
    mock.onGet('/queued').reply((config) => {
      const authorization = (config.headers?.Authorization as string) ?? null;
      queuedHeaders.push(authorization);
      if (authorization === 'Bearer new-token') {
        return [200, {}];
      }
      return new Promise((resolve) => {
        setTimeout(() => resolve([401]), 20);
      });
    });

    await Promise.all([client.get('/initiator'), client.get('/queued')]);

    expect(refreshToken).toHaveBeenCalledTimes(1);
    expect(reAuthenticate).not.toHaveBeenCalled();
    expect(initiatorHeaders).toEqual([null, 'Bearer new-token']);
    expect(queuedHeaders).toEqual([null, 'Bearer new-token']);
  });
});
