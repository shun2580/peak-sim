<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Run;

use PeakSim\Runtime\Run\Classification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClassificationTest extends TestCase
{
    /** @return iterable<string, array{int, string}> */
    public static function statusTable(): iterable
    {
        yield '200' => [200, Classification::OK];
        yield '201' => [201, Classification::OK];
        yield '204' => [204, Classification::OK];
        yield '302 is measured, the redirect is not followed' => [302, Classification::OK];
        yield '401' => [401, Classification::UNAUTHENTICATED];
        yield '403' => [403, Classification::FORBIDDEN];
        yield '404' => [404, Classification::NOT_FOUND];
        yield '405' => [405, Classification::METHOD_NOT_ALLOWED];
        yield '419' => [419, Classification::CSRF_419];
        yield '422' => [422, Classification::VALIDATION_422];
        yield '429' => [429, Classification::RATE_LIMITED];
        yield '500' => [500, Classification::EXCEPTION];
        yield '503' => [503, Classification::EXCEPTION];
        yield 'other 4xx' => [409, Classification::CLIENT_ERROR];
        yield '1xx' => [100, Classification::UNEXPECTED_STATUS];
        yield '0' => [0, Classification::UNEXPECTED_STATUS];
    }

    #[DataProvider('statusTable')]
    public function testStatusMapsToClass(int $status, string $expected): void
    {
        self::assertSame($expected, Classification::fromStatus($status));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function throwableTable(): iterable
    {
        yield 'illuminate http client connection failure' => [
            'Illuminate\\Http\\Client\\ConnectionException', 'peak-sim: blocked https://api.example.test/x', Classification::EXTERNAL_CALL_BLOCKED,
        ];
        yield 'guzzle connect exception' => [
            'GuzzleHttp\\Exception\\ConnectException', 'cURL error 6: Could not resolve host: example.com', Classification::EXTERNAL_CALL_BLOCKED,
        ];
        yield 'getaddrinfo in a plain exception' => [
            'RuntimeException', 'stream_socket_client(): php_network_getaddresses: getaddrinfo for example.com failed: Name or service not known', Classification::EXTERNAL_CALL_BLOCKED,
        ];
        yield 'temporary failure in name resolution' => [
            'ErrorException', 'file_get_contents(): php_network_getaddresses: getaddrinfo failed: Temporary failure in name resolution', Classification::EXTERNAL_CALL_BLOCKED,
        ];
        yield 'network unreachable' => [
            'ErrorException', 'fsockopen(): Unable to connect to 1.1.1.1:443 (Network is unreachable)', Classification::EXTERNAL_CALL_BLOCKED,
        ];
        yield 'a database failure is not an external call even if the host is unknown' => [
            'PDOException', 'SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for db failed: Name or service not known', Classification::EXCEPTION,
        ];
        yield 'query exception' => [
            'Illuminate\\Database\\QueryException', 'SQLSTATE[42S02]: Base table or view not found', Classification::EXCEPTION,
        ];
        yield 'read-only file system' => [
            'ErrorException', 'file_put_contents(/app/storage/x.log): Failed to open stream: Read-only file system', Classification::APP_WRITE_ATTEMPT,
        ];
        yield 'mkdir on a read-only file system' => [
            'ErrorException', 'mkdir(): Read-only file system', Classification::APP_WRITE_ATTEMPT,
        ];
        yield 'invalid signature' => [
            'Illuminate\\Routing\\Exceptions\\InvalidSignatureException', 'Invalid signature.', Classification::SIGNATURE_REQUIRED,
        ];
        yield 'request timeout' => [
            'PeakSim\\Runtime\\Run\\RequestTimeout', 'the request ran longer than 20 seconds', Classification::TIMEOUT,
        ];
        yield 'plain exception' => [
            'LogicException', 'something broke', Classification::EXCEPTION,
        ];
    }

    #[DataProvider('throwableTable')]
    public function testThrowableMapsToClass(string $class, string $message, string $expected): void
    {
        self::assertSame($expected, Classification::fromThrowable($class, $message));
    }

    public function testDecideOkWhenNothingWentWrong(): void
    {
        $result = Classification::decide(200, null, null, [], null);
        self::assertSame(Classification::OK, $result->class);
        self::assertNull($result->detail);
        self::assertTrue($result->isMeasured());
    }

    public function testARedirectToTheLoginPageIsUnauthenticatedNotOk(): void
    {
        $result = Classification::decide(302, null, null, [], null, true);
        self::assertSame(Classification::UNAUTHENTICATED, $result->class);
        self::assertSame('redirected to the login page', $result->detail);
        self::assertFalse($result->isMeasured());
    }

    public function testAnyOtherRedirectStaysMeasured(): void
    {
        self::assertSame(Classification::OK, Classification::decide(302, null, null, [], null, false)->class);
    }

    public function testTheLoginRedirectFlagDoesNotTurnA200IntoUnauthenticated(): void
    {
        self::assertSame(Classification::OK, Classification::decide(200, null, null, [], null, true)->class);
    }

    public function testDecideUsesStatusWhenThereIsNoException(): void
    {
        $result = Classification::decide(404, null, null, [], null);
        self::assertSame(Classification::NOT_FOUND, $result->class);
        self::assertFalse($result->isMeasured());
    }

    public function testDecideRecordsTheExceptionOfA500(): void
    {
        $result = Classification::decide(500, 'LogicException', 'something broke', [], null);
        self::assertSame(Classification::EXCEPTION, $result->class);
        self::assertSame('LogicException: something broke', $result->detail);
    }

    public function testTheNoteOfAFailedAnswerBecomesItsDetail(): void
    {
        $result = Classification::decide(422, null, null, [], null, false, 'The name field is required. | fields: name');
        self::assertSame(Classification::VALIDATION_422, $result->class);
        self::assertSame('The name field is required. | fields: name', $result->detail);
    }

    public function testTheNoteIsNotAddedToAnAnswerThatWasMeasured(): void
    {
        self::assertNull(Classification::decide(200, null, null, [], null, false, 'left over')->detail);
    }

    public function testAnExceptionDetailIsKeptOverTheNote(): void
    {
        $result = Classification::decide(500, 'LogicException', 'boom', [], null, false, 'a note');
        self::assertSame('LogicException: boom', $result->detail);
    }

    public function testExceptionDetailIsCutAt200Characters(): void
    {
        $result = Classification::decide(500, 'LogicException', str_repeat('x', 500), [], null);
        self::assertSame(200, mb_strlen((string) $result->detail));
    }

    public function testExternalCallWinsEvenWhenTheAppSwallowedTheFailureAndAnswered200(): void
    {
        $result = Classification::decide(200, null, null, ['api.example.test', 'api.example.test', 'other.example.test'], null);
        self::assertSame(Classification::EXTERNAL_CALL_BLOCKED, $result->class);
        self::assertSame('hosts: api.example.test, other.example.test', $result->detail);
        self::assertFalse($result->isMeasured());
    }

    public function testRollbackProblemWinsOverEverything(): void
    {
        $result = Classification::decide(200, null, null, ['api.example.test'], 'ddl: ALTER TABLE x');
        self::assertSame(Classification::NOT_ROLLBACKABLE, $result->class);
        self::assertSame('ddl: ALTER TABLE x', $result->detail);
    }

    public function testRollbackProblemWinsOverA500(): void
    {
        $result = Classification::decide(500, 'LogicException', 'x', [], 'row_count_changed: orders 3 -> 4');
        self::assertSame(Classification::NOT_ROLLBACKABLE, $result->class);
    }

    public function testAnExceptionWithoutStatusIsAnException(): void
    {
        $result = Classification::decide(null, 'LogicException', 'boom', [], null);
        self::assertSame(Classification::EXCEPTION, $result->class);
    }

    public function testNoStatusAndNoExceptionIsUnexpected(): void
    {
        $result = Classification::decide(null, null, null, [], null);
        self::assertSame(Classification::UNEXPECTED_STATUS, $result->class);
    }

    public function testAppWriteAttemptFromTheExceptionBeats500(): void
    {
        $result = Classification::decide(500, 'ErrorException', 'mkdir(): Read-only file system', [], null);
        self::assertSame(Classification::APP_WRITE_ATTEMPT, $result->class);
    }

    public function testSignatureRequiredFrom403(): void
    {
        $result = Classification::decide(403, 'Illuminate\\Routing\\Exceptions\\InvalidSignatureException', 'Invalid signature.', [], null);
        self::assertSame(Classification::SIGNATURE_REQUIRED, $result->class);
    }

    public function testOnlyOkIsMeasuredAndTheVocabularyIsClosed(): void
    {
        foreach (Classification::ALL as $class) {
            self::assertSame($class === Classification::OK, Classification::isMeasured($class), $class);
        }
        self::assertContains(Classification::UNFILLED_PARAM, Classification::ALL);
        self::assertContains(Classification::ACTION_NOT_DEFINED, Classification::ALL);
        self::assertSame('action_not_defined', Classification::ACTION_NOT_DEFINED);
        self::assertSame(count(Classification::ALL), count(array_unique(Classification::ALL)));
    }
}
