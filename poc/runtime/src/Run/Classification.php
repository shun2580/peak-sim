<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

/**
 * Why an attempt is measured or not. The vocabulary is closed: it becomes a column of the report.
 *
 * Pure functions on strings, so the table is testable without Laravel. Names of exception classes are matched by
 * text for the same reason (and because the target app may ship a version of a class we have never seen).
 */
final class Classification
{
    public const OK = 'ok';
    public const UNFILLED_PARAM = 'unfilled_param';
    /** The controller has no such operation (apiResource registers routes for operations it does not define). Never hit. */
    public const ACTION_NOT_DEFINED = 'action_not_defined';
    public const UNAUTHENTICATED = 'unauthenticated';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const METHOD_NOT_ALLOWED = 'method_not_allowed';
    public const CSRF_419 = 'csrf_419';
    public const VALIDATION_422 = 'validation_422';
    public const RATE_LIMITED = 'rate_limited';
    public const SIGNATURE_REQUIRED = 'signature_required';
    public const EXCEPTION = 'exception';
    public const EXTERNAL_CALL_BLOCKED = 'external_call_blocked';
    public const NOT_ROLLBACKABLE = 'not_rollbackable';
    public const APP_WRITE_ATTEMPT = 'app_write_attempt';
    public const CLIENT_ERROR = 'client_error';
    public const UNEXPECTED_STATUS = 'unexpected_status';
    public const TIMEOUT = 'timeout';

    public const ALL = [
        self::OK,
        self::UNFILLED_PARAM,
        self::ACTION_NOT_DEFINED,
        self::UNAUTHENTICATED,
        self::FORBIDDEN,
        self::NOT_FOUND,
        self::METHOD_NOT_ALLOWED,
        self::CSRF_419,
        self::VALIDATION_422,
        self::RATE_LIMITED,
        self::SIGNATURE_REQUIRED,
        self::EXCEPTION,
        self::EXTERNAL_CALL_BLOCKED,
        self::NOT_ROLLBACKABLE,
        self::APP_WRITE_ATTEMPT,
        self::CLIENT_ERROR,
        self::UNEXPECTED_STATUS,
        self::TIMEOUT,
    ];

    /** Messages of a failed outbound connection (network blocked, name not resolvable, route absent). */
    private const EXTERNAL_MESSAGE = '/getaddrinfo|Name or service not known|Temporary failure in name resolution|Could not resolve host|Network is unreachable|cURL error (?:6|7|28)\b|Connection timed out|Operation timed out/i';

    /** Exception classes that mean "a network call failed" whatever their message says. */
    private const EXTERNAL_CLASS = '/(?:^|\\\\)(?:ConnectionException|ConnectException)$/';

    /** A database failure is never an external call, even when the message talks about a host name. */
    private const DATABASE_CLASS = '/PDOException$|QueryException$/';

    public static function fromStatus(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 400 => self::OK,
            $status === 401 => self::UNAUTHENTICATED,
            $status === 403 => self::FORBIDDEN,
            $status === 404 => self::NOT_FOUND,
            $status === 405 => self::METHOD_NOT_ALLOWED,
            $status === 419 => self::CSRF_419,
            $status === 422 => self::VALIDATION_422,
            $status === 429 => self::RATE_LIMITED,
            $status >= 500 && $status < 600 => self::EXCEPTION,
            $status >= 400 && $status < 500 => self::CLIENT_ERROR,
            default => self::UNEXPECTED_STATUS,
        };
    }

    public static function fromThrowable(string $exceptionClass, string $message): string
    {
        if (preg_match(self::DATABASE_CLASS, $exceptionClass) === 1) {
            return self::EXCEPTION;
        }
        if (str_ends_with($exceptionClass, 'RequestTimeout')) {
            return self::TIMEOUT;
        }
        if (str_ends_with($exceptionClass, 'InvalidSignatureException')) {
            return self::SIGNATURE_REQUIRED;
        }
        if (stripos($message, 'Read-only file system') !== false) {
            return self::APP_WRITE_ATTEMPT;
        }
        if (preg_match(self::EXTERNAL_CLASS, $exceptionClass) === 1 || preg_match(self::EXTERNAL_MESSAGE, $message) === 1) {
            return self::EXTERNAL_CALL_BLOCKED;
        }

        return self::EXCEPTION;
    }

    /**
     * The final class of an attempt. Precedence, strongest first:
     *   a write that cannot be undone  >  an outside call that was blocked  >  the exception  >  the status.
     * A blocked outside call wins even over a 200: the app swallowed the failure, so the path that was measured is
     * not the path a real run takes.
     *
     * @param list<string> $externalHosts  hosts the app tried to reach (host names only)
     * @param ?string      $rollbackProblem e.g. "ddl: ALTER TABLE ..." (see QueryCounter::rollbackProblem())
     * @param bool         $redirectedToLogin a 3xx whose target is the login page: the route wanted a user
     * @param ?string      $responseNote what Requester::noteOf() kept of a failed answer; it is the detail when nothing else is
     */
    public static function decide(
        ?int $status,
        ?string $exceptionClass,
        ?string $exceptionMessage,
        array $externalHosts,
        ?string $rollbackProblem,
        bool $redirectedToLogin = false,
        ?string $responseNote = null,
    ): ClassificationResult {
        if ($rollbackProblem !== null) {
            return new ClassificationResult(self::NOT_ROLLBACKABLE, $rollbackProblem);
        }
        if ($externalHosts !== []) {
            return new ClassificationResult(self::EXTERNAL_CALL_BLOCKED, 'hosts: ' . implode(', ', array_values(array_unique($externalHosts))));
        }

        $exceptionDetail = null;
        if ($exceptionClass !== null) {
            $message = $exceptionMessage ?? '';
            $exceptionDetail = mb_substr($exceptionClass . ': ' . $message, 0, Attempt::DETAIL_LIMIT);
            $fromThrowable = self::fromThrowable($exceptionClass, $message);
            if ($fromThrowable !== self::EXCEPTION || $status === null || $status >= 500) {
                return new ClassificationResult($fromThrowable, $exceptionDetail);
            }
        }

        if ($status === null) {
            return new ClassificationResult(self::UNEXPECTED_STATUS, 'no response');
        }

        if ($redirectedToLogin && $status >= 300 && $status < 400) {
            return new ClassificationResult(self::UNAUTHENTICATED, 'redirected to the login page');
        }

        $class = self::fromStatus($status);

        return new ClassificationResult($class, $class === self::OK ? null : ($exceptionDetail ?? $responseNote));
    }

    public static function isMeasured(string $class): bool
    {
        return $class === self::OK;
    }
}
