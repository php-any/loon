<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 按模板输出日记行，占位符含 time、level、event、fields 及 field.xxx。
 */
final class PatternJournalFormatter implements JournalFormatter
{
    public function __construct(private readonly string $pattern)
    {
        if ($pattern === '') {
            throw new \InvalidArgumentException('Pattern journal formatter requires a non-empty pattern');
        }
    }

    public function format(JournalEntry $entry): string
    {
        $replacements = [
            '{time}' => $entry->time,
            '{level}' => $entry->level,
            '{event}' => $entry->event,
            '{fields}' => self::formatFields($entry->fields),
        ];
        $line = str_replace(array_keys($replacements), array_values($replacements), $this->pattern);
        return preg_replace_callback(
            '/\{field\.([^}]+)\}/',
            static function (array $matches) use ($entry): string {
                $key = $matches[1];
                if (!array_key_exists($key, $entry->fields)) {
                    return '';
                }
                return self::scalarToString($entry->fields[$key]);
            },
            $line,
        ) . "\n";
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function formatFields(array $fields): string
    {
        if ($fields === []) {
            return '';
        }
        $parts = [];
        foreach ($fields as $key => $value) {
            $parts[] = $key . '=' . self::scalarToString($value);
        }
        return implode(' ', $parts);
    }

    private static function scalarToString(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? '{}' : $encoded;
    }
}
