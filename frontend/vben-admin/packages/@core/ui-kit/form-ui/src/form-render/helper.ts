import type { ZodType } from 'zod';

import { toRaw } from 'vue';

import { isObject, isString } from '@vben-core/shared/utils';

import { ZodPipe } from 'zod';

type UnwrappableZodType = ZodType & {
  unwrap?: () => ZodType;
};

/**
 * 获取最底层的 Zod 类型。
 * 这会解包可选类型、细化约束等。
 */
export function getBaseRules(schema?: null | string | ZodType): null | ZodType {
  if (!schema || isString(schema)) return null;
  const rawSchema = toRaw(schema);

  if (rawSchema instanceof ZodPipe) {
    return getBaseRules(rawSchema.in as ZodType);
  }

  // 在 zod v4 中，ZodArray 也有 unwrap()，但返回的是元素类型，
  // 而非外部包装。不能对它解包，否则 z.array(T) 会丢失
  // 数组外层，使数组值校验失败。
  const defType = (rawSchema as unknown as { _zod?: { def: { type: string } } })
    ._zod?.def?.type;
  if (defType === 'array') {
    return rawSchema;
  }

  const unwrappedSchema = (rawSchema as UnwrappableZodType).unwrap?.();
  if (unwrappedSchema && unwrappedSchema !== rawSchema) {
    return getBaseRules(unwrappedSchema);
  }

  return rawSchema;
}

/**
 * 在 Zod 类型栈中查找 ZodDefault 并返回其值。
 */
export function getDefaultValueInZodStack(
  schema?: null | string | ZodType,
): any {
  if (!schema || isString(schema)) {
    return;
  }

  try {
    const result = toRaw(schema).safeParse(undefined);
    return result.success ? result.data : undefined;
  } catch {
    return undefined;
  }
}

export function isEventObjectLike(obj: any) {
  if (!obj || !isObject(obj)) {
    return false;
  }
  return Reflect.has(obj, 'target') && Reflect.has(obj, 'stopPropagation');
}
