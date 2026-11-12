<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 将日记字段展开为人类可读的 key=value 行（标量留在首行片段，嵌套结构用点号与下标）。
 */
final class JournalFieldText
{
    /**
     * @param array<string, mixed> $fields
     * @return array{headerParts: list<string>, extraLines: list<string>}
     */
    public static function splitScalarsAndNested(array $fields): array
    {
        $headerParts = [];
        $extraLines = [];
        foreach ($fields as $key => $value) {
            if (self::isScalarOrNull($value)) {
                $headerParts[] = $key . '=' . self::scalarToString($value);
            } elseif (self::isEmptyContainer($value)) {
                continue;
            } else {
                self::expandNested((string) $key, $value, $extraLines);
            }
        }

        return ['headerParts' => $headerParts, 'extraLines' => $extraLines];
    }

    /**
     * @return list<string> 每项已带两空格缩进，不含尾部换行。
     */
    public static function expansionLinesForField(string $key, mixed $value): array
    {
        if (self::isScalarOrNull($value)) {
            return [];
        }
        if (self::isEmptyContainer($value)) {
            return [];
        }
        $lines = [];
        self::expandNested($key, $value, $lines);

        return $lines;
    }

    public static function scalarToString(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private static function isScalarOrNull(mixed $value): bool
    {
        return is_scalar($value) || $value === null;
    }

    private static function isEmptyContainer(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        return $value === [];
    }

    /**
     * @param list<string> $lines
     */
    private static function expandNested(string $prefix, mixed $value, array &$lines): void
    {
        if (!is_array($value)) {
            return;
        }
        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                $childKey = $prefix . '[' . $index . ']';
                self::expandValue($childKey, $item, $lines);
            }

            return;
        }
        foreach ($value as $childName => $item) {
            $childKey = $prefix . '.' . (string) $childName;
            self::expandValue($childKey, $item, $lines);
        }
    }

    /**
     * @param list<string> $lines
     */
    private static function expandValue(string $key, mixed $value, array &$lines): void
    {
        if (self::isScalarOrNull($value)) {
            $lines[] = '  ' . $key . '=' . self::scalarToString($value);

            return;
        }
        if (self::isEmptyContainer($value)) {
            return;
        }
        self::expandNested($key, $value, $lines);
    }
}
