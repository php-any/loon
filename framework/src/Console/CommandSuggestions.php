<?php

declare(strict_types=1);

namespace Loom\Console;

/**
 * 根据编辑距离在可见命令名中挑选与用户输入相近的候选，用于未知命令时的提示。
 */
final class CommandSuggestions
{
    private const MAX_SUGGESTIONS = 3;

    /**
     * @param list<string> $candidates 可见命令名（含 list、help 等内置名）
     */
    public static function format(string $typed, array $candidates): string
    {
        $matches = self::match($typed, $candidates);
        if ($matches === []) {
            return '';
        }
        if (count($matches) === 1) {
            return '你是不是想执行：' . $matches[0];
        }

        return '你是不是想执行：' . implode('、', $matches);
    }

    /**
     * @param list<string> $candidates
     * @return list<string>
     */
    public static function match(string $typed, array $candidates): array
    {
        $typed = trim($typed);
        if ($typed === '' || $candidates === []) {
            return [];
        }

        $group = self::groupPrefix($typed);
        if ($group !== '') {
            $sameGroup = [];
            foreach ($candidates as $name) {
                if (str_starts_with($name, $group)) {
                    $sameGroup[] = $name;
                }
            }
            $fromGroup = self::rank($typed, $sameGroup);
            if ($fromGroup !== []) {
                return array_slice($fromGroup, 0, self::MAX_SUGGESTIONS);
            }
        }

        $ranked = self::rank($typed, $candidates);

        return array_slice($ranked, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * @param list<string> $candidates
     * @return list<string>
     */
    private static function rank(string $typed, array $candidates): array
    {
        $scored = [];
        foreach ($candidates as $name) {
            if ($name === $typed) {
                continue;
            }
            $distance = self::levenshtein($typed, $name);
            if ($distance > self::maxDistance($typed, $name)) {
                continue;
            }
            $scored[] = [$distance, $name];
        }
        if ($scored === []) {
            return [];
        }
        usort($scored, static function (array $a, array $b) use ($typed): int {
            if ($a[0] !== $b[0]) {
                return $a[0] <=> $b[0];
            }
            $prefixA = str_starts_with($a[1], $typed) ? 0 : 1;
            $prefixB = str_starts_with($b[1], $typed) ? 0 : 1;
            if ($prefixA !== $prefixB) {
                return $prefixA <=> $prefixB;
            }

            return strcmp($a[1], $b[1]);
        });
        $names = [];
        foreach ($scored as [, $name]) {
            $names[] = $name;
        }

        return $names;
    }

    private static function groupPrefix(string $name): string
    {
        if (!str_contains($name, ':')) {
            return '';
        }

        return explode(':', $name, 2)[0] . ':';
    }

    private static function maxDistance(string $a, string $b): int
    {
        $length = max(strlen($a), strlen($b));

        return max(2, (int) floor($length * 0.4));
    }

    private static function levenshtein(string $a, string $b): int
    {
        $lenA = strlen($a);
        $lenB = strlen($b);
        if ($lenA === 0) {
            return $lenB;
        }
        if ($lenB === 0) {
            return $lenA;
        }
        $prev = range(0, $lenB);
        for ($i = 1; $i <= $lenA; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $lenB; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $cur;
        }

        return $prev[$lenB];
    }
}
