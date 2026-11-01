<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker\Data;

use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Data\Generator;
use PeakSim\Runtime\Data\Reason;
use PeakSim\Runtime\Data\ScalePlan;
use PeakSim\Runtime\Tests\Docker\Data\Support\GenerationCase;
use PHPUnit\Framework\Attributes\Group;
use PDO;

/**
 * W3 acceptance on the verification app (five tables: customers, products, orders, items, product_stats; run with
 * the verification app at /app): generate into a freshly migrated measurement database and compare the real row
 * counts with the plan.
 */
#[Group('in-stack')]
final class FixturesGenerationDockerTest extends GenerationCase
{
    private const SCALE = 100;
    private const OTHER = 1000;

    public static function tearDownAfterClass(): void
    {
        self::drop(self::SCALE);
        self::drop(self::OTHER);
    }

    public function testTargetedPlanGivesExactlyThePlannedRows(): void
    {
        self::fresh(self::SCALE);
        $pdo = self::pdo(self::SCALE);
        $plan = ScalePlan::targeted(['orders'], 'items', 'orders', 20, 3, 5);

        $report = (new Generator())->generate($pdo, $plan, []);

        self::assertSame(1.0, $report->successRate(), json_encode($report->toArray()));
        self::assertSame(5, $report->generatedCount());
        $expected = ['customers' => 5, 'products' => 5, 'orders' => 20, 'items' => 60, 'product_stats' => 5];
        foreach ($expected as $table => $rows) {
            self::assertSame($rows, self::rowCount($pdo, $table), $table);
            self::assertSame($rows, $report->outcome($table)->actual, $table);
        }
        // every order has exactly M = 3 items; every item points at an existing order and product
        self::assertSame(20, (int) self::scalar($pdo, 'SELECT COUNT(DISTINCT order_id) FROM items'));
        self::assertSame(3, (int) self::scalar($pdo, 'SELECT MIN(c) FROM (SELECT COUNT(*) AS c FROM items GROUP BY order_id) t'));
        self::assertSame(3, (int) self::scalar($pdo, 'SELECT MAX(c) FROM (SELECT COUNT(*) AS c FROM items GROUP BY order_id) t'));
        self::assertSame(0, (int) self::scalar($pdo, 'SELECT COUNT(*) FROM items i LEFT JOIN orders o ON o.id = i.order_id WHERE o.id IS NULL'));
    }

    public function testGlobalPlanScalesEveryTable(): void
    {
        self::fresh(self::SCALE);
        $pdo = self::pdo(self::SCALE);

        $report = (new Generator())->generate($pdo, ScalePlan::global(50, 5), []);

        self::assertSame(1.0, $report->successRate(), json_encode($report->toArray()));
        foreach (['customers', 'products', 'orders', 'items', 'product_stats'] as $table) {
            self::assertSame(50, self::rowCount($pdo, $table), $table);
        }
        // product_stats.product_id is unique: 50 different products
        self::assertSame(50, (int) self::scalar($pdo, 'SELECT COUNT(DISTINCT product_id) FROM product_stats'));
    }

    public function testAOneToOneUniqueKeyMakesRoomInItsParent(): void
    {
        self::fresh(self::SCALE);
        $pdo = self::pdo(self::SCALE);

        $report = (new Generator())->generate($pdo, ScalePlan::targeted(['product_stats'], null, null, 30, 0, 5), []);

        self::assertSame(1.0, $report->successRate(), json_encode($report->toArray()));
        self::assertSame(30, self::rowCount($pdo, 'product_stats'));
        self::assertSame(30, self::rowCount($pdo, 'products'), 'the parent is raised to the size of the unique child');
        self::assertSame(5, self::rowCount($pdo, 'customers'));
    }

    public function testNZeroLeavesTheNTableAndWhatNeedsItEmpty(): void
    {
        self::fresh(self::SCALE);
        $pdo = self::pdo(self::SCALE);

        $report = (new Generator())->generate($pdo, ScalePlan::targeted(['orders'], null, null, 0, 0, 5), []);

        self::assertSame(1.0, $report->successRate(), json_encode($report->toArray()));
        self::assertSame(0, self::rowCount($pdo, 'orders'));
        self::assertSame(0, self::rowCount($pdo, 'items'));
        self::assertSame(5, self::rowCount($pdo, 'customers'));
        self::assertSame(5, self::rowCount($pdo, 'products'));
    }

    public function testValuesFollowTheColumnRulesInTheRealDatabase(): void
    {
        self::fresh(self::SCALE);
        $pdo = self::pdo(self::SCALE);
        (new Generator())->generate($pdo, ScalePlan::global(12, 5), []);

        self::assertSame('customers_name_1', self::scalar($pdo, 'SELECT name FROM customers WHERE id = 1'));
        self::assertMatchesRegularExpression('/^customers\d+@example\.test$/', (string) self::scalar($pdo, 'SELECT email FROM customers WHERE id = 3'));
        self::assertSame('1.50', (string) self::scalar($pdo, 'SELECT total FROM orders WHERE id = 1'));
        self::assertSame('2026-01-01 00:00:00', (string) self::scalar($pdo, 'SELECT created_at FROM orders WHERE id = 1'));
        // the column with a database default is left to the database
        self::assertSame(0, (int) self::scalar($pdo, 'SELECT MAX(units_sold) FROM product_stats'));
        self::assertSame(12, (int) self::scalar($pdo, 'SELECT COUNT(DISTINCT email) FROM customers'));
    }

    public function testTheSameInputGivesTheSameRows(): void
    {
        $sums = [];
        foreach ([1, 2] as $_) {
            self::fresh(self::SCALE);
            $pdo = self::pdo(self::SCALE);
            (new Generator())->generate($pdo, ScalePlan::targeted(['orders'], 'items', 'orders', 20, 3, 5), []);
            $sums[] = array_map(
                static fn (string $t): string => (string) $pdo->query("CHECKSUM TABLE `$t`")->fetch(PDO::FETCH_ASSOC)['Checksum'],
                ['customers', 'products', 'orders', 'items', 'product_stats'],
            );
        }

        self::assertSame($sums[0], $sums[1]);
    }

    public function testASecondScaleHasMoreRowsInTheSameShape(): void
    {
        self::fresh(self::OTHER);
        $pdo = self::pdo(self::OTHER);

        (new Generator())->generate($pdo, ScalePlan::targeted(['orders'], 'items', 'orders', 200, 3, 5), []);

        self::assertSame(200, self::rowCount($pdo, 'orders'));
        self::assertSame(600, self::rowCount($pdo, 'items'));
        self::assertSame(5, self::rowCount($pdo, 'customers'));
    }

    public function testItRefusesAConnectionThatHasNoMeasurementDatabaseSelected(): void
    {
        $config = self::config();
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config->dbHost, $config->dbPort), $config->dbUser, $config->dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->expectException(MeasureRefused::class);
        (new Generator())->generate($pdo, ScalePlan::global(10, 5), []);
    }

    public function testASkippedTableIsLeftEmptyAndItsChildrenAreStillGenerated(): void
    {
        self::fresh(self::SCALE);
        $pdo = self::pdo(self::SCALE);

        $report = (new Generator())->generate($pdo, ScalePlan::global(10, 5), ['skip' => ['product_stats']]);

        self::assertSame(0, self::rowCount($pdo, 'product_stats'));
        self::assertSame(Reason::PROFILE_SKIP, $report->outcome('product_stats')->reason);
        self::assertSame(10, self::rowCount($pdo, 'items'));
    }

    public function testTheModelFactoryCreatesRowsWhenFakerIsInstalled(): void
    {
        self::fresh(self::SCALE);

        [$exit, $out, $err] = self::php(__DIR__ . '/Support/factory.php', [(string) self::SCALE, 'App\\Models\\Customer', '3']);
        self::assertSame(0, $exit, $err);
        $result = json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);

        self::assertNull($result['reason'], $out);
        self::assertSame(3, $result['created']);
        self::assertSame(3, $result['rows']);
    }

    public function testAReportOfTheFixturesIsWrittenForTheRecord(): void
    {
        self::fresh(self::SCALE);
        $pdo = self::pdo(self::SCALE);
        $report = (new Generator())->generate($pdo, ScalePlan::global(100, 5), []);

        self::writeOut('generation-fixtures.json', ['app' => 'fixtures-laravel-app', 'scale' => self::SCALE] + $report->toArray());
        self::assertSame(1.0, $report->successRate());
    }
}
