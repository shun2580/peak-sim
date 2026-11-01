<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

use PeakSim\Runtime\Boot\BootedApp;

/**
 * Sends one request through the app's HTTP kernel, in this process (no network, no web server).
 *
 * The request is built from the spec only; middleware runs as the app defines it (auth, throttle, CSRF). Redirects
 * are not followed: the one response is what is measured.
 */
final class Requester
{
    public const BASE_URL = 'http://measure.invalid';

    /** Longest a single request may run, in seconds (needs ext-pcntl; without it the limit is the caller's). */
    public const TIME_LIMIT = 20;

    /** Lets a test shorten the limit (1 to 60 seconds). */
    public const TIME_LIMIT_ENV = 'PEAK_MEASURE_REQUEST_TIMEOUT';

    public function __construct(private readonly BootedApp $booted)
    {
    }

    public function send(RequestSpec $spec): RequestResult
    {
        $app = $this->booted->app;
        $kernel = $this->booted->kernel;
        $requestClass = 'Illuminate\\Http\\Request';

        $server = [];
        $cookies = [];
        $headers = $spec->headers;
        $headers['Accept'] ??= $spec->json ? 'application/json' : 'text/html';
        if ($spec->csrf) {
            [$token, $cookieName, $cookieValue] = $this->csrfParts();
            $headers['X-CSRF-TOKEN'] = $token;
            $cookies[$cookieName] = $cookieValue;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $method = strtoupper($spec->method);
        $content = null;
        $parameters = [];
        if ($spec->body !== null) {
            if ($spec->json) {
                $server['CONTENT_TYPE'] = 'application/json';
                $content = json_encode($spec->body, JSON_THROW_ON_ERROR);
            } else {
                $parameters = $spec->body;
            }
        }
        $request = $requestClass::create(self::BASE_URL . '/' . ltrim($spec->uri, '/'), $method, $method === 'GET' ? [] : $parameters, $cookies, [], $server, $content);

        $this->booted->exceptions->take();
        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage();

        $response = null;
        $thrown = null;
        $this->armTimeout();
        try {
            $response = $kernel->handle($request);
            $bytes = $this->bytesOf($response);
            $kernel->terminate($request, $response);
        } catch (\Throwable $e) {
            $thrown = $e;
            $bytes = 0;
        } finally {
            $this->disarmTimeout();
        }
        $peak = max(0, memory_get_peak_usage() - $base);

        $reported = $this->booted->exceptions->take();
        $location = $response === null ? null : $response->headers->get('Location');
        $redirectPath = is_string($location) ? parse_url($location, PHP_URL_PATH) : null;

        return new RequestResult(
            $response === null ? null : $response->getStatusCode(),
            $bytes,
            $peak,
            $thrown ?? $reported,
            is_string($redirectPath) ? $redirectPath : null,
        );
    }

    /**
     * The session and its token, made before the request so a write route is not refused as "page expired". The
     * session store is the array driver, which keeps the data for the life of the process.
     *
     * @return array{string, string, string} [token, cookie name, encrypted cookie value]
     */
    private function csrfParts(): array
    {
        $app = $this->booted->app;
        $session = $app['session']->driver();
        if (!$session->isStarted()) {
            $session->start();
        }
        if ((string) $session->token() === '') {
            $session->regenerateToken();
        }
        $token = (string) $session->token();
        $session->save();

        $name = $session->getName();
        $encrypter = $app['encrypter'];
        $prefix = 'Illuminate\\Cookie\\CookieValuePrefix';
        $serialized = method_exists('Illuminate\\Cookie\\Middleware\\EncryptCookies', 'serialized')
            ? \Illuminate\Cookie\Middleware\EncryptCookies::serialized($name)
            : false;
        $value = $encrypter->encrypt($prefix::create($name, $encrypter->getKey()) . $session->getId(), $serialized);

        return [$token, $name, $value];
    }

    private function bytesOf(object $response): int
    {
        if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            $length = 0;
            ob_start(static function (string $chunk) use (&$length): string {
                $length += strlen($chunk);

                return '';
            }, 8192);
            try {
                $response->sendContent();
            } finally {
                ob_end_clean();
            }

            return $length;
        }
        if ($response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            return 0;
        }

        return strlen((string) $response->getContent());
    }

    private function armTimeout(): void
    {
        if (!function_exists('pcntl_alarm')) {
            return;
        }
        pcntl_async_signals(true);
        $limit = self::timeLimit();
        pcntl_signal(SIGALRM, static function () use ($limit): void {
            throw new RequestTimeout(sprintf('the request ran longer than %d seconds', $limit));
        });
        pcntl_alarm($limit);
    }

    public static function timeLimit(?string $override = null): int
    {
        $override ??= (string) getenv(self::TIME_LIMIT_ENV);
        if (preg_match('/^[0-9]{1,2}$/D', $override) === 1 && (int) $override >= 1 && (int) $override <= 60) {
            return (int) $override;
        }

        return self::TIME_LIMIT;
    }

    private function disarmTimeout(): void
    {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
    }
}
