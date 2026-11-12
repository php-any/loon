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
                self::formatter($options->formatter, $options, $registration, $config, []),
                self::resolveWriter($options->writer, $options, $registration, $config, []),
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
                $driver = (string) ($item['driver'] ?? '');
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
        return self::resolveWriter($options->writer, $options, $registration, $config, []);
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
            $driver = (string) ($item['driver'] ?? '');
            if ($driver === '') {
                throw new \LogicException('Diary stack item requires driver');
            }
            $formatterName = (string) ($item['formatter'] ?? $globalFormatter);
            $lanes[] = new LaneJournalSink(
                self::formatter($formatterName, $options, $registration, $config, $item),
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
        $factory = $registration->writerFactory($driver);
        if ($factory !== null) {
            $writer = $factory($options, $config, $item);
            if (!$writer instanceof JournalWriter) {
                throw new \LogicException('Diary writer factory must return JournalWriter');
            }
            return $writer;
        }
        return match ($driver) {
            'stderr' => new StderrJournalWriter(),
            'null' => new NullJournalWriter(),
            'file' => new FileJournalWriter(self::resolveNamer($options, $registration, $config, $item)),
            default => throw new \LogicException('Unsupported diary writer driver: ' . $driver),
        };
    }

  /**
   * @param array<string, mixed> $item
   * @return callable(JournalEntry): string
   */
    private static function resolveNamer(DiaryOptions $options, DiaryRegistration $registration, Config $config, array $item): callable
    {
        $namerName = (string) ($item['namer'] ?? $options->namer);
        $factory = $registration->namerFactory($namerName);
        if ($factory !== null) {
            return static fn (JournalEntry $entry): string => $factory($entry, $item);
        }
        if ($namerName === 'template') {
            return TemplateJournalNamer::callable($options, $config, $item);
        }
        throw new \LogicException('Unsupported diary namer: ' . $namerName);
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function formatter(
        string $name,
        DiaryOptions $options,
        DiaryRegistration $registration,
        Config $config,
        array $item = [],
    ): JournalFormatter {
        $factory = $registration->formatterFactory($name);
        if ($factory !== null) {
            $formatter = $factory($options, $config, $item);
            if (!$formatter instanceof JournalFormatter) {
                throw new \LogicException('Diary formatter factory must return JournalFormatter');
            }
            return $formatter;
        }
        return match ($name) {
            'text', 'plain' => new PlainTextJournalFormatter(),
            'pattern' => new PatternJournalFormatter(self::patternString($options, $item)),
            'json' => new JsonLineJournalFormatter(),
            default => throw new \LogicException('Unsupported diary formatter: ' . $name),
        };
    }

  /**
   * @param array<string, mixed> $item
   */
    private static function patternString(DiaryOptions $options, array $item): string
    {
        $pattern = (string) ($item['pattern'] ?? $options->pattern);
        if ($pattern === '') {
            throw new \LogicException('Pattern diary formatter requires pattern');
        }
        return $pattern;
    }
}
