import { computed, effectScope, nextTick, shallowRef } from 'vue';

import {
  getLayoutScrollElement,
  getScrollbarWidth,
  needsScrollbar,
} from '@vben-core/shared/utils';

import {
  useScrollLock as _useScrollLock,
  tryOnBeforeUnmount,
  tryOnMounted,
} from '@vueuse/core';

export const SCROLL_FIXED_CLASS = `_scroll__fixed_`;

interface ScrollLockOptions {
  immediate?: boolean;
}

let lockCount = 0;
let releaseLock: (() => void) | undefined;
const pendingTransitionRestores = new WeakMap<
  HTMLElement,
  { frame: number; restore: () => void }
>();

function getScrollLockTarget() {
  return getLayoutScrollElement() ?? document.body;
}

function captureStyle(element: HTMLElement, property: string) {
  const value = element.style.getPropertyValue(property);
  const priority = element.style.getPropertyPriority(property);
  return () => {
    if (value) {
      element.style.setProperty(property, value, priority);
    } else {
      element.style.removeProperty(property);
    }
  };
}

function disableTransition(node: HTMLElement) {
  const pending = pendingTransitionRestores.get(node);
  if (pending) {
    cancelAnimationFrame(pending.frame);
    pendingTransitionRestores.delete(node);
  }
  // 在恢复帧执行前开始新的锁定时，复用原始样式。
  const restore = pending?.restore ?? captureStyle(node, 'transition');
  node.style.transition = 'none';

  return () => {
    const frame = requestAnimationFrame(() => {
      pendingTransitionRestores.delete(node);
      restore();
    });
    pendingTransitionRestores.set(node, { frame, restore });
  };
}

function applyScrollbarStyles(target: HTMLElement): (() => void)[] {
  if (!needsScrollbar(target)) {
    return [];
  }

  if (target !== document.body) {
    const restore = captureStyle(target, 'scrollbar-gutter');
    target.style.setProperty('scrollbar-gutter', 'stable');
    return [restore];
  }

  if (!needsScrollbar()) {
    return [];
  }

  const width = `${getScrollbarWidth()}px`;
  const restore = [captureStyle(target, 'padding-right')];
  target.style.paddingRight = width;
  document
    .querySelectorAll<HTMLElement>(`.${SCROLL_FIXED_CLASS}`)
    .forEach((node) => {
      restore.push(
        captureStyle(node, 'padding-right'),
        disableTransition(node),
      );
      node.style.paddingRight = width;
    });

  return restore;
}

function acquireLock(target: HTMLElement) {
  const restore = applyScrollbarStyles(target);

  // 解除关联，避免一个遮罩卸载时释放共享锁。
  const scope = effectScope(true);
  scope.run(() => {
    const locked = _useScrollLock(target);
    locked.value = true;
  });
  return () => {
    scope.stop();
    restore.forEach((reset) => reset());
  };
}

export function useScrollLock(options: ScrollLockOptions = {}) {
  const { immediate = true } = options;
  const ownsLock = shallowRef(false);
  let disposed = false;

  const isLocked = computed({
    get: () => ownsLock.value,
    set(value: boolean) {
      // 每个调用方仅计数一次；忽略重复赋值和未使用的解锁操作。
      if (value === ownsLock.value) {
        return;
      }
      if (value) {
        releaseLock ??= acquireLock(getScrollLockTarget());
        lockCount += 1;
      } else if (--lockCount === 0) {
        releaseLock?.();
        releaseLock = undefined;
      }
      ownsLock.value = value;
    },
  });

  tryOnMounted(async () => {
    // 等待挂载钩子及其 DOM 更新完成后再测量溢出。
    await nextTick();
    if (disposed) {
      return;
    }
    const target = getScrollLockTarget();
    // 即使 overflow: hidden 使 needsScrollbar 为 false，也要加入已有锁。
    if (immediate && (lockCount > 0 || needsScrollbar(target))) {
      isLocked.value = true;
    }
  });

  tryOnBeforeUnmount(() => {
    disposed = true;
    isLocked.value = false;
  });

  return isLocked;
}
