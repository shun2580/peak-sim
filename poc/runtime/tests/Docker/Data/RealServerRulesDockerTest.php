<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker\Data;

use PDO;
use PeakSim\Runtime\Data\Generator;
use PeakSim\Runtime\Data\Reason;
use PeakSim\Runtime\Data\ScalePlan;
use PeakSim\Runtime\Data\TableOutcome;
use PeakSim\Runtime\Tests\Docker\Data\Support\GenerationCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The value rules and the failure reasons against the real server, with hand-made tables (no app needed): every
 * column type the generator knows, a CHECK constraint, foreign key cycles, a pivot. Test code may issue DDL in the
 * measurement database it made; the tool itself never does.
 */
#[Group('in-stack')]
final class RealServerRulesDockerTest extends GenerationCase
{
    private const SCALE = 100;

    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $config = self::config();
        $name = $config->dbNameForScale(self::SCALE);
        $admin = self::db();
        $admin->drop($name);
        $admin->create($name);
        self::$pdo = self::pdo(self::SCALE);
        foreach (self::ddl() as $sql) {
            self::$pdo->exec($sql);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::drop(self::SCALE);
    }

    /** @return list<string> */
    private static function ddl(): array
    {
        return [
            'CREATE TABLE kitchen_sink (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                c_tiny TINYINT NOT NULL, c_tinyu TINYINT UNSIGNED NOT NULL, c_small SMALLINT NOT NULL,
                c_medium MEDIUMINT NOT NULL, c_int INT NOT NULL, c_intu INT UNSIGNED NOT NULL, c_big BIGINT NOT NULL,
                c_dec DECIMAL(5,2) NOT NULL, c_dec0 DECIMAL(6,0) NOT NULL, c_frac DECIMAL(2,2) NOT NULL,
                c_float FLOAT NOT NULL, c_double DOUBLE NOT NULL,
                c_date DATE NOT NULL, c_dt DATETIME NOT NULL, c_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, c_time TIME NOT NULL, c_year YEAR NOT NULL,
                c_char CHAR(5) NOT NULL, c_varchar VARCHAR(10) NOT NULL, c_tinytext TINYTEXT NOT NULL, c_text TEXT NOT NULL, c_longtext LONGTEXT NOT NULL,
                c_enum ENUM(\'on\',\'deleted\',\'banned\',\'pending\') NOT NULL, c_set SET(\'a\',\'b\') NOT NULL, c_json JSON NOT NULL,
                c_blob BLOB NOT NULL, c_binary BINARY(4) NOT NULL, c_varbinary VARBINARY(8) NOT NULL,
                c_bool BOOLEAN NOT NULL, is_admin TINYINT(1) NOT NULL, c_uuid CHAR(36) NOT NULL,
                email VARCHAR(255) NOT NULL, created_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL,
                c_note VARCHAR(20) NULL, c_defaulted INT NOT NULL DEFAULT 7,
                c_virtual INT AS (c_int + 1) VIRTUAL,
                UNIQUE KEY u_varchar (c_varchar), UNIQUE KEY u_dt (c_dt), UNIQUE KEY u_dec (c_dec)
            )',
            'CREATE TABLE checked (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, qty INT NOT NULL, CONSTRAINT qty_negative CHECK (qty < 0))',
            'CREATE TABLE geo (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, place POINT NOT NULL)',
            'CREATE TABLE geo_visits (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, geo_id INT NOT NULL, FOREIGN KEY (geo_id) REFERENCES geo(id))',
            'CREATE TABLE tight (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, code VARCHAR(1) NOT NULL, UNIQUE KEY u_code (code))',
            'CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, team_id INT UNSIGNED NOT NULL)',
            'CREATE TABLE teams (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, owner_id INT UNSIGNED NULL)',
            'ALTER TABLE users ADD FOREIGN KEY (team_id) REFERENCES teams(id)',
            'ALTER TABLE teams ADD FOREIGN KEY (owner_id) REFERENCES users(id)',
            'CREATE TABLE a (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, b_id INT NULL)',
            'CREATE TABLE b (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, a_id INT NOT NULL, FOREIGN KEY (a_id) REFERENCES a(id))',
            'CREATE TABLE tree (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, parent_id INT NULL, FOREIGN KEY (parent_id) REFERENCES tree(id))',
            'CREATE TABLE chain (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, next_id INT NULL, UNIQUE KEY u_next (next_id), FOREIGN KEY (next_id) REFERENCES chain(id))',
            'CREATE TABLE songs_for_likes (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY)',
            'CREATE TABLE likes (user_id INT UNSIGNED NOT NULL, song_id INT UNSIGNED NOT NULL, PRIMARY KEY (user_id, song_id),
                FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (song_id) REFERENCES songs_for_likes(id))',
        ];
    }

    private function runAll(array $profile = []): array
    {
        // fresh rows each time: empty every table the generator writes (test code may do DDL here)
        $pdo = self::$pdo;
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        return [$pdo, (new Generator())->generate($pdo, ScalePlan::global(40, 5), $profile)];
    }

    public function testEveryKnownColumnTypeIsAcceptedByTheServer(): void
    {
        [$pdo, $report] = $this->runAll();

        $outcome = $report->outcome('kitchen_sink');
        self::assertSame(TableOutcome::GENERATED, $outcome->status, json_encode($outcome->toArray()));
        self::assertSame(40, self::rowCount($pdo, 'kitchen_sink'));
        $row = $pdo->query('SELECT * FROM kitchen_sink WHERE id = 3')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('2.50', $row['c_dec'], 'a unique decimal counts rows, it does not wrap at 100');
        self::assertSame(1, (int) $row['c_bool']);
        self::assertSame(0, (int) $row['is_admin'], 'privilege flags stay 0');
        self::assertSame(['on', 'pending'], array_map('strval', $pdo->query('SELECT DISTINCT c_enum FROM kitchen_sink ORDER BY c_enum')->fetchAll(PDO::FETCH_COLUMN)));
        self::assertNull($row['deleted_at']);
        self::assertSame('2026-01-01 00:00:00', $row['created_at']);
        self::assertSame('{}', (string) $row['c_json']);
        self::assertSame(7, (int) $row['c_defaulted'], 'a column with a database default is left to the database');
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-0000-4000-8000-[0-9a-f]{12}$/', $row['c_uuid']);
        self::assertSame(40, (int) $pdo->query('SELECT COUNT(DISTINCT c_varchar) FROM kitchen_sink')->fetchColumn(), 'unique varchar(10) stays unique');
        self::assertSame(40, (int) $pdo->query('SELECT COUNT(DISTINCT c_dt) FROM kitchen_sink')->fetchColumn());
        self::assertSame(40, (int) $pdo->query('SELECT COUNT(DISTINCT c_dec) FROM kitchen_sink')->fetchColumn());
        self::assertSame((int) $row['c_int'] + 1, (int) $row['c_virtual'], 'the generated column is computed by the server');
    }

    public function testACheckConstraintFailureIsClassifiedAndRolledBack(): void
    {
        [$pdo, $report] = $this->runAll();

        self::assertSame(Reason::CHECK_CONSTRAINT, $report->outcome('checked')->reason);
        self::assertSame(0, self::rowCount($pdo, 'checked'));
        self::assertStringContainsString('qty_negative', (string) $report->outcome('checked')->detail);
    }

    public function testAnUnsupportedRequiredColumnAndItsChildFail(): void
    {
        [, $report] = $this->runAll();

        self::assertSame(Reason::UNSUPPORTED_TYPE, $report->outcome('geo')->reason);
        self::assertSame(Reason::PARENT_NOT_GENERATED, $report->outcome('geo_visits')->reason);
    }

    public function testAUniqueColumnTooShortForTheRowsFailsAsAUniqueViolation(): void
    {
        [$pdo, $report] = $this->runAll();

        // VARCHAR(1) holds the last digit of the row number: 40 rows cannot all be different
        self::assertSame(Reason::UNIQUE_VIOLATION, $report->outcome('tight')->reason);
        self::assertSame(0, self::rowCount($pdo, 'tight'));
    }

    public function testAMutualCycleIsBrokenAtTheNullableKeyAndFilledInAfterwards(): void
    {
        [$pdo, $report] = $this->runAll();

        self::assertSame(TableOutcome::GENERATED, $report->outcome('teams')->status, json_encode($report->outcome('teams')->toArray()));
        self::assertSame(TableOutcome::GENERATED, $report->outcome('users')->status);
        self::assertSame(40, (int) $pdo->query('SELECT COUNT(owner_id) FROM teams')->fetchColumn(), 'every team got an owner');
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM teams t LEFT JOIN users u ON u.id = t.owner_id WHERE u.id IS NULL')->fetchColumn());
    }

    public function testACycleOfNotNullKeysIsAnFkCycle(): void
    {
        // a.b_id is nullable, so a <-> b is breakable; make the cycle unbreakable and look again
        self::$pdo->exec('ALTER TABLE a MODIFY b_id INT NOT NULL');
        self::$pdo->exec('ALTER TABLE a ADD FOREIGN KEY (b_id) REFERENCES b(id)');
        [, $report] = $this->runAll();

        self::assertSame(Reason::FK_CYCLE, $report->outcome('a')->reason);
        self::assertSame(Reason::FK_CYCLE, $report->outcome('b')->reason);
    }

    public function testSelfReferencesAreFilledInsideTheInsert(): void
    {
        [$pdo, $report] = $this->runAll();

        self::assertSame(TableOutcome::GENERATED, $report->outcome('tree')->status);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM tree WHERE parent_id IS NULL')->fetchColumn(), 'one root');
        self::assertSame(2, (int) $pdo->query('SELECT parent_id FROM tree WHERE id = 5')->fetchColumn());
        self::assertSame(TableOutcome::GENERATED, $report->outcome('chain')->status);
        self::assertSame(39, (int) $pdo->query('SELECT COUNT(DISTINCT next_id) FROM chain')->fetchColumn());
    }

    public function testAPivotWithACompositeKeyGetsDistinctPairs(): void
    {
        [$pdo, $report] = $this->runAll();

        self::assertSame(TableOutcome::GENERATED, $report->outcome('likes')->status, json_encode($report->outcome('likes')->toArray()));
        self::assertSame(40, self::rowCount($pdo, 'likes'));
    }
}
