<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabEntry;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guards of the lab's entry script (poc/lab/app/entry.php) are called from it, for every request, in the right
 * order, and the entry puts no transaction around a request. The checker reads the code (not the comments); each
 * "mutation" below takes one call out or turns it off in a copy of the entry and must be caught, so that a pass of the
 * real file means something. Behaviour of the guards themselves is in EntryGuardTest and ViewsOnlyWritesTest.
 */
final class EntryWiringTest extends TestCase
{
    private const ENTRY = __DIR__ . '/../../../../lab/app/entry.php';

    private const NGINX = __DIR__ . '/../../../../lab/compose/nginx/lab.conf';

    /** In this order, each exactly once. */
    private const CALLS = [
        'method check' => 'EntryGuard::methodRefusal(',
        'latch check' => 'EntryGuard::latchRefusal(',
        'app start' => 'new AppBooter(',
        'guard made' => 'new EntryGuard(',
        'statement watch' => '$guard->watch(',
        'the request is handled' => '$booted->kernel->handle(',
        'verdict' => '$guard->finish(',
    ];

    /** The entry must not open, end or count a transaction: the request is not wrapped. */
    private const NO_WRAPPER = ['beginTransaction(', 'rollBack(', '->transaction(', 'transactionLevel(', 'commit('];

    /**
     * @return list<string> what is wrong with this entry code (empty when it is as it must be)
     */
    public static function problems(string $code): array
    {
        $problems = [];
        $last = -1;
        foreach (self::CALLS as $name => $needle) {
            $count = substr_count($code, $needle);
            if ($count !== 1) {
                $problems[] = sprintf('%s: "%s" is called %d times, not once', $name, $needle, $count);
                continue;
            }
            $at = (int) strpos($code, $needle);
            if ($at < $last) {
                $problems[] = sprintf('%s: "%s" is out of order', $name, $needle);
            }
            $last = max($last, $at);
        }
        if (preg_match('/\$methodRefusal = EntryGuard::methodRefusal\(\(string\) \(\$_SERVER\[\'REQUEST_METHOD\'\] \?\? \'\'\)\); if \(\$methodRefusal !== null\) \{ \$refuse\(405, \$methodRefusal\); \}/', $code) !== 1) {
            $problems[] = 'method check: the refusal is not made a 405 on the method of the request';
        }
        if (preg_match('/\$latch = \$scratchPath \. \'\/contaminated\.flag\'; \$latchReason = EntryGuard::latchRefusal\(\$latch\); if \(\$latchReason !== null\) \{ \$refuse\(503, /', $code) !== 1) {
            $problems[] = 'latch check: a latch is not made a 503';
        }
        if (preg_match('/\$guard = new EntryGuard\(\$latch, \$booted->dbName, /', $code) !== 1) {
            $problems[] = 'guard made: it is not given the latch of the check above and the measurement database';
        }
        if (preg_match('/\$guard->watch\(\$db, \$app\[\'events\'\]\);/', $code) !== 1) {
            $problems[] = 'statement watch: it is not put on the app\'s database and events';
        }
        if (preg_match('/\$booted->kernel->terminate\(\$request, \$response\); \$counter->stop\(\); (?:\/\/[^$]*)?\$guard->finish\(\$db, \$counter\);/', $code) !== 1
            && preg_match('/\$counter->stop\(\); \$guard->finish\(\$db, \$counter\);/', $code) !== 1) {
            $problems[] = 'verdict: it does not follow the end of the request';
        }
        foreach (self::NO_WRAPPER as $needle) {
            if (str_contains($code, $needle)) {
                $problems[] = sprintf('wrapper: the entry contains "%s"', $needle);
            }
        }

        return $problems;
    }

    public function testTheRealEntryIsAsItMustBe(): void
    {
        self::assertSame([], self::problems(CodeOnly::file(self::ENTRY)));
    }

    /** @return iterable<string, array{string, string, string, string}> search, replacement (in the real source), and a second search and replacement ('' for none) */
    public static function mutations(): iterable
    {
        $watch = '$guard->watch($db, $app[\'events\']);';
        $finish = '$guard->finish($db, $counter);';
        yield 'method check turned off' => ['if ($methodRefusal !== null) {', 'if (false) {', '', ''];
        yield 'method call removed' => ["\$methodRefusal = EntryGuard::methodRefusal((string) (\$_SERVER['REQUEST_METHOD'] ?? ''));", '$methodRefusal = null;', '', ''];
        yield 'method refusal made another status' => ['$refuse(405, $methodRefusal);', '$refuse(200, $methodRefusal);', '', ''];
        yield 'latch check turned off' => ['if ($latchReason !== null) {', 'if (false) {', '', ''];
        yield 'latch call removed' => ['$latchReason = EntryGuard::latchRefusal($latch);', '$latchReason = null;', '', ''];
        yield 'latch refusal made another status' => ["\$refuse(503, 'the database can no longer be trusted: '", "\$refuse(200, 'the database can no longer be trusted: '", '', ''];
        yield 'guard not made' => ['$guard = new EntryGuard($latch, $booted->dbName, (string) $app[\'config\']->get(\'database.default\'), (string) ($_SERVER[\'REQUEST_URI\'] ?? \'\'));', '$guard = null;', '', ''];
        yield 'guard made with another latch' => ['$guard = new EntryGuard($latch, ', '$guard = new EntryGuard($scratchPath . \'/other.flag\', ', '', ''];
        yield 'statement watch removed' => [$watch, '', '', ''];
        yield 'statement watch commented out' => [$watch, '// ' . $watch, '', ''];
        yield 'statement watch put on nothing' => [$watch, '$guard->watch(new \stdClass(), new \stdClass());', '', ''];
        yield 'statement watch moved after the request' => [$watch, '', $finish, $watch . ' ' . $finish];
        yield 'verdict removed' => [$finish, '', '', ''];
        yield 'verdict taken before the request' => [$finish, '', '$request = \\Illuminate\\Http\\Request::capture();', $finish . ' $request = \\Illuminate\\Http\\Request::capture();'];
        yield 'wrapper put back' => ['$counter->start();', '$db->beginTransaction(); $counter->start();', '', ''];
        yield 'rollback put back' => ['$counter->stop();', '$counter->stop(); $db->rollBack();', '', ''];
    }

    #[DataProvider('mutations')]
    public function testEveryMutationIsCaught(string $search, string $replace, string $search2, string $replace2): void
    {
        $source = (string) file_get_contents(self::ENTRY);
        self::assertSame(1, substr_count($source, $search), 'the mutation does not apply to the entry: ' . $search);
        $mutated = str_replace($search, $replace, $source);
        if ($search2 !== '') {
            self::assertSame(1, substr_count($mutated, $search2), 'the second step of the mutation does not apply: ' . $search2);
            $mutated = str_replace($search2, $replace2, $mutated);
        }
        self::assertNotSame($source, $mutated);

        self::assertNotSame([], self::problems(CodeOnly::of($mutated)), 'not caught: ' . $search . ' -> ' . $replace);
    }

    public function testEveryRequestGoesToTheEntry(): void
    {
        $conf = CodeOnly::of((string) preg_replace('/#[^\n]*/', '', (string) file_get_contents(self::NGINX)));
        self::assertSame(1, substr_count($conf, 'fastcgi_param SCRIPT_FILENAME '), 'the web server names more than one script');
        self::assertStringContainsString('fastcgi_param SCRIPT_FILENAME /peak-sim/poc/lab/app/entry.php;', $conf);
        self::assertSame(1, substr_count($conf, 'fastcgi_pass '), 'the web server has more than one way to the app');
        self::assertSame(1, substr_count($conf, 'location / {'));
    }
}
