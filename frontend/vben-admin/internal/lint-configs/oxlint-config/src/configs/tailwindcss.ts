import type { OxlintConfig } from 'oxlint';

import { fileURLToPath } from 'node:url';

import eslintPluginBetterTailwindcss from 'eslint-plugin-better-tailwindcss';
import { getDefaultSelectors } from 'eslint-plugin-better-tailwindcss/defaults';
import { SelectorKind } from 'eslint-plugin-better-tailwindcss/types';

const selectors = [
  ...getDefaultSelectors(),
  {
    kind: SelectorKind.Attribute,
    match: [{ type: 'objectValues' }],
    name: '^classNames$',
  },
];

const entryPoint = fileURLToPath(
  new URL('../../../../tailwind-config/src/theme.css', import.meta.url),
);

const settings = {
  entryPoint,
  selectors,
};

const tailwindcss: OxlintConfig = {
  // 自动生成的 shadcn-ui 内部代码有意不纳入管理。
  ignorePatterns: ['packages/@core/ui-kit/shadcn-ui/**/*'],
  jsPlugins: [
    {
      name: 'better-tailwindcss',
      specifier: 'eslint-plugin-better-tailwindcss',
    },
  ],
  rules: {
    ...eslintPluginBetterTailwindcss.configs.recommended.rules,
    'better-tailwindcss/enforce-consistent-class-order': [
      'error',
      {
        detectComponentClasses: true,
        unknownClassOrder: 'asc',
        unknownClassPosition: 'start',
      },
    ],
    // 由 Prettier 统一决定换行，避免反复格式化。
    'better-tailwindcss/enforce-consistent-line-wrapping': 'off',
    'better-tailwindcss/no-unknown-classes': 'off',
  },
  settings: {
    'better-tailwindcss': settings,
    'eslint-plugin-better-tailwindcss': settings,
  },
};

export { tailwindcss };
