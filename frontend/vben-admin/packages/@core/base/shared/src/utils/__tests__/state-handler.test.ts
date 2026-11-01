import { describe, expect, it } from 'vitest';

import { StateHandler } from '../state-handler';

describe('stateHandler', () => {
  it('should resolve when condition is set to true', async () => {
    const handler = new StateHandler();

    // 模拟异步设置 condition 为 true
    setTimeout(() => {
      handler.setConditionTrue(); // 明确触发 condition 为 true
    }, 10);

    // 等待条件被设置为 true
    await handler.waitForCondition();
    expect(handler.isConditionTrue()).toBe(true);
  });

  it('should resolve immediately if condition is already true', async () => {
    const handler = new StateHandler();
    handler.setConditionTrue(); // 提前设置为 true

    // 立即 resolve，因为 condition 已经是 true
    await handler.waitForCondition();
    expect(handler.isConditionTrue()).toBe(true);
  });

  it('should reject when condition is set to false after waiting', async () => {
    const handler = new StateHandler();

    // 模拟异步设置 condition 为 false
    setTimeout(() => {
      handler.setConditionFalse(); // 明确触发 condition 为 false
    }, 10);

    // 等待过程中，期望 Promise 被 reject
    await expect(handler.waitForCondition()).rejects.toThrow(
      'Condition was set to false',
    );
    expect(handler.isConditionTrue()).toBe(false);
  });

  it('should reset condition to false', () => {
    const handler = new StateHandler();
    handler.setConditionTrue(); // 设置为 true
    handler.reset(); // 重置为 false

    expect(handler.isConditionTrue()).toBe(false);
  });

  it('should resolve when condition is set to true after reset', async () => {
    const handler = new StateHandler();
    handler.reset(); // 确保初始为 false

    setTimeout(() => {
      handler.setConditionTrue(); // 重置后设置为 true
    }, 10);

    await handler.waitForCondition();
    expect(handler.isConditionTrue()).toBe(true);
  });
});

// StateHandler 是公共工具，提供“等待就绪后放行”的基础机制：
// form-api.getForm() 和 vxe-table api 在组件仍处于挂载阶段时
// 都会执行 `await waitForCondition()`。多个调用方可以同时等待，
// 例如在 <VbenForm /> 挂载前等待两个表单方法，因此
// 条件的一次变化必须结束所有挂起的等待，
// 而不只是最后注册的一个。
describe('stateHandler concurrent waiters', () => {
  it('resolves every waiter registered before the condition turns true', async () => {
    const handler = new StateHandler();

    const settled: Record<string, string> = {
      first: 'pending',
      second: 'pending',
      third: 'pending',
    };

    handler.waitForCondition().then(
      () => (settled.first = 'resolved'),
      () => (settled.first = 'rejected'),
    );
    handler.waitForCondition().then(
      () => (settled.second = 'resolved'),
      () => (settled.second = 'rejected'),
    );
    handler.waitForCondition().then(
      () => (settled.third = 'resolved'),
      () => (settled.third = 'rejected'),
    );

    handler.setConditionTrue();

    // 执行所有挂起等待者对应的 Promise 完成微任务。
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();

    expect(settled).toEqual({
      first: 'resolved',
      second: 'resolved',
      third: 'resolved',
    });
  });

  it('rejects every waiter registered before the condition turns false', async () => {
    const handler = new StateHandler();

    const settled: Record<string, string> = {
      first: 'pending',
      second: 'pending',
    };

    handler.waitForCondition().then(
      () => (settled.first = 'resolved'),
      () => (settled.first = 'rejected'),
    );
    handler.waitForCondition().then(
      () => (settled.second = 'resolved'),
      () => (settled.second = 'rejected'),
    );

    handler.setConditionFalse();

    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();

    expect(settled).toEqual({ first: 'rejected', second: 'rejected' });
  });
});

describe('stateHandler reset while callers wait', () => {
  it('settles a pending waiter instead of dropping it', async () => {
    const handler = new StateHandler();

    const outcome = handler.waitForCondition().then(
      () => 'resolved',
      (error: Error) => error.message,
    );
    handler.reset();

    expect(
      await Promise.race([
        outcome,
        new Promise((resolve) => setTimeout(() => resolve('pending'), 50)),
      ]),
    ).toBe('Condition was reset');
  });

  it('settles every waiter registered before the reset', async () => {
    const handler = new StateHandler();

    const settled: Record<string, string> = {
      first: 'pending',
      second: 'pending',
    };

    handler.waitForCondition().then(
      () => (settled.first = 'resolved'),
      () => (settled.first = 'rejected'),
    );
    handler.waitForCondition().then(
      () => (settled.second = 'resolved'),
      () => (settled.second = 'rejected'),
    );

    handler.reset();

    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();

    expect(settled).toEqual({ first: 'rejected', second: 'rejected' });
  });
});
