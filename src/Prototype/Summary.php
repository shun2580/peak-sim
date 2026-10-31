<?php

declare(strict_types=1);

namespace PeakSim\Prototype;

/**
 * What one method does: the query events (relative to the method), the call path that led to them,
 * and the places the prototype could not follow. Computed once per method and reused.
 */
final class Summary
{
    /** @var list<Event> */
    public array $events = [];

    /** @var list<array{file: string, line: int, note: string}> */
    public array $trace = [];

    /** @var list<array{file: string, line: int, note: string}> */
    public array $gaps = [];

    public ?Type $returnType = null;

    /** @param list<string|null> $outerTables */
    public function absorb(self $callee, int $depth, array $outerTables): void
    {
        foreach ($callee->events as $event) {
            $this->events[] = $event->shifted($depth, $outerTables);
        }
        foreach ($callee->trace as $item) {
            $this->trace[] = $item;
        }
        foreach ($callee->gaps as $item) {
            $this->gaps[] = $item;
        }
    }

    public function gap(string $file, int $line, string $note): void
    {
        $this->gaps[] = ['file' => $file, 'line' => $line, 'note' => $note];
    }

    public function trace(string $file, int $line, string $note): void
    {
        $this->trace[] = ['file' => $file, 'line' => $line, 'note' => $note];
    }
}
