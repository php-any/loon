import type { VxeUIExport } from 'vxe-table';

import type { Recordable } from '@vben/types';

import type { VxeGridApi } from './api';
import type { VxeGridProps as VbenVxeGridProps } from './types';

import { formatDate, formatDateTime, isFunction } from '@vben/utils';

type VbenGridOptions = NonNullable<VbenVxeGridProps['gridOptions']>;

type FormValuesGetter = () => Recordable<any>;

type AjaxFn = (...args: any[]) => any;

const PROXY_AJAX_KEYS = [
  'query',
  'querySuccess',
  'queryError',
  'queryAll',
  'queryAllSuccess',
  'queryAllError',
] as const;

export function extendProxyOptions(
  api: VxeGridApi,
  options: VbenGridOptions,
  getFormValues: FormValuesGetter,
) {
  formValueGetters.set(api, getFormValues);
  PROXY_AJAX_KEYS.forEach((key) => {
    extendProxyOption(key, api, options);
  });
}

/**
 * 就地包装传入选项中的 proxyConfig.ajax 回调（不写回 api 状态）。
 *
 * gridOptions 可能由父组件以 prop 传入，prop 的优先级高于 state，
 * extendProxyOptions 写入 state 的包装器会被 prop 遮蔽。因此对最终
 * 传给 VxeGrid 的选项直接包装（调用方传入克隆对象，源数据不会被修改）。
 */
export function wrapProxyAjaxOptions(
  api: VxeGridApi,
  options: VbenGridOptions,
  getFormValues: FormValuesGetter,
) {
  formValueGetters.set(api, getFormValues);
  const ajax = options.proxyConfig?.ajax as Recordable<any> | undefined;
  if (!ajax) {
    return;
  }
  PROXY_AJAX_KEYS.forEach((key) => {
    ajax[key] = wrapAjaxFn(api, ajax[key]);
  });
}

export function extendsDefaultFormatter(vxeUI: VxeUIExport) {
  vxeUI.formats.add('formatDate', {
    tableCellFormatMethod({ cellValue }) {
      return formatDate(cellValue);
    },
  });

  vxeUI.formats.add('formatDateTime', {
    tableCellFormatMethod({ cellValue }) {
      return formatDateTime(cellValue);
    },
  });
}

// 跟踪 wrapAjaxFn 包装的 ajax 处理器，使重复包装不产生额外操作，
// 避免叠加包装器，例如 setup 阶段包装、init 阶段重新包装以及
// 选项重新计算。以函数身份为键。
const wrappedAjaxFns = new WeakSet<object>();

// 每个 api 维护源函数到包装器的映射：同一源函数复用
// 同一包装器，确保选项重新计算时传给 VxeGrid 的
// 引用保持稳定。包装器捕获各自的 api，因此映射绝不在不同
// api 之间共享。
const ajaxFnWrappers = new WeakMap<object, WeakMap<object, AjaxFn>>();

// 每个 api 保存最新的表单取值函数。包装器在调用时解析它，
// 因此即使 api 及其包装的回调在卸载和重新挂载后仍保留，
// 也始终绑定到当前挂载的 formApi。
const formValueGetters = new WeakMap<object, FormValuesGetter>();

function extendProxyOption(
  key: string,
  api: VxeGridApi,
  options: VbenGridOptions,
) {
  const { proxyConfig } = options;
  const configFn = (proxyConfig?.ajax as Recordable<any> | undefined)?.[key];
  const wrapperFn = wrapAjaxFn(api, configFn);
  if (wrapperFn === configFn) {
    return options;
  }
  api.setState({
    gridOptions: {
      proxyConfig: {
        ajax: {
          [key]: wrapperFn,
        },
      },
    },
  });
}

/**
 * 返回 configFn 的包装器（幂等）：非函数或已包装的函数原样返回；
 * 同一 api 内同一源函数复用同一包装器，保持引用稳定。
 */
function wrapAjaxFn(api: VxeGridApi, configFn: unknown): unknown {
  if (!isFunction(configFn) || wrappedAjaxFns.has(configFn)) {
    return configFn;
  }
  let apiWrappers = ajaxFnWrappers.get(api);
  if (!apiWrappers) {
    apiWrappers = new WeakMap();
    ajaxFnWrappers.set(api, apiWrappers);
  }
  let wrapperFn = apiWrappers.get(configFn);
  if (!wrapperFn) {
    wrapperFn = async (
      params: Recordable<any>,
      customValues: Recordable<any>,
      ...args: Recordable<any>[]
    ) => {
      const formValues = formValueGetters.get(api)?.() ?? {};
      const data = await configFn(
        params,
        {
          /**
           * 开启toolbarConfig.refresh功能
           * 点击刷新按钮 这里的值为PointerEvent 会携带错误参数
           */
          ...(customValues instanceof PointerEvent ? {} : customValues),
          ...formValues,
        },
        ...args,
      );
      return data;
    };
    wrappedAjaxFns.add(wrapperFn);
    apiWrappers.set(configFn, wrapperFn);
  }
  return wrapperFn;
}
