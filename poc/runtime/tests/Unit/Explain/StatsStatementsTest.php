<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Explain;

use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Explain\StatsDb;
use PeakSim\Runtime\Explain\StatsStatements;
use PHPUnit\Framework\TestCase;

final class StatsStatementsTest extends TestCase
{
    private static function db(): object
    {
        return new class() implements StatsDb {
            /** @var list<string> */
            public array $sent = [];

            public function run(string $sql): array
            {
                $this->sent[] = $sql;
                if (str_contains($sql, 'information_schema.TABLES')) {
                    return [['t' => 'users'], ['t' => 'orders'], ['t' => 'references'], ['t' => 'bad`name']];
                }

                return [];
            }
        };
    }

    public function testItListsOnlyPlainTableNamesTheDatabaseGave(): void
    {
        $db = self::db();

        $this->assertSame(['users', 'orders', 'references'], (new StatsStatements($db))->tables());
    }

    public function testRelevantKeepsOnlyTablesTheDatabaseHas(): void
    {
        $s = new StatsStatements(self::db());

        $this->assertSame(['orders', 'users'], $s->relevant(['users', 'ghost', 'orders', 'users', '1; drop table users']));
    }

    public function testEveryStatementItSendsIsOneOfTheFixedPatterns(): void
    {
        $db = self::db();
        $s = new StatsStatements($db);
        $tables = $s->relevant(['users', 'references']);
        $s->analyze($tables);
        $s->switchAutoRecalcOff($tables);
        $s->setSamplePages($tables, 4);
        $s->setSamplePages($tables, 200);
        $s->setSamplePages($tables, 20);
        $s->settings();

        $patterns = [
            '/^SELECT table_name AS t FROM information_schema\.TABLES WHERE table_schema = DATABASE\(\) AND table_type = \'BASE TABLE\' ORDER BY table_name$/',
            '/^ANALYZE TABLE `[A-Za-z0-9_]+`$/',
            '/^ALTER TABLE `[A-Za-z0-9_]+` STATS_AUTO_RECALC=0$/',
            '/^ALTER TABLE `[A-Za-z0-9_]+` STATS_SAMPLE_PAGES=(4|20|200)$/',
            '/^SELECT @@innodb_stats_persistent AS persistent, @@innodb_stats_persistent_sample_pages AS sample_pages, @@innodb_stats_auto_recalc AS auto_recalc, @@version AS version$/',
        ];
        $this->assertNotEmpty($db->sent);
        foreach ($db->sent as $sql) {
            $matched = array_filter($patterns, static fn (string $p): bool => preg_match($p, $sql) === 1);
            $this->assertNotEmpty($matched, 'not a fixed statement: ' . $sql);
        }
        $this->assertContains('ALTER TABLE `references` STATS_SAMPLE_PAGES=4', $db->sent);
        $this->assertContains('ANALYZE TABLE `users`', $db->sent);
    }

    public function testATextThatIsNotATableOfTheDatabaseIsRefusedAndNothingIsSent(): void
    {
        $db = self::db();
        $s = new StatsStatements($db);
        $s->tables();
        $before = count($db->sent);
        try {
            $s->analyze(['users; DROP DATABASE x']);
            $this->fail('a free text was let through');
        } catch (MeasureRefused) {
        }
        $this->assertCount($before, $db->sent);
    }

    public function testATableOfTheDatabaseWithAnOddNameIsRefused(): void
    {
        $s = new StatsStatements(self::db());
        $s->tables();
        $this->expectException(MeasureRefused::class);
        $s->switchAutoRecalcOff(['bad`name']);
    }

    public function testASamplePageCountThatIsNotOneOfTheStatesIsRefused(): void
    {
        $db = self::db();
        $s = new StatsStatements($db);
        $before = count($db->sent);
        try {
            $s->setSamplePages(['users'], 7);
            $this->fail('a free page count was let through');
        } catch (MeasureRefused) {
        }
        $this->assertSame($before, count($db->sent));
    }

    public function testTheStatesAreTheDefaultTheLowAndTheHighCounts(): void
    {
        $this->assertSame(['default' => 20, 'low' => 4, 'high' => 200], StatsStatements::SAMPLE_PAGES);
    }

    public function testThereIsNoPublicMethodThatTakesAStatement(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(StatsStatements::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        sort($methods);

        $this->assertSame(['__construct', 'analyze', 'relevant', 'setSamplePages', 'settings', 'switchAutoRecalcOff', 'tables'], $methods);
        foreach ((new \ReflectionClass(StatsStatements::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            foreach ($m->getParameters() as $p) {
                $this->assertNotContains($p->getName(), ['sql', 'statement', 'query'], $m->getName() . ' takes a statement');
            }
        }
    }
}
