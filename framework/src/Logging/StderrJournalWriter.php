<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 每次写入时打开 php://stderr 写出日记行。
 *
 * @example (new StderrJournalWriter())->write("line\n");
 */
final class StderrJournalWriter implements JournalWriter
{
    public function write(string $line): void
    {
        $stream = @fopen('php://stderr', 'w');
        if ($stream === false) {
            return;
        }
        try {
            @fwrite($stream, $line);
        } finally {
            fclose($stream);
        }
    }
}
