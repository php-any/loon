import type { SupportedLanguagesType } from '@vben-core/typings';

export type { SupportedLanguagesType };

export type ImportLocaleFn = () => Promise<{ default: Record<string, string> }>;

export type LoadMessageFn = (
  lang: SupportedLanguagesType,
) => Promise<Record<string, string> | undefined>;

export interface LocaleSetupOptions {
  /**
   * 默认语言
   * @default zh-CN
   */
  defaultLocale?: SupportedLanguagesType;
  /**
   * 加载消息函数
   * @param lang
   * @returns
   */
  loadMessages?: LoadMessageFn;
  /**
   * 未找到键时是否警告
   */
  missingWarn?: boolean;
}
