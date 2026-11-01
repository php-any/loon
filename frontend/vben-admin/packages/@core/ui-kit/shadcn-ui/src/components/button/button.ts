import type { AsTag } from 'reka-ui';

import type { Component } from 'vue';

import type { ButtonVariants } from '../../ui';

export interface VbenButtonProps {
  /**
   * 此组件应渲染为的元素或组件，可由 `asChild` 覆盖
   * @defaultValue "div"
   */
  as?: AsTag | Component;
  /**
   * 使用传入的子元素替换默认渲染元素，并合并它们的属性与行为。
   *
   * 更多信息请阅读[组合指南](https://www.reka-ui.com/docs/guides/composition)。
   */
  asChild?: boolean;
  class?: any;
  disabled?: boolean;
  loading?: boolean;
  size?: ButtonVariants['size'];
  variant?: ButtonVariants['variant'];
}

export type CustomRenderType = (() => Component | string) | string;

export type ValueType = boolean | number | string;

export interface VbenButtonGroupProps extends Pick<
  VbenButtonProps,
  'disabled'
> {
  /** 单选模式下允许清除选中 */
  allowClear?: boolean;
  /** 值改变前的回调 */
  beforeChange?: (
    value: ValueType,
    isChecked: boolean,
  ) => boolean | PromiseLike<boolean | undefined> | undefined;
  /** 按钮样式 */
  btnClass?: any;
  /** 按钮间隔距离 */
  gap?: number;
  /** 多选模式下限制最多选择的数量。0表示不限制 */
  maxCount?: number;
  /** 是否允许多选 */
  multiple?: boolean;
  /** 选项 */
  options?: { [key: string]: any; label: CustomRenderType; value: ValueType }[];
  /** 显示图标 */
  showIcon?: boolean;
  /** 尺寸 */
  size?: 'large' | 'middle' | 'small';
}
