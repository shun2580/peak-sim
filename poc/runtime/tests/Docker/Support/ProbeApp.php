<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker\Support;

/**
 * A scratch copy of the fixture app with a few extra routes, so the paths the real fixture does not have (a DDL
 * statement, a write on another connection, an outside call, a timeout) can be driven through the same code.
 * The copy lives in the temp dir (a tmpfs); the fixture app itself is never touched, and `vendor` is a link.
 */
final class ProbeApp
{
    public function __construct(public readonly string $root)
    {
    }

    public static function create(string $fixtureRoot, string $suffix): self
    {
        $root = sys_get_temp_dir() . '/peak-probe-app-' . $suffix . '-' . getmypid();
        self::copy($fixtureRoot, $root);
        symlink($fixtureRoot . '/vendor', $root . '/vendor');
        mkdir($root . '/storage/framework', 0777, true);

        file_put_contents($root . '/routes/probe.php', self::routes());
        $bootstrap = (string) file_get_contents($root . '/bootstrap/app.php');
        $bootstrap = str_replace("['ok', 'ng', 'hidden', 'unknown']", "['ok', 'ng', 'hidden', 'unknown', 'probe']", $bootstrap, $count);
        if ($count !== 1) {
            throw new \RuntimeException('the fixture bootstrap/app.php changed; update ProbeApp');
        }
        file_put_contents($root . '/bootstrap/app.php', $bootstrap);

        // Not under app/: the composer map of the (linked) vendor points App\ at the fixture, not at this copy.
        file_put_contents($root . '/probe_user.php', <<<'PHP'
            <?php
            namespace PeakProbe;
            class ProbeUser extends \Illuminate\Foundation\Auth\User
            {
                protected $table = 'customers';
                public $timestamps = false;
            }
            PHP);

        return new self($root);
    }

    /** Hard-codes the settings an app could have in its config: a host, a database, a user, a pgsql default. */
    public function hardcodeDatabaseConfig(): void
    {
        $file = $this->root . '/config/database.php';
        $text = (string) file_get_contents($file);
        $text = preg_replace("/'default' => env\\('DB_CONNECTION'[^\\n]*\\n/", "'default' => 'pgsql',\n", $text, 1, $a);
        $text = preg_replace("/'host' => env\\('DB_HOST'[^\\n]*\\n/", "'host' => '127.0.0.1',\n", $text, -1, $b);
        $text = preg_replace("/'database' => env\\('DB_DATABASE'[^\\n]*\\n/", "'database' => 'app',\n", $text, -1, $c);
        $text = preg_replace("/'username' => env\\('DB_USERNAME'[^\\n]*\\n/", "'username' => 'root',\n", $text, -1, $d);
        if ($a < 1 || $b < 1 || $c < 1 || $d < 1) {
            throw new \RuntimeException('the fixture config/database.php changed; update ProbeApp');
        }
        file_put_contents($file, $text);
    }

    public function remove(): void
    {
        if (!str_starts_with(basename($this->root), 'peak-probe-app-') || !is_dir($this->root)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            ($item->isDir() && !$item->isLink()) ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->root);
    }

    private static function copy(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (new \DirectoryIterator($from) as $entry) {
            if ($entry->isDot() || in_array($entry->getFilename(), ['vendor', 'storage', 'node_modules', '.git'], true)) {
                continue;
            }
            $target = $to . '/' . $entry->getFilename();
            if ($entry->isDir()) {
                self::copy($entry->getPathname(), $target);
            } else {
                copy($entry->getPathname(), $target);
            }
        }
    }

    private static function routes(): string
    {
        return <<<'PHP'
            <?php
            require_once __DIR__ . '/../probe_user.php';

            use Illuminate\Support\Facades\DB;
            use Illuminate\Support\Facades\Gate;
            use Illuminate\Support\Facades\Http;
            use Illuminate\Support\Facades\Route;

            Route::post('ddl', fn () => DB::statement('create table probe_t (id int)'));
            Route::post('other-connection', function () {
                DB::connection('mariadb')->table('customers')->insert(['id' => 9001, 'name' => 'x', 'email' => 'x9001@example.test']);
                return 'ok';
            });
            Route::post('commit', function () {
                DB::table('customers')->insert(['id' => 9002, 'name' => 'x', 'email' => 'x9002@example.test']);
                DB::commit();
                return 'ok';
            });
            Route::get('external', function () {
                try {
                    Http::get('https://api.example.test/v1/secret-path?token=SECRET');
                } catch (\Throwable $e) {
                    // the app swallows the failure and answers 200
                }
                return 'ok';
            });
            Route::get('boom', fn () => throw new \RuntimeException('boom'));
            Route::get('write-app', function () {
                file_put_contents('/app/probe-write.txt', 'x');
                return 'ok';
            });
            Route::get('gate', function () {
                Gate::authorize('something-nobody-defined');
                return 'ok';
            });
            Route::get('sleep', function () {
                sleep(30);
                return 'ok';
            });
            Route::get('auth-only', fn () => auth()->check() ? 'ok' : abort(401));
            Route::get('login-page', fn () => 'login')->name('login');
            Route::get('guarded', fn () => redirect()->route('login'));
            Route::get('elsewhere', fn () => redirect('/probe/auth-only'));
            Route::get('forbidden', fn () => abort(403));
            Route::get('missing', fn () => abort(404));
            PHP;
    }
}
