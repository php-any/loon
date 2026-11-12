<?php

declare(strict_types=1);

namespace Loom\Logging;

use Loom\Config;

/**
 * 将 file 模板中的 {date}、{level} 替换为条目相关片段，并相对路径解析到应用根。
 *
 * @internal
 */
final class TemplateJournalNamer
{
    /**
     * @param array<string, mixed> $item stack 子项或空数组。
     * @return callable(JournalEntry): string
     */
    public static function callable(DiaryOptions $options, Config $config, array $item = []): callable
    {
        $fileTemplate = (string) ($item['file'] ?? $options->file);
        if ($fileTemplate === '') {
            $fileTemplate = 'storage/logs/diary-{date}.log';
        }
        $dateFormat = (string) ($item['date'] ?? $options->date);
        $timezone = (string) ($item['timezone'] ?? $options->timezone);
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new \LogicException('Invalid diary timezone: ' . $timezone, 0, $e);
        }
        return static function (JournalEntry $entry) use ($config, $fileTemplate, $dateFormat, $timezone): string {
            $path = self::expand($entry, $fileTemplate, $dateFormat, $timezone);
            return self::resolvePath($config, $path);
        };
    }

    public static function expand(JournalEntry $entry, string $template, string $dateFormat, string $timezone): string
    {
        $dateSegment = self::formatDate($entry->time, $dateFormat, $timezone);
        return str_replace(
            ['{date}', '{level}'],
            [$dateSegment, $entry->level],
            $template,
        );
    }

    public static function resolvePath(Config $config, string $path): string
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Diary file path must not be empty');
        }
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path;
        }
        return $config->path($path);
    }

    private static function formatDate(string $time, string $dateFormat, string $timezone): string
    {
        // Origami 上 DateTimeImmutable 构造器不能解析 JournalEntry 的 UTC ISO 串（…T…Z），须用 createFromFormat。
        $instant = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $time, new \DateTimeZone('UTC'));
        if ($instant === false) {
            throw new \LogicException('Invalid diary entry time: ' . $time);
        }
        $zoned = $instant->setTimezone(new \DateTimeZone($timezone));
        return $zoned->format($dateFormat);
    }
}
