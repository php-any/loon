<?php

declare(strict_types=1);

namespace Loom\Logging;

use Loom\Config;

/**
 * 根据 DiaryOptions 与扩展登记组装 JournalSink / JournalWriter。
 *
 * @internal
 */
final class DiaryComposer
{
    public static function createSink(DiaryOptions $options, DiaryRegistration $registration, Config $config): JournalSink
    {
        return match ($options->writer) {
            'stack' => self::stackSink($options, $registration, $config),
            default => new LaneJournalSink(
                self::formatter($options->formatter),
                self::resolveWriter($options->writer, $options, $registration, $config),
            ),
        };
    }

    public static function createWriter(DiaryOptions $options, DiaryRegistration $registration, Config $config): JournalWriter
    {
        if ($options->writer === 'stack') {
            $writers = [];
            foreach ($options->stack as $item) {
                if (!is_array($item)) {
                    throw new \LogicException('Diary stack item must be an array');
                }
                $driver = (string)($item['driver'] ?? '');
                if ($driver === '') {
                    throw new \LogicException('Diary stack item requires driver');
                }
                $writers[] = self::resolveWriter($driver, $options, $registration, $config, $item);
            }
            if ($writers === []) {
                throw new \LogicException('Diary stack must not be empty');
            }
            return new StackJournalWriter($writers);
        }
        return self::resolveWriter($options->writer, $options, $registration, $config);
    }

  /**
   * @param array<string, mixed> $item
   */
    private static function stackSink(DiaryOptions $options, DiaryRegistration $registration, Config $config): StackJournalSink
    {
        $lanes = [];
        $globalFormatter = $options->formatter;
        foreach ($options->stack as $item) {
            if (!is_array($item)) {
                throw new \LogicException('Diary stack item must be an array');
            }
            $driver = (string)($item['driver'] ?? '');
            if ($driver === '') {
                throw new \LogicException('Diary stack item requires driver');
            }
            $formatterName = (string)($item['formatter'] ?? $globalFormatter);
            $lanes[] = new LaneJournalSink(
                self::formatter($formatterName),
                self::resolveWriter($driver, $options, $registration, $config, $item),
            );
        }
        if ($lanes === []) {
            throw new \LogicException('Diary stack must not be empty');
        }
        return new StackJournalSink($lanes);
    }

  /**
   * @param array<string, mixed> $item
   */
    private static function resolveWriter(
        string $driver,
        DiaryOptions $options,
        DiaryRegistration $registration,
        Config $config,
        array $item = [],
    ): JournalWriter {
        $factory = $registration->factory($driver);
        if ($factory !== null) {
            $writer = $factory($options, $config);
            if (!$writer instanceof JournalWriter) {
                throw new \LogicException('Diary writer factory must return JournalWriter');
            }
            return $writer;
        }
        return match ($driver) {
            'stderr' => new StderrJournalWriter(),
            'null' => new NullJournalWriter(),
            'file' => new FileJournalWriter(
                self::filePath($options, $config, $item),
            ),
            default => throw new \LogicException('Unsupported diary writer driver: ' . $driver),
        };
    }

  /**
   * @param array<string, mixed> $item
   */
    private static function filePath(DiaryOptions $options, Config $config, array $item = []): string
    {
        $configured = (string)($item['file'] ?? $options->file);
        if ($configured !== '') {
            return $configured;
        }
        return $config->path('storage/logs/diary.log');
    }

    public static function formatter(string $name): JournalFormatter
    {
        return match ($name) {
            'text', 'plain' => new PlainTextJournalFormatter(),
            default => new JsonLineJournalFormatter(),
        };
    }
}
