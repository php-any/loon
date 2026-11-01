import type { SortableOptions } from 'sortablejs';

import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useSortable } from '../use-sortable';

describe('useSortable', () => {
  beforeEach(() => {
    vi.mock('sortablejs/modular/sortable.complete.esm.js', () => ({
      default: {
        create: vi.fn(),
      },
    }));
  });
  it('should call Sortable.create with the correct options', async () => {
    // 创建模拟元素
    const mockElement = document.createElement('div') as HTMLDivElement;

    // 定义自定义选项
    const customOptions: SortableOptions = {
      group: 'test-group',
      sort: false,
    };

    // 使用 useSortable 函数
    const { initializeSortable } = useSortable(mockElement, customOptions);

    // 初始化 sortable
    await initializeSortable();

    // 导入 sortablejs，以访问模拟的 create 函数
    const Sortable =
      // @ts-expect-error - 此处为动态导入
      await import('sortablejs/modular/sortable.complete.esm.js');

    // 验证 Sortable.create 使用了正确的参数
    expect(Sortable.default.create).toHaveBeenCalledTimes(1);
    expect(Sortable.default.create).toHaveBeenCalledWith(
      mockElement,
      expect.objectContaining({
        animation: 300,
        delay: 400,
        delayOnTouchOnly: true,
        ...customOptions,
      }),
    );
  });
});
