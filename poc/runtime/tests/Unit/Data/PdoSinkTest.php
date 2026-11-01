<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Data;

use PeakSim\Runtime\Data\PdoSink;
use PeakSim\Runtime\Data\Reason;
use PeakSim\Runtime\Data\Sql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The parts of the PDO sink that need no server: error sorting and identifier quoting. */
final class PdoSinkTest extends TestCase
{
    #[DataProvider('mysqlCodes')]
    public function testMysqlErrorsAreSortedIntoReasons(int $code, string $reason): void
    {
        $this->assertSame($reason, PdoSink::reasonForMysqlCode($code));
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function mysqlCodes(): array
    {
        return [
            'check constraint' => [3819, Reason::CHECK_CONSTRAINT],
            'duplicate entry' => [1062, Reason::UNIQUE_VIOLATION],
            'foreign key child' => [1452, Reason::FK_VIOLATION],
            'null in not null' => [1048, Reason::NOT_NULL_VIOLATION],
            'no default' => [1364, Reason::NO_DEFAULT],
            'data too long' => [1406, Reason::DATA_TOO_LONG],
            'out of range' => [1264, Reason::OUT_OF_RANGE],
            'anything else' => [9999, Reason::INSERT_ERROR],
            'no code' => [0, Reason::INSERT_ERROR],
        ];
    }

    public function testIdentifiersAreQuotedAndBackticksDoubled(): void
    {
        $this->assertSame('`orders`', Sql::identifier('orders'));
        $this->assertSame('`we``ird`', Sql::identifier('we`ird'));
    }
}
