<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The analysis never leaves the machine and never runs anything (design I7): src/ must not reach the network, a
 * database, or a command. The one door is proc_open in the process runner that starts artisan for the second
 * input mode.
 *
 * The scan reads tokens, so a word inside a comment or a string does not count, and `$x->exec()` is not `exec()`.
 */
final class NoNetworkTest extends TestCase
{
    /** Files of src/ that may call proc_open (the artisan process runner and its interface home). */
    private const PROC_OPEN_FILES = ['src/Input/ProcessRunner.php', 'src/Input/ProcOpenRunner.php'];

    private const FORBIDDEN_FUNCTIONS = [
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server', 'socket_create', 'socket_connect',
        'exec', 'shell_exec', 'system', 'passthru', 'popen', 'pcntl_exec',
    ];

    private const FORBIDDEN_PREFIXES = ['curl_', 'mysqli_'];

    private const FORBIDDEN_CLASSES = ['PDO', 'mysqli'];

    private const URL_READERS = ['file_get_contents', 'fopen', 'file', 'readfile', 'copy'];

    /** @return array<string, array{0: string}> */
    public static function sourceFileProvider(): array
    {
        $cases = [];
        foreach (self::sourceFiles() as $relative => $absolute) {
            $cases[$relative] = [$relative];
        }

        return $cases;
    }

    #[DataProvider('sourceFileProvider')]
    public function testSourceFileReachesNeitherNetworkNorDatabaseNorCommand(string $relative): void
    {
        $source = (string) file_get_contents(self::sourceFiles()[$relative]);
        $allowProcOpen = in_array($relative, self::PROC_OPEN_FILES, true);

        $hits = self::scan($source, $allowProcOpen);

        $this->assertSame([], $hits, "{$relative}: " . implode('; ', $hits));
    }

    public function testThereIsSomethingToScanAndProcOpenHasAtMostTheOneHome(): void
    {
        $files = self::sourceFiles();
        $this->assertNotEmpty($files, 'src/ holds no PHP file: the scan would pass for nothing');

        $withProcOpen = [];
        foreach ($files as $relative => $absolute) {
            $hits = self::scan((string) file_get_contents($absolute), false);
            if (preg_grep('/proc_open/', $hits) !== []) {
                $withProcOpen[] = $relative;
            }
        }
        $this->assertSame([], array_values(array_diff($withProcOpen, self::PROC_OPEN_FILES)), 'proc_open outside the process runner');
    }

    /** @return array<string, array{0: string, 1: bool, 2: int}> source, allow proc_open, expected number of hits */
    public static function scannerProvider(): array
    {
        return [
            'curl call' => ['<?php curl_init("x");', false, 1],
            'curl in a comment' => ['<?php // curl_init() is not used here', false, 0],
            'curl in a string' => ['<?php $m = "no curl_exec here";', false, 0],
            'fsockopen' => ['<?php fsockopen("h", 80);', false, 1],
            'stream_socket_client' => ['<?php stream_socket_client("tcp://h:1");', false, 1],
            'http read' => ["<?php file_get_contents('http://example.test/x');", false, 1],
            'https read through fopen' => ['<?php fopen("https://example.test", "r");', false, 1],
            'a local read is fine' => ["<?php file_get_contents('/etc/hostname');", false, 0],
            'a url in a message is fine' => ["<?php \$m = 'see https://example.test/docs';", false, 0],
            'PDO' => ['<?php $db = new PDO("sqlite::memory:");', false, 1],
            'fully qualified PDO' => ['<?php $db = new \PDO("sqlite::memory:");', false, 1],
            'mysqli' => ['<?php $c = new mysqli("h");', false, 1],
            'mysqli_ function' => ['<?php mysqli_connect("h");', false, 1],
            'exec' => ['<?php exec("ls");', false, 1],
            'a method called exec is fine' => ['<?php $p->exec("ls"); Foo::system(1);', false, 0],
            'a function named exec being declared is fine' => ['<?php function exec() {}', false, 0],
            'shell_exec' => ['<?php shell_exec("ls");', false, 1],
            'proc_open outside the runner' => ['<?php proc_open("ls", [], $p);', false, 1],
            'proc_open in the runner' => ['<?php proc_open("ls", [], $p);', true, 0],
            'two hits' => ['<?php curl_init(); exec("x");', false, 2],
        ];
    }

    #[DataProvider('scannerProvider')]
    public function testTheScannerFindsWhatItShouldAndNothingElse(string $source, bool $allowProcOpen, int $expected): void
    {
        $this->assertCount($expected, self::scan($source, $allowProcOpen), implode('; ', self::scan($source, $allowProcOpen)));
    }

    /** @return array<string, string> app-relative path => absolute path, sorted */
    private static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
            }
        }
        ksort($found);

        return $found;
    }

    /** @return list<string> */
    private static function scan(string $source, bool $allowProcOpen): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
        $hits = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || !in_array($token[0], $names, true)) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            $afterMember = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true);
            if ($afterMember) {
                continue;
            }
            $name = ltrim($token[1], '\\');
            $short = strtolower(substr(strrchr('\\' . $name, '\\') ?: '', 1));
            $isCall = ($tokens[$i + 1] ?? null) === '(';

            if (in_array($name, self::FORBIDDEN_CLASSES, true) || in_array($short, array_map('strtolower', self::FORBIDDEN_CLASSES), true)) {
                $hits[] = "line {$token[2]}: {$name} (a database)";
            }
            if (!$isCall) {
                continue;
            }
            foreach (self::FORBIDDEN_PREFIXES as $prefix) {
                if (str_starts_with($short, $prefix)) {
                    $hits[] = "line {$token[2]}: {$short}() (network or database)";
                }
            }
            if (in_array($short, self::FORBIDDEN_FUNCTIONS, true)) {
                $hits[] = "line {$token[2]}: {$short}() (network or command)";
            }
            if ($short === 'proc_open' && !$allowProcOpen) {
                $hits[] = "line {$token[2]}: proc_open() outside the process runner";
            }
            if (in_array($short, self::URL_READERS, true)) {
                $argument = $tokens[$i + 2] ?? null;
                if (is_array($argument) && $argument[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('~^[\'"](https?|ftp)://~i', $argument[1]) === 1) {
                    $hits[] = "line {$token[2]}: {$short}() of a URL";
                }
            }
        }

        return $hits;
    }
}
