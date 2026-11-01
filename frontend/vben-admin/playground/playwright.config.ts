import type { PlaywrightTestConfig } from '@playwright/test';

import { devices } from '@playwright/test';

/**
 * 从文件读取环境变量。
 * https://github.com/motdotla/dotenv
 */
// require('dotenv').config();

/**
 * 参阅 https://playwright.dev/docs/test-configuration。
 */
const config: PlaywrightTestConfig = {
  expect: {
    /**
     * expect() 等待条件成立的最长时间。
     * 例如 `await expect(locator).toHaveText();`
     */
    timeout: 5000,
  },
  /* 如果源码意外保留 test.only，则使 CI 构建失败。 */
  forbidOnly: !!process.env.CI,
  /* 保存截图、视频、跟踪记录等测试产物的目录。 */
  outputDir: 'node_modules/.e2e/test-results/',
  /* 配置主流浏览器的测试项目 */
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
      },
    },
    // {
    //   name: 'firefox',
    //   use: {
    //     ...devices['Desktop Firefox'],
    //   },
    // },
    // {
    //   name: 'webkit',
    //   use: {
    //     ...devices['Desktop Safari'],
    //   },
    // },

    /* 测试移动设备视口。 */
    // {
    //   name: 'Mobile Chrome',
    //   use: {
    //     ...devices['Pixel 5'],
    //   },
    // },
    // {
    //   name: 'Mobile Safari',
    //   use: {
    //     ...devices['iPhone 12'],
    //   },
    // },

    /* 测试品牌浏览器。 */
    // {
    //   name: 'Microsoft Edge',
    //   use: {
    //     channel: 'msedge',
    //   },
    // },
    // {
    //   name: 'Google Chrome',
    //   use: {
    //     channel: 'chrome',
    //   },
    // },
  ],
  /* 使用的报告器。参阅 https://playwright.dev/docs/test-reporters */
  reporter: [
    ['list'],
    ['html', { outputFolder: 'node_modules/.e2e/test-results' }],
  ],
  /* 仅在 CI 中重试 */
  retries: process.env.CI ? 2 : 0,
  testDir: './__tests__/e2e',
  /* 单个测试允许运行的最长时间。 */
  timeout: 30 * 1000,
  /* 下方所有项目共享的配置。参阅 https://playwright.dev/docs/api/class-testoptions。 */
  use: {
    /* click() 等操作允许执行的最长时间。默认 0，表示不限时。 */
    actionTimeout: 0,
    /* await page.goto('/') 等操作使用的基础 URL。 */
    baseURL: 'http://localhost:5555',
    /* 仅在 CI 系统中以无头模式运行测试 */
    headless: !!process.env.CI,
    /* 重试失败测试时收集跟踪记录。参阅 https://playwright.dev/docs/trace-viewer */
    trace: 'retain-on-failure',
  },

  /* 开始测试前运行本地开发服务器 */
  webServer: {
    command: process.env.CI ? 'pnpm preview --port 5555' : 'pnpm dev',
    port: 5555,
    reuseExistingServer: !process.env.CI,
  },

  /* 在 CI 中禁用并行测试。 */
  workers: process.env.CI ? 1 : undefined,
};

export default config;
