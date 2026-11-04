<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PHPUnit\Framework\TestCase;

/**
 * The time one measurement may take is derived from the request limit the run was given. If `bin/inner/measure.php`
 * stopped passing the derived value on (a fixed number instead), a long `--request-timeout` would still be cut short by
 * the process limit and nothing else would notice.
 */
final class MeasureScriptTimeoutWiringTest extends TestCase
{
    private static function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/bin/inner/measure.php');
    }

    public function test_the_limit_of_the_request_is_read_from_the_environment_of_the_run(): void
    {
        self::assertMatchesRegularExpression(
            '/\$requestLimit\s*=\s*Requester::timeLimit\(\$env\[Requester::TIME_LIMIT_ENV\]\s*\?\?\s*\'\'\)\s*;/',
            self::script(),
            'the request limit must come from the run\'s environment',
        );
    }

    public function test_the_runner_gets_the_process_limit_derived_from_that_request_limit(): void
    {
        self::assertMatchesRegularExpression(
            '/new ProcessOneRunner\(\s*\$bin\s*\.\s*\'\/measure-one\'\s*,\s*\$env\s*,\s*ProcessOneRunner::timeoutFor\(\$requestLimit\)\s*\)/',
            self::script(),
            'the runner must be built with timeoutFor($requestLimit) as its time limit',
        );
    }

    public function test_the_process_limit_is_not_a_number_written_in_the_script(): void
    {
        self::assertSame(0, preg_match('/new ProcessOneRunner\([^;]*,\s*\d+\s*\)/', self::script()), 'no fixed number may stand in for the derived limit');
    }
}
