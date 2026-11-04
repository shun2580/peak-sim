<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Run;

use PeakSim\Runtime\Run\Attempt;
use PeakSim\Runtime\Run\Classification;
use PHPUnit\Framework\TestCase;

final class AttemptTest extends TestCase
{
    private function make(?string $detail = null): Attempt
    {
        return new Attempt(
            route: 'ok/eager',
            method: 'GET',
            scale: 100,
            axis: 'N',
            attempt: Attempt::COLD,
            status: 200,
            queries: 2,
            byConnection: ['mysql' => 2],
            tables: ['customers', 'orders'],
            bytes: 1234,
            peakMem: 4096,
            gateBypass: false,
            class: Classification::OK,
            detail: $detail,
        );
    }

    public function testToArrayHasExactlyTheContractKeysInOrder(): void
    {
        self::assertSame(
            ['route', 'method', 'scale', 'axis', 'attempt', 'status', 'queries', 'by_connection', 'tables', 'bytes', 'peak_mem', 'gate_bypass', 'class', 'detail', 'rows_examined', 'rows_examined_baseline'],
            array_keys($this->make()->toArray()),
        );
    }

    public function testJsonIsOneLine(): void
    {
        $json = $this->make("line1\nline2")->toJson();
        self::assertStringNotContainsString("\n", $json);
        self::assertSame('ok/eager', json_decode($json, true, 512, JSON_THROW_ON_ERROR)['route']);
    }

    public function testRoundTripThroughJson(): void
    {
        $attempt = $this->make('hello');
        $back = Attempt::fromArray(json_decode($attempt->toJson(), true, 512, JSON_THROW_ON_ERROR));
        self::assertEquals($attempt, $back);
    }

    public function testRowsExaminedGoThroughJsonAndNullStaysNull(): void
    {
        $measured = new Attempt('r', 'GET', 10, 'N', Attempt::COLD, 200, 1, [], [], 1, 1, false, Classification::OK, null, rowsExamined: 120, rowsExaminedBaseline: 4);
        $back = Attempt::fromArray(json_decode($measured->toJson(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(120, $back->rowsExamined);
        self::assertSame(4, $back->rowsExaminedBaseline);

        $unknown = $this->make()->toArray();
        self::assertArrayHasKey('rows_examined', $unknown);
        self::assertNull($unknown['rows_examined'], 'not measured is null, never 0');
        self::assertNull($unknown['rows_examined_baseline']);
        $back = Attempt::fromArray(json_decode($this->make()->toJson(), true, 512, JSON_THROW_ON_ERROR));
        self::assertNull($back->rowsExamined);
        self::assertNull($back->rowsExaminedBaseline);
    }

    public function testDetailIsCutAt200Characters(): void
    {
        self::assertSame(200, mb_strlen((string) $this->make(str_repeat('あ', 500))->detail));
    }

    public function testAttemptNumbersAreFixed(): void
    {
        self::assertSame([1, 2, 3], [Attempt::WARMUP, Attempt::COLD, Attempt::WARM]);
    }

    public function testNullCountsStayNullForAFailedAttempt(): void
    {
        $attempt = new Attempt('r', 'POST', 10, 'N', Attempt::WARMUP, 419, null, [], [], null, null, false, Classification::CSRF_419, null);
        $array = $attempt->toArray();
        self::assertNull($array['queries']);
        self::assertSame([], $array['by_connection']);
    }
}
