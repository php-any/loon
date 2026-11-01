import { describe, expect, it, vi } from 'vitest';

import { VxeGridApi } from '../api';
import { extendProxyOptions } from '../extends';

const FORM_VALUES = { category: 'cat-42' };

type QueryFn = (
  params: Record<string, unknown>,
  formValues: Record<string, unknown>,
) => Promise<unknown> | unknown;

function createApi(query: QueryFn) {
  return new VxeGridApi({
    gridOptions: { proxyConfig: { ajax: { query } } },
  });
}

function currentQuery(api: VxeGridApi): QueryFn | undefined {
  const query = api.state?.gridOptions?.proxyConfig?.ajax?.query;
  return typeof query === 'function' ? (query as QueryFn) : undefined;
}

function wrap(api: VxeGridApi) {
  extendProxyOptions(api, api.state?.gridOptions ?? {}, () => ({
    ...FORM_VALUES,
  }));
}

describe('extendProxyOptions', () => {
  it('is idempotent: wrapping the same options twice keeps a single wrapper', () => {
    const original = vi.fn();
    const api = createApi(original);

    wrap(api);
    const firstWrapper = currentQuery(api);
    expect(firstWrapper).not.toBe(original);
    expect(firstWrapper).toBeTypeOf('function');

    wrap(api);
    expect(currentQuery(api)).toBe(firstWrapper);
  });

  it('wraps callbacks installed by setGridOptions after the setup-time wrapping', async () => {
    const original = vi.fn();
    const replacement = vi.fn().mockResolvedValue({ total: 0 });
    const api = createApi(original);

    // setup 阶段包装
    wrap(api);

    // 父组件替换回调，例如在 onMounted 中调用 setGridOptions
    api.setGridOptions({ proxyConfig: { ajax: { query: replacement } } });
    expect(currentQuery(api)).toBe(replacement);

    // 选项更新后在 init 阶段重新包装
    wrap(api);
    const wrapped = currentQuery(api);
    expect(wrapped).not.toBe(replacement);
    expect(wrapped).toBeTypeOf('function');
    if (!wrapped) {
      throw new Error('expected the replacement to be wrapped');
    }

    // 替换后的回调接收合并的表单值
    await wrapped({}, {});
    expect(replacement).toHaveBeenCalledOnce();
    expect(replacement.mock.calls[0]?.[1]).toEqual(FORM_VALUES);
    expect(original).not.toHaveBeenCalled();

    // 再次包装仍保持幂等
    wrap(api);
    expect(currentQuery(api)).toBe(wrapped);
  });

  it('rebinds form values to the current mount when the same api re-wraps', async () => {
    const original = vi.fn().mockResolvedValue({ total: 0 });
    const api = createApi(original);

    // 首次挂载时安装绑定到第一个表单实例的包装器
    extendProxyOptions(api, api.state?.gridOptions ?? {}, () => ({ mount: 1 }));
    const wrapper = currentQuery(api);
    if (!wrapper) {
      throw new Error('expected the query to be wrapped');
    }

    // 表格使用同一 api 卸载并重新挂载：重新包装会跳过
    // 已安装的包装器，但必须重新绑定到新的表单实例
    extendProxyOptions(api, api.state?.gridOptions ?? {}, () => ({ mount: 2 }));

    await wrapper({}, {});
    expect(original).toHaveBeenCalledOnce();
    // 过期绑定会返回首次挂载时的值
    expect(original.mock.calls[0]?.[1]).toEqual({ mount: 2 });
  });
});
