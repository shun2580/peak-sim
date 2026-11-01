<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

final class TableOutcome
{
    public const GENERATED = 'generated';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    /** Detail text is cut to this many characters (nothing long goes into a log). */
    public const DETAIL_LIMIT = 200;

    /** @param list<string> $notes things that were done differently on purpose (for example a foreign key left NULL) */
    public function __construct(
        public readonly string $table,
        public readonly string $status,
        public readonly int $planned,
        public readonly int $actual,
        public readonly ?string $reason = null,
        public readonly ?string $detail = null,
        public readonly array $notes = [],
    ) {
    }

    public static function cut(string $text): string
    {
        return substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, self::DETAIL_LIMIT);
    }

    /** @return array{table: string, status: string, planned: int, actual: int, reason: ?string, detail: ?string, notes: list<string>} */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'status' => $this->status,
            'planned' => $this->planned,
            'actual' => $this->actual,
            'reason' => $this->reason,
            'detail' => $this->detail,
            'notes' => $this->notes,
        ];
    }
}
