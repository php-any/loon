<?php

declare(strict_types=1);

function contextCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

final class ContextProbe
{
    public function current(): mixed
    {
        return \Loom\Native\modelDatabase();
    }
}

$first = new ContextProbe();
$second = new ContextProbe();
contextCheck(\Loom\Native\modelDatabase() === null, 'No ambient database');
\Loom\Native\withModelDatabase($first, function () use ($first, $second): void {
    contextCheck($first->current() === $first, 'Method calls inherit the binding');
    $included = require __DIR__ . '/fixtures/native_context_include.php';
    contextCheck($included === $first, 'Included PHP inherits the execution binding');
    try {
        \Loom\Native\withModelDatabase($second, function () use ($second): void {
            contextCheck($second->current() === $second, 'Nested override');
            throw new RuntimeException('nested');
        });
    } catch (RuntimeException $error) {
        contextCheck($error->getMessage() === 'nested', 'Expected exception');
    }
    contextCheck($first->current() === $first, 'Exception preserves caller binding');
    $finished = new Channel(1);
    spawn(function () use ($first, $finished): void {
        $finished->send($first->current() === $first);
    });
    contextCheck($finished->receive(), 'Spawn inherits the explicit execution context');
});
contextCheck(\Loom\Native\modelDatabase() === null, 'Binding does not escape callback');

$ready = new Channel(2);
$resume = new Channel(2);
$results = new Channel(2);
foreach ([$first, $second] as $database) {
    spawn(function () use ($database, $ready, $resume, $results): void {
        \Loom\Native\withModelDatabase($database, function () use ($database, $ready, $resume, $results): void {
            $ready->send(true);
            $resume->receive();
            $results->send($database->current() === $database);
        });
    });
}
$ready->receive();
$ready->receive();
$resume->send(true);
$resume->send(true);
contextCheck($results->receive() && $results->receive(), 'Concurrent bindings remain isolated');
echo "loom_native_context_test: OK\n";
