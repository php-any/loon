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
        $split = JournalFieldText::splitScalarsAndNested($entry->fields);
        $replacements = [
            '{time}' => $entry->time,
            '{level}' => $entry->level,
            '{event}' => $entry->event,
            '{fields}' => implode(' ', $split['headerParts']),
        ];
        $line = str_replace(array_keys($replacements), array_values($replacements), $this->pattern);
        $line = preg_replace_callback(
            '/\{field\.([^}]+)\}/',
            static function (array $matches) use ($entry): string {
                $key = $matches[1];
                if (!array_key_exists($key, $entry->fields)) {
                    return '';
                }
                $value = $entry->fields[$key];
                if (is_scalar($value) || $value === null) {
                    return JournalFieldText::scalarToString($value);
                }

                return '';
            },
            $line,
        );
        $output = $line;
        if ($split['extraLines'] !== []) {
            $output .= "\n" . implode("\n", $split['extraLines']);
        }

        return $output . "\n";
    }
}
