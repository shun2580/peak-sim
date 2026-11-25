<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabEntry;

use PeakSim\Runtime\Run\Requester;
use PHPUnit\Framework\TestCase;

/**
 * The Host of a request in the Bagisto lab is the host of APP_URL, as it was for every request of the capture. The app checks, after each
 * administrator's response, that APP_URL matches the address the request arrived on; with the web server's own name as the Host that check fails
 * in the lab and not in the capture, and the app then tries to write a notification the lab was never meant to see written. This reads the
 * settings and the code that carry the Host (nothing is started) and fixes the three places that must agree, and that the BookStack lab names none.
 */
final class RequestHostTest extends TestCase
{
    private const LAB = __DIR__ . '/../../../../lab';

    private const BOOTER = __DIR__ . '/../../../src/Boot/AppBooter.php';

    private static function read(string $path): string
    {
        $code = file_get_contents($path);
        self::assertIsString($code, $path);

        return $code;
    }

    /** The host of APP_URL, as the app is given it (the one place that decides it). */
    private static function appUrlHost(): string
    {
        self::assertSame(1, preg_match("/'APP_URL' => '([^']+)'/", self::read(self::BOOTER), $m), 'APP_URL is not set in one place');
        $host = parse_url($m[1], PHP_URL_HOST);
        self::assertIsString($host);

        return $host;
    }

    public function testTheCaptureSendsEveryRequestToTheHostOfAppUrl(): void
    {
        self::assertSame(self::appUrlHost(), parse_url(Requester::BASE_URL, PHP_URL_HOST));
    }

    public function testTheBagistoLabNamesThatHost(): void
    {
        $wrapper = self::read(self::LAB . '/bin/with-bagisto');
        self::assertSame(1, preg_match('/^export PEAK_LAB_REQUEST_HOST="\$\{PEAK_LAB_REQUEST_HOST-([^}]*)\}"$/m', $wrapper, $m), 'with-bagisto does not set the Host');
        self::assertSame(self::appUrlHost(), $m[1]);
    }

    public function testTheBookStackLabNamesNone(): void
    {
        foreach (['/bin/_lab.sh', '/compose/lab.compose.yaml'] as $file) {
            self::assertStringNotContainsString('PEAK_LAB_REQUEST_HOST', self::read(self::LAB . $file), $file . ' sets a Host for every lab');
        }
        $targets = self::read(self::LAB . '/bin/inner/targets.php');
        // no value: the headers are what they always were (an empty object), and nothing is added to a route
        self::assertStringContainsString("'headers' => \$requestHost === '' ? new stdClass() : ['Host' => \$requestHost],", $targets);
    }

    public function testTargetsGetsTheHostAndRefusesOneThatIsNotAHostName(): void
    {
        self::assertStringContainsString('-e PEAK_LAB_REQUEST_HOST="${PEAK_LAB_REQUEST_HOST:-}"', self::read(self::LAB . '/bin/targets'));
        $targets = self::read(self::LAB . '/bin/inner/targets.php');
        self::assertSame(1, substr_count($targets, "getenv('PEAK_LAB_REQUEST_HOST')"));
        self::assertSame(1, preg_match("~if \(\\\$requestHost !== '' && preg_match\('(/[^']+/D)', \\\$requestHost\) !== 1\) \{~", $targets, $m), 'the host is not checked');
        foreach (['measure.invalid', 'lab-web', 'a.b-c.example'] as $good) {
            self::assertSame(1, preg_match($m[1], $good), $good);
        }
        foreach (['Measure.invalid', 'a b', 'a:8080', 'a/b', "a\r\nX: y", '-a', 'a-', '', 'a_b', str_repeat('a', 254)] as $bad) {
            self::assertSame(0, preg_match($m[1], $bad), 'let through: ' . $bad);
        }
    }

    public function testTheEntryCheckJudgesTheEntryAndRecordsTheNumbers(): void
    {
        $check = self::read(self::LAB . '/bin/inner/entry_check.py');
        // no limit on the statements or the rows beside the capture's: a number is recorded as a difference, never a verdict
        foreach (['STATEMENTS_EXTRA', 'ROWS_EXTRA', 'allowed 0..'] as $limit) {
            self::assertStringNotContainsString($limit, $check, 'a limit on a count is back: ' . $limit);
        }
        self::assertStringContainsString('"statements_and_rows_vs_capture": "recorded, not judged"', $check);
        // what does judge a route: the status, the login, a write, the latch
        self::assertStringContainsString('if res["error"] is not None or res["status"] != 200:', $check);
        self::assertStringContainsString('if sent != expected:', $check);
        self::assertStringContainsString('if row["writes"]:', $check);
        self::assertStringContainsString('and not latch_before and not latch_after', $check);
    }

    public function testTheEntryCheckSendsTheHostOnEveryRequest(): void
    {
        $check = self::read(self::LAB . '/bin/inner/entry_check.py');
        // the route requests: the targets file's headers, then the route's own
        self::assertStringContainsString('headers = dict(targets.get("headers") or {}) | dict(t.get("headers") or {})', $check);
        // the POST and the login that is not offered carry the same Host
        self::assertSame(2, substr_count($check, 'common | {"X-Lab-Login"'));
        self::assertSame(1, substr_count($check, 'common = dict(targets.get("headers") or {})'));
    }
}
