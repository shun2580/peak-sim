<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Explain;

use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Explain\ExplainSender;
use PHPUnit\Framework\TestCase;

final class ExplainSenderTest extends TestCase
{
    public function testItPutsExplainInFrontAndSendsOneStatementWithItsValues(): void
    {
        $transport = new RecordingTransport();
        $answer = (new ExplainSender($transport))->explain('select * from users where id = ?', [7]);

        $this->assertTrue($answer->isAvailable());
        $this->assertSame([['sql' => 'EXPLAIN FORMAT=JSON select * from users where id = ?', 'bindings' => [7]]], $transport->sent);
    }

    public function testADeleteRepresentativeIsExplainedNeverRun(): void
    {
        $transport = new RecordingTransport();
        (new ExplainSender($transport))->explain('delete from `references` where from_type = ? and from_id in (?)', ['Page', 3]);

        $this->assertCount(1, $transport->sent);
        $this->assertStringStartsWith('EXPLAIN FORMAT=JSON delete from', $transport->sent[0]['sql']);
    }

    public function testADeleteSentWithoutExplainIsRefusedBeforeTheTransportSeesIt(): void
    {
        $transport = new RecordingTransport();
        $sender = new ExplainSender($transport);
        try {
            $sender->sendRaw('delete from `references` where from_type = ?', ['Page']);
            $this->fail('a DELETE without EXPLAIN was let through');
        } catch (MeasureRefused $e) {
            $this->assertSame(10, MeasureRefused::EXIT_CODE);
        }
        $this->assertSame([], $transport->sent, 'nothing reached the server');
        $this->assertSame(0, $sender->sent());
    }

    public function testAnUpdateSentWithoutExplainIsRefusedBeforeTheTransportSeesIt(): void
    {
        $transport = new RecordingTransport();
        $this->expectException(MeasureRefused::class);
        try {
            (new ExplainSender($transport))->sendRaw('update `deletions` set deleted_by = ? where id = ?', [1, 2]);
        } finally {
            $this->assertSame([], $transport->sent);
        }
    }

    public function testARepresentativeWithASecondStatementIsRefused(): void
    {
        $transport = new RecordingTransport();
        $this->expectException(MeasureRefused::class);
        try {
            (new ExplainSender($transport))->explain('select 1; delete from users', []);
        } finally {
            $this->assertSame([], $transport->sent);
        }
    }

    public function testARepresentativeThatIsAnExplainItselfIsRefused(): void
    {
        $transport = new RecordingTransport();
        $this->expectException(MeasureRefused::class);
        try {
            (new ExplainSender($transport))->explain('EXPLAIN ANALYZE select 1', []);
        } finally {
            $this->assertSame([], $transport->sent);
        }
    }

    public function testTheCountOfSentStatementsCountsOnlyThoseTheGateLetThrough(): void
    {
        $transport = new RecordingTransport();
        $sender = new ExplainSender($transport);
        $sender->explain('select 1', []);
        try {
            $sender->sendRaw('delete from t', []);
        } catch (MeasureRefused) {
        }
        $sender->explain('select 2', []);

        $this->assertSame(2, $sender->sent());
    }

    public function testAServerRefusalIsAnExplainUnavailableWithItsReason(): void
    {
        $transport = new RecordingTransport(fails: new \RuntimeException("Unknown column 'x' in 'where clause'"));
        $answer = (new ExplainSender($transport))->explain('select * from users where x = ?', [1]);

        $this->assertFalse($answer->isAvailable());
        $this->assertStringStartsWith('server_refused: ', (string) $answer->unavailable);
        $this->assertStringContainsString('Unknown column', (string) $answer->unavailable);
    }

    public function testAValueCountThatDoesNotMatchThePlaceholdersIsExplainUnavailableAndNothingIsSent(): void
    {
        $transport = new RecordingTransport();
        $answer = (new ExplainSender($transport))->explain('select * from users where id in (?, ?, ?)', [1, 2]);

        $this->assertFalse($answer->isAvailable());
        $this->assertStringStartsWith('binding_count_mismatch', (string) $answer->unavailable);
        $this->assertSame([], $transport->sent);
    }

    public function testAQuestionMarkInsideATextIsNotAPlaceholder(): void
    {
        $this->assertSame(1, ExplainSender::placeholders("select * from t where a = 'why?' and b = ? /* ? */"));
    }

    public function testAnAnswerThatIsNotJsonIsExplainUnavailable(): void
    {
        $answer = (new ExplainSender(new RecordingTransport('not json')))->explain('select 1', []);

        $this->assertFalse($answer->isAvailable());
        $this->assertSame('plan_not_json', $answer->unavailable);
    }
}
