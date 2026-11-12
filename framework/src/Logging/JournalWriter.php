<?php

declare(strict_types=1);

namespace Loom\Logging;

/**
 * 日记序列化后的单行或多行文本写入目标。实现应假定调用方已处理业务级失败策略。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $writer->write("{\"event\":\"ping\"}\n", $entry);
 */
interface JournalWriter
{
    /**
     * 写出一条已格式化的日记行。实现可以每次打开目标或缓冲写入。
     *
     * @param string $line 格式化器产出的完整文本，通常以换行结尾。
     * @param JournalEntry $entry 原始结构化条目，供按日期或级别分文件等场景使用。
     * @return void 无返回值。目标不可用时由实现决定是抛出还是忽略。
     */
    public function write(string $line, JournalEntry $entry): void;
}
