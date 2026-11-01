import { colors, consola } from '@vben/node-utils';

import { cac } from 'cac';

import { version } from '../package.json';
import { defineCheckCircularCommand } from './check-circular';
import { defineCheckDepCommand } from './check-dep';
import { defineCodeWorkspaceCommand } from './code-workspace';
import { defineLintCommand, LintError } from './lint';
import { definePubLintCommand } from './publint';

// 命令描述
const COMMAND_DESCRIPTIONS = {
  'check-circular': 'Check for circular dependencies',
  'check-dep': 'Check for unused dependencies',
  'code-workspace': 'Manage VS Code workspace settings',
  lint: 'Run linting on the project',
  publint: 'Check package.json files for publishing standards',
} as const;

/**
 * 初始化并运行 CLI
 */
async function main(): Promise<void> {
  try {
    const vsh = cac('vsh');

    // 注册命令
    defineLintCommand(vsh);
    definePubLintCommand(vsh);
    defineCodeWorkspaceCommand(vsh);
    defineCheckCircularCommand(vsh);
    defineCheckDepCommand(vsh);

    // 配置 CLI
    vsh.usage('vsh <command> [options]');
    vsh.help();
    vsh.version(version);

    // 解析参数但不自动执行，以检测未知命令
    // 注意：cac v7 已移除 EventEmitter；请在解析后使用 matchedCommand
    vsh.parse(undefined, { run: false });

    if (!vsh.matchedCommand && vsh.args.length > 0) {
      const unknownCmd = String(vsh.args[0]);
      consola.error(
        colors.red(`Invalid command: ${unknownCmd}`),
        '\n',
        colors.yellow('Available commands:'),
        '\n',
        Object.entries(COMMAND_DESCRIPTIONS)
          .map(([name, desc]) => `  ${colors.cyan(name)} - ${desc}`)
          .join('\n'),
      );
      process.exit(1);
    }

    await vsh.runMatchedCommand();
  } catch (error) {
    // lint 检查失败是预期内的结果（格式/规范不达标），
    // 直接展示可操作的错误信息，而非笼统的 "unexpected error"
    if (error instanceof LintError) {
      consola.error(error.message);
      process.exit(1);
    }
    consola.error(
      colors.red('An unexpected error occurred:'),
      '\n',
      error instanceof Error ? error.message : error,
    );
    process.exit(1);
  }
}

// 运行 CLI
main().catch((error) => {
  consola.error(
    colors.red('Failed to start CLI:'),
    '\n',
    error instanceof Error ? error.message : error,
  );
  process.exit(1);
});
