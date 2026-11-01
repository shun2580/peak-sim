<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Boot\BootedApp;
use PeakSim\Runtime\Boot\BootFailure;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Guard\RollbackGuard;
use PeakSim\Runtime\Route\RequestCredentials;

/**
 * Measures one (route, scale) in this process: three requests (warm-up, cold, warm) against a database that has
 * already been made, migrated and filled by the caller. Prints one JSON line per attempt.
 *
 * Every request runs inside a transaction that is rolled back. A request that cannot be rolled back stops the
 * measurement at once (exit 14): the caller drops the scale's database and builds it again.
 *
 * Exit codes: 0 done / 2 usage / 10 refused / 12 the app did not start / 14 the database is contaminated.
 */
final class MeasureOne
{
    public const EXIT_OK = 0;
    public const EXIT_USAGE = 2;
    public const EXIT_CONTAMINATED = 14;

    private const USAGE = <<<'TXT'
        usage: measure-one --scale=N --uri=/path [options]
          --method=GET            HTTP method
          --route=<label>         label in the output (default: the uri without the leading slash)
          --axis=N                which sweep this scale belongs to (N or M)
          --header=Name:Value     repeatable
          --body-json='{"a":1}'   request body (a JSON object)
          --form                  send the body as a form instead of JSON
          --html                  ask for text/html instead of application/json
          --csrf                  send a session cookie and X-CSRF-TOKEN
          --query=name=value      repeatable: a query string parameter of a GET (a secret is given as a token {key:purpose:login})
          --act-as=Model:key[:guard]   log this user in (before every request)
          --gate-bypass           let every Gate check pass (recorded as gate_bypass=true)
          --phases=warmup,cold,warm    default: all three
        Input (database host, user, password, run id, app root, scales) comes from PEAK_MEASURE_* variables only.

        TXT;

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * @param array<string, mixed> $env
     * @param resource             $out
     * @param resource             $err
     */
    public function __construct(private readonly array $env, $out, $err)
    {
        $this->out = $out;
        $this->err = $err;
    }

    /** @param list<string> $argv arguments after the script name */
    public function run(array $argv): int
    {
        try {
            $args = self::parseArguments($argv);
        } catch (\InvalidArgumentException $e) {
            fwrite($this->err, $e->getMessage() . "\n\n" . self::USAGE);

            return self::EXIT_USAGE;
        }

        try {
            $config = MeasureConfig::fromEnv($this->env);
            $booted = (new AppBooter())->boot($config, $args['scale']);
        } catch (MeasureRefused $e) {
            fwrite($this->err, $e->getMessage() . "\n");

            return MeasureRefused::EXIT_CODE;
        } catch (BootFailure $e) {
            fwrite($this->err, 'cannot start the app: ' . $e->getMessage() . "\n");

            return BootFailure::EXIT_CODE;
        }

        try {
            return $this->measure($config, $booted, $args);
        } catch (MeasureRefused $e) {
            fwrite($this->err, $e->getMessage() . "\n");

            return MeasureRefused::EXIT_CODE;
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    private function measure(MeasureConfig $config, BootedApp $booted, array $args): int
    {
        $app = $booted->app;
        $db = $app['db'];
        $default = (string) $app['config']->get('database.default');

        $counter = new QueryCounter($booted->dbName, $default);
        $counter->listenTo($app['events']);
        $rows = RowCounter::connect($config, $booted->dbName);
        $requester = new Requester($booted);
        try {
            $query = RequestCredentials::expand($args['query'], (string) ($this->env[AppBooter::APP_KEY_ENV] ?? ''));
        } catch (\InvalidArgumentException $e) {
            // Nothing can be sent: say so as an attempt that was not made (the message names no value).
            $this->emit($this->attempt($args, Attempt::WARMUP, null, null, null, Classification::UNFILLED_PARAM, 'credentials: ' . $e->getMessage()));

            return self::EXIT_OK;
        }
        $spec = new RequestSpec($args['method'], $args['uri'], $args['headers'], $args['body'], $args['json'], $args['csrf'], $query);

        if ($args['gateBypass']) {
            $app->make('Illuminate\\Contracts\\Auth\\Access\\Gate')->before(static fn (mixed $user = null, mixed $ability = null): bool => true); // nullable user: guests pass too
        }
        fwrite($this->err, 'booted: ' . json_encode($booted->report, JSON_UNESCAPED_SLASHES) . "\n");

        foreach ($args['phases'] as $phase) {
            $loginError = null;
            if ($phase === Attempt::COLD) {
                $app['cache']->store()->flush();
            }
            if ($args['actAs'] !== null) {
                try {
                    ActingAs::apply($app, $args['actAs']);
                } catch (\Throwable $e) {
                    $loginError = $e;
                }
            }
            if ($loginError !== null) {
                $this->emit($this->attempt($args, $phase, null, null, null, Classification::UNFILLED_PARAM, 'act-as: ' . $loginError->getMessage()));

                return self::EXIT_OK;
            }

            $before = $rows->snapshot();
            $db->beginTransaction();
            $booted->externalCalls->clear();
            $counter->start();
            $result = $requester->send($spec);
            $counter->stop();
            $level = $db->transactionLevel();
            for ($i = 0; $i < 50 && $db->transactionLevel() > 0; $i++) {
                $db->rollBack();
            }
            $after = $rows->snapshot();

            if ($counter->foreignSchemas() !== []) {
                throw new MeasureRefused('refused: a statement referred to a schema other than the measurement database: ' . implode(', ', $counter->foreignSchemas()));
            }

            $problem = $counter->rollbackProblem();
            if ($problem === null && !RollbackGuard::transactionStillOpen($level)) {
                $problem = 'transaction_control: the transaction was ended by the request';
            }
            if ($problem === null && ($changed = RollbackGuard::changedTables($before, $after)) !== []) {
                $problem = 'row_count_changed: ' . implode(', ', $changed);
            }

            $exception = $result->exception;
            $decision = Classification::decide(
                $result->status,
                $exception === null ? null : get_class($exception),
                $exception?->getMessage(),
                $booted->externalCalls->hosts(),
                $problem,
                $result->redirectPath !== null && $result->redirectPath === $this->loginPath($app),
                $result->note,
            );

            $measured = $decision->isMeasured();
            $this->emit($this->attempt(
                $args,
                $phase,
                $result->status,
                $result,
                $counter,
                $decision->class,
                $decision->detail,
            ));

            if ($problem !== null) {
                fwrite($this->err, "the database can no longer be trusted ($problem); build it again\n");

                return self::EXIT_CONTAMINATED;
            }
            if (!$measured && $phase === Attempt::WARMUP) {
                break; // the same request will fail the same way; the caller changes the request and asks again
            }
        }

        return self::EXIT_OK;
    }

    /** The path of the app's login page (route "login" when it exists, else /login). */
    private function loginPath(object $app): string
    {
        $router = $app['router'];
        if ($router->has('login')) {
            $path = parse_url($app['url']->route('login'), PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        return '/login';
    }

    /** @param array<string, mixed> $args */
    private function attempt(array $args, int $phase, ?int $status, ?RequestResult $result, ?QueryCounter $counter, string $class, ?string $detail): Attempt
    {
        return new Attempt(
            route: $args['route'],
            method: $args['method'],
            scale: $args['scale'],
            axis: $args['axis'],
            attempt: $phase,
            status: $status,
            queries: $counter?->total(),
            byConnection: $counter?->byConnection() ?? [],
            tables: $counter?->tables() ?? [],
            bytes: $result?->bytes,
            peakMem: $result?->peakMem,
            gateBypass: $args['gateBypass'],
            class: $class,
            detail: $detail,
        );
    }

    private function emit(Attempt $attempt): void
    {
        fwrite($this->out, $attempt->toJson() . "\n");
    }

    /**
     * @param list<string> $argv
     *
     * @return array{scale: int, uri: string, method: string, route: string, axis: string, headers: array<string, string>, query: array<string, string>, body: ?array<string, mixed>, json: bool, csrf: bool, actAs: ?string, gateBypass: bool, phases: list<int>}
     */
    public static function parseArguments(array $argv): array
    {
        $opts = ['header' => [], 'query' => []];
        foreach ($argv as $arg) {
            if (!str_starts_with($arg, '--')) {
                throw new \InvalidArgumentException('unexpected argument: ' . $arg);
            }
            $pair = explode('=', substr($arg, 2), 2);
            $name = $pair[0];
            $value = $pair[1] ?? null;
            if ($name === 'header') {
                $opts['header'][] = (string) $value;
            } elseif ($name === 'query') {
                $opts['query'][] = $value;
            } else {
                $opts[$name] = $value ?? true;
            }
        }
        $known = ['scale', 'uri', 'method', 'route', 'axis', 'header', 'query', 'body-json', 'form', 'html', 'csrf', 'act-as', 'gate-bypass', 'phases'];
        foreach (array_keys($opts) as $name) {
            if (!in_array($name, $known, true)) {
                throw new \InvalidArgumentException('unknown option: --' . $name);
            }
        }

        $scale = $opts['scale'] ?? null;
        if (!is_string($scale) || preg_match('/^[0-9]{1,7}$/D', $scale) !== 1) {
            throw new \InvalidArgumentException('--scale=N is required (a positive integer)');
        }
        $uri = $opts['uri'] ?? null;
        if (!is_string($uri) || !str_starts_with($uri, '/')) {
            throw new \InvalidArgumentException('--uri=/path is required and must start with a slash');
        }
        $method = strtoupper(is_string($opts['method'] ?? null) ? $opts['method'] : 'GET');
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new \InvalidArgumentException('--method must be GET, POST, PUT, PATCH or DELETE');
        }

        $headers = [];
        foreach ($opts['header'] as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/D', $line, $m) !== 1) {
                throw new \InvalidArgumentException('--header must look like Name:Value');
            }
            $headers[$m[1]] = $m[2];
        }

        $query = [];
        foreach ($opts['query'] as $line) {
            if (!is_string($line) || preg_match('/^([A-Za-z0-9_.-]{1,40})=(.*)$/sD', $line, $m) !== 1) {
                throw new \InvalidArgumentException('--query must look like name=value');
            }
            $query[$m[1]] = $m[2];
        }

        $body = null;
        if (isset($opts['body-json'])) {
            $decoded = is_string($opts['body-json']) ? json_decode($opts['body-json'], true) : null;
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('--body-json must be a JSON object');
            }
            $body = $decoded;
        }

        $names = ['warmup' => Attempt::WARMUP, 'cold' => Attempt::COLD, 'warm' => Attempt::WARM];
        $phases = [];
        foreach (explode(',', is_string($opts['phases'] ?? null) ? $opts['phases'] : 'warmup,cold,warm') as $name) {
            if (!isset($names[$name])) {
                throw new \InvalidArgumentException('--phases takes warmup, cold and warm');
            }
            $phases[] = $names[$name];
        }

        $actAs = $opts['act-as'] ?? null;
        if ($actAs !== null) {
            if (!is_string($actAs)) {
                throw new \InvalidArgumentException('--act-as needs a value');
            }
            ActingAs::parse($actAs);
        }

        return [
            'scale' => (int) $scale,
            'uri' => $uri,
            'method' => $method,
            'route' => is_string($opts['route'] ?? null) ? $opts['route'] : ltrim($uri, '/'),
            'axis' => is_string($opts['axis'] ?? null) ? $opts['axis'] : 'N',
            'headers' => $headers,
            'query' => $query,
            'body' => $body,
            'json' => !isset($opts['form']),
            'csrf' => isset($opts['csrf']),
            'actAs' => $actAs,
            'gateBypass' => isset($opts['gate-bypass']),
            'phases' => $phases,
        ];
    }
}
