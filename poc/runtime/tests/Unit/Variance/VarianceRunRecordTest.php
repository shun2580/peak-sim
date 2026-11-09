<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Variance;

use PHPUnit\Framework\TestCase;

/**
 * bin/variance-run, run for real (bash) with a stand-in for `docker` and for the two helper scripts that need a stack. The stand-in
 * `measure-one` answers like a route that sends a file: the file's bytes reach standard output, in front of the attempt records
 * (once on a line of their own, once on the same line as the record). The record of a trial, attempt.jsonl, must hold the attempt
 * records and nothing else, so every line of it is JSON; trial.json says how many bytes were left out, never what they were.
 */
final class VarianceRunRecordTest extends TestCase
{
    /** What the file route sends: not text, no newline in it, with a brace and the word "route" inside to try the reader. */
    private const BODY_A = "PK\x03\x04\x14\x00\x00\x00\x08\x00\xff\xfe{route}\x00zipbytes";

    private const BODY_B = "PK\x01\x02\x00\xc3\x28{\"route\":\"fake\"\x7fend";

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-varrec-' . bin2hex(random_bytes(4));
        foreach (['bin', 'fakebin', 'app/vendor', 'out'] as $d) {
            mkdir($this->dir . '/' . $d, 0777, true);
        }
        touch($this->dir . '/app/vendor/autoload.php');
        file_put_contents($this->dir . '/tasks.json', '[]');
        $source = dirname(__DIR__, 3) . '/bin';
        foreach (['variance-run', '_stack.sh'] as $name) {
            copy($source . '/' . $name, $this->dir . '/bin/' . $name);
        }
        // Stand-ins for the helpers that need a stack: the preflight passes, the watch waits for its done file.
        file_put_contents($this->dir . '/bin/preflight', "#!/usr/bin/env bash\nexit 0\n");
        file_put_contents($this->dir . '/bin/variance-watch', "#!/usr/bin/env bash\nfor a in \"\$@\"; do case \"\$a\" in --done-file=*) f=\"\${a#--done-file=}\";; esac; done\nwhile [ ! -e \"\$f\" ]; do sleep 0.05; done\n");
        file_put_contents($this->dir . '/body_a.bin', self::BODY_A);
        file_put_contents($this->dir . '/body_b.bin', self::BODY_B);
        file_put_contents($this->dir . '/fakebin/docker', $this->fakeDocker());
        foreach (['bin/preflight', 'bin/variance-watch', 'fakebin/docker'] as $f) {
            chmod($this->dir . '/' . $f, 0755);
        }
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    private function fakeDocker(): string
    {
        $record = static fn (int $attempt, string $phase): string => json_encode(['route' => '/export/zip', 'method' => 'GET', 'scale' => 20000, 'axis' => 'N', 'attempt' => $attempt, 'status' => 200, 'class' => 'ok', 'phase' => $phase], JSON_UNESCAPED_SLASHES);

        return <<<BASH
#!/usr/bin/env bash
# stand-in for docker: only what bin/variance-run asks of it
all="\$*"
case "\$all" in
  *" up "*|*" down "*) exit 0 ;;
  *variance-plan.php*)
    d="\$PEAK_OUT_DIR"
    echo 1 > "\$d/plan.groups"; echo '[]' > "\$d/plan.json"
    mkdir -p "\$d/g1"
    printf 'k\\tt\\t20000\\n' > "\$d/g1/plan.meta"
    printf -- '--scale=20000\\n' > "\$d/g1/plan.build_args"
    printf '1\\tGET\\t/export/zip\\t/export/zip\\tGET__export_zip\\n' > "\$d/g1/plan.routes"
    printf -- '--uri=/export/zip\\n--method=GET\\n--phases=warmup,cold\\n' > "\$d/g1/plan.r1.args"
    exit 0 ;;
  *build-db.php*) echo '{"built":true}'; exit 0 ;;
  *measure-one*)
    cat "{$this->dir}/body_a.bin"; printf '\\n%s\\n' '{$record(1, 'warmup')}'
    cat "{$this->dir}/body_b.bin"; printf '%s\\n' '{$record(2, 'cold')}'
    exit 0 ;;
  *) exit 0 ;;
esac
BASH;
    }

    /** @return array{int, string, string} */
    private function runScript(): array
    {
        $proc = proc_open(
            ['bash', $this->dir . '/bin/variance-run', '--app=' . $this->dir . '/app', '--tasks=' . $this->dir . '/tasks.json', '--scale=20000',
                '--out=' . $this->dir . '/out', '--label=rec', '--routes=GET /export/zip', '--dbs=1', '--laps=1', '--tap=off', '--profile=none',
                '--source-commit=test', '--watch-interval=1'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => $this->dir . '/fakebin:/usr/bin:/bin', 'HOME' => $this->dir],
        );
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    private function trialDir(): string
    {
        $found = glob($this->dir . '/out/rec/db1/g1/plain_lap1/01_*') ?: [];
        $this->assertCount(1, $found, 'the trial directory');

        return $found[0];
    }

    public function test_the_record_of_a_trial_holds_the_attempt_records_and_no_byte_of_the_file(): void
    {
        [$code, , $err] = $this->runScript();
        $this->assertSame(0, $code, $err);

        $raw = (string) file_get_contents($this->trialDir() . '/attempt.jsonl');
        $lines = preg_split('/\R/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertIsArray(json_decode($line, true), 'every line of attempt.jsonl is JSON: ' . bin2hex(substr($line, 0, 12)));
        }
        $this->assertSame(['warmup', 'cold'], array_map(static fn (string $l): string => json_decode($l, true)['phase'], $lines));
        $this->assertStringNotContainsString("PK\x03\x04", $raw);
        $this->assertStringNotContainsString('zipbytes', $raw);
    }

    public function test_trial_json_says_how_many_bytes_were_left_out_and_the_count_of_attempts_is_unchanged(): void
    {
        [$code, , $err] = $this->runScript();
        $this->assertSame(0, $code, $err);

        $trial = json_decode((string) file_get_contents($this->trialDir() . '/trial.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $trial['attempts']);
        $this->assertSame(2, $trial['attempts_ok']);
        $this->assertSame(strlen(self::BODY_A) + strlen(self::BODY_B), $trial['stdout_other_bytes']);
    }

    public function test_what_measure_one_wrote_is_not_kept_beside_the_record(): void
    {
        [$code, , $err] = $this->runScript();
        $this->assertSame(0, $code, $err);

        $names = array_values(array_diff(scandir($this->trialDir()) ?: [], ['.', '..']));
        sort($names);
        $this->assertSame(['attempt.jsonl', 'stats_after.tsv', 'stats_before.tsv', 'stderr.log', 'trial.json'], $names);
    }
}
