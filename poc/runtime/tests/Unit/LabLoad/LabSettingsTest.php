<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PeakSim\Runtime\LabLoad\LabSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LabSettingsTest extends TestCase
{
    private const NGINX = <<<'TXT'
        nginx: the configuration file /etc/nginx/nginx.conf syntax is ok
        nginx: configuration file /etc/nginx/nginx.conf test is successful
        # configuration file /etc/nginx/nginx.conf:
        http {
            keepalive_timeout 65;
            server {
                listen 8080;
                location / {
                    # fastcgi_read_timeout 30s;
                    fastcgi_pass lab_app;
                    fastcgi_read_timeout 120s;
                }
            }
        }
        TXT;

    private const FPM = <<<'TXT'
        [10-Nov-2026 10:39:21] NOTICE: 	process_control_timeout = 0s
        [10-Nov-2026 10:39:21] NOTICE: 	request_slowlog_timeout = 0s
        [10-Nov-2026 10:39:21] NOTICE: 	request_terminate_timeout = 0s
        [10-Nov-2026 10:39:21] NOTICE: 	request_terminate_timeout_track_finished = no
        TXT;

    private static function ini(string $maxExecutionTime, string $sapi = 'fpm-fcgi', string $memoryLimit = '512M'): string
    {
        return json_encode(['sapi' => $sapi, 'php' => '8.3.36', 'max_execution_time' => $maxExecutionTime, 'memory_limit' => $memoryLimit]) . "\n";
    }

    public function test_it_reads_the_three_limits_and_the_pool_setting_from_what_the_containers_printed(): void
    {
        $s = LabSettings::fromReadings(self::NGINX, self::FPM, self::ini('30'), null);

        $this->assertSame('30', $s['php_max_execution_time']);
        $this->assertSame('120s', $s['nginx_fastcgi_read_timeout']);
        $this->assertSame('0s', $s['php_fpm_request_terminate_timeout']);
        $this->assertNull($s['k6_request_timeout']);
        $this->assertStringContainsString('default', $s['k6_request_timeout_note']);
    }

    public function test_the_unlimited_re_check_setting_reads_as_0_and_600_seconds(): void
    {
        $nginx = str_replace('fastcgi_read_timeout 120s;', 'fastcgi_read_timeout 600s;', self::NGINX);
        $s = LabSettings::fromReadings($nginx, self::FPM, self::ini('0'), '600s');

        $this->assertSame('0', $s['php_max_execution_time']);
        $this->assertSame('600s', $s['nginx_fastcgi_read_timeout']);
        $this->assertSame('600s', $s['k6_request_timeout']);
        $this->assertStringContainsString('REQUEST_TIMEOUT', $s['k6_request_timeout_note']);
    }

    public function test_a_commented_out_line_is_not_a_setting(): void
    {
        // the only active line says 120s; the comment says 30s
        $this->assertSame('120s', LabSettings::fromReadings(self::NGINX, self::FPM, self::ini('30'), null)['nginx_fastcgi_read_timeout']);
        $onlyComment = "http {\n    # fastcgi_read_timeout 30s;\n}\n";
        $this->expectException(\InvalidArgumentException::class);
        LabSettings::fromReadings($onlyComment, self::FPM, self::ini('30'), null);
    }

    public function test_the_track_finished_line_is_not_taken_for_the_terminate_timeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LabSettings::fromReadings(self::NGINX, "NOTICE: \trequest_terminate_timeout_track_finished = no\n", self::ini('30'), null);
    }

    public function test_two_different_values_are_refused_rather_than_one_picked(): void
    {
        $two = str_replace("fastcgi_pass lab_app;\n", "fastcgi_pass lab_app;\n            fastcgi_read_timeout 60s;\n", self::NGINX);
        try {
            LabSettings::fromReadings($two, self::FPM, self::ini('30'), null);
            $this->fail('expected a refusal for two nginx values');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('more than one value', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        LabSettings::fromReadings(self::NGINX, self::FPM . "\nNOTICE: \trequest_terminate_timeout = 30s\n", self::ini('30'), null);
    }

    public function test_a_value_that_cannot_be_read_stops_the_step(): void
    {
        foreach ([
            ['nginx: [emerg] no such file', self::FPM, self::ini('30')],
            [self::NGINX, 'ERROR: unable to read the configuration', self::ini('30')],
            [self::NGINX, self::FPM, ''],
            [self::NGINX, self::FPM, 'cannot connect: Connection refused'],
            [self::NGINX, self::FPM, '{"sapi":"fpm-fcgi"}'],
        ] as $i => [$nginx, $fpm, $ini]) {
            try {
                LabSettings::fromReadings($nginx, $fpm, $ini, null);
                $this->fail("case $i: expected a refusal");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_reading_that_did_not_come_through_php_fpm_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fpm-fcgi');
        LabSettings::fromReadings(self::NGINX, self::FPM, self::ini('30', 'cli'), null);
    }

    public function test_a_header_line_before_the_json_body_is_tolerated(): void
    {
        $s = LabSettings::fromReadings(self::NGINX, self::FPM, "Content-Type: application/json\n\n" . self::ini('0'), null);
        $this->assertSame('0', $s['php_max_execution_time']);
    }

    /** @return array<string, mixed> the readings of a lab set as the re-check (B) needs it */
    private static function unlimited(): array
    {
        $nginx = str_replace('fastcgi_read_timeout 120s;', 'fastcgi_read_timeout 600s;', self::NGINX);

        return LabSettings::fromReadings($nginx, self::FPM, self::ini('0', 'fpm-fcgi', '4G'), '600s');
    }

    public function test_the_lab_set_for_b_passes_the_unlimited_guard(): void
    {
        $this->assertSame([], LabSettings::violations(self::unlimited(), LabSettings::UNLIMITED, '600s'));
        // nginx waiting longer than k6, and a different unit of the same time, are both enough
        $longer = ['nginx_fastcgi_read_timeout' => '1h'] + self::unlimited();
        $this->assertSame([], LabSettings::violations($longer, LabSettings::UNLIMITED, '600s'));
        $minutes = ['nginx_fastcgi_read_timeout' => '10m'] + self::unlimited();
        $this->assertSame([], LabSettings::violations($minutes, LabSettings::UNLIMITED, '600s'));
    }

    public function test_the_default_lab_left_with_the_limit_on_is_refused_by_the_unlimited_guard(): void
    {
        $default = LabSettings::fromReadings(self::NGINX, self::FPM, self::ini('30'), '600s');
        $found = LabSettings::violations($default, LabSettings::UNLIMITED, '600s');

        $this->assertCount(3, $found);   // max_execution_time 30, memory_limit 512M (B needs 4G), and nginx 120s < 600s; the pool's 0s is right
        $this->assertStringContainsString('max_execution_time', $found[0]);
        $this->assertStringContainsString('memory_limit', $found[1]);
        $this->assertStringContainsString('fastcgi_read_timeout', $found[2]);
    }

    #[DataProvider('oneThingWrong')]
    public function test_each_limit_is_checked_on_its_own(string $key, string $value, string $mentioned): void
    {
        $found = LabSettings::violations([$key => $value] + self::unlimited(), LabSettings::UNLIMITED, '600s');
        $this->assertCount(1, $found, implode(' | ', $found));
        $this->assertStringContainsString($mentioned, $found[0]);
    }

    /** @return array<string, array{string, string, string}> */
    public static function oneThingWrong(): array
    {
        return [
            'php limit 30' => ['php_max_execution_time', '30', 'max_execution_time'],
            'php limit 1' => ['php_max_execution_time', '1', 'max_execution_time'],
            'pool limit 300s' => ['php_fpm_request_terminate_timeout', '300s', 'request_terminate_timeout'],
            'pool limit 1' => ['php_fpm_request_terminate_timeout', '1', 'request_terminate_timeout'],
            'nginx 599s is one second short' => ['nginx_fastcgi_read_timeout', '599s', 'fastcgi_read_timeout'],
            'nginx 9m is shorter than 600s' => ['nginx_fastcgi_read_timeout', '9m', 'fastcgi_read_timeout'],
            'memory limit left at 512M' => ['php_memory_limit', '512M', 'memory_limit'],
            'memory limit 2G is not 4G' => ['php_memory_limit', '2G', 'memory_limit'],
            'memory limit 8G is not 4G' => ['php_memory_limit', '8G', 'memory_limit'],
            'memory limit -1 (no limit) is not 4G' => ['php_memory_limit', '-1', 'memory_limit'],
        ];
    }

    #[DataProvider('unreadableValues')]
    public function test_a_value_that_is_not_a_duration_is_a_violation_not_a_pass(string $key, mixed $value): void
    {
        $settings = [$key => $value] + self::unlimited();
        $this->assertNotSame([], LabSettings::violations($settings, LabSettings::UNLIMITED, '600s'));
    }

    /** @return array<string, array{string, mixed}> */
    public static function unreadableValues(): array
    {
        return [
            'php limit empty' => ['php_max_execution_time', ''],
            'php limit text' => ['php_max_execution_time', 'unlimited'],
            'php limit negative' => ['php_max_execution_time', '-1'],
            'pool text' => ['php_fpm_request_terminate_timeout', 'off'],
            'nginx text' => ['nginx_fastcgi_read_timeout', 'forever'],
            'nginx combined units' => ['nginx_fastcgi_read_timeout', '10m 30s'],
            'nginx null' => ['nginx_fastcgi_read_timeout', null],
            'memory text' => ['php_memory_limit', 'unlimited'],
            'memory empty' => ['php_memory_limit', ''],
            'memory null' => ['php_memory_limit', null],
        ];
    }

    public function test_a_missing_reading_is_a_violation(): void
    {
        foreach (['php_max_execution_time', 'php_memory_limit', 'php_fpm_request_terminate_timeout', 'nginx_fastcgi_read_timeout'] as $key) {
            $settings = self::unlimited();
            unset($settings[$key]);
            $this->assertNotSame([], LabSettings::violations($settings, LabSettings::UNLIMITED, '600s'), "without $key");
            $this->assertNotSame([], LabSettings::violations($settings, LabSettings::LIMITED, '600s'), "limited, without $key (only php counts there)");
        }
    }

    public function test_the_unlimited_guard_needs_the_load_tools_timeout_to_compare_with(): void
    {
        $this->assertNotSame([], LabSettings::violations(self::unlimited(), LabSettings::UNLIMITED, null));
        $this->assertNotSame([], LabSettings::violations(self::unlimited(), LabSettings::UNLIMITED, 'soon'));
    }

    public function test_the_limited_guard_wants_30_and_nothing_else(): void
    {
        $default = LabSettings::fromReadings(self::NGINX, self::FPM, self::ini('30'), null);
        $this->assertSame([], LabSettings::violations($default, LabSettings::LIMITED, null));

        foreach (['0', '29', '31', '60', '', 'x'] as $value) {
            $settings = LabSettings::fromReadings(self::NGINX, self::FPM, self::ini($value), null);
            $found = LabSettings::violations($settings, LabSettings::LIMITED, null);
            $this->assertCount(1, $found, "max_execution_time '$value'");
            $this->assertStringContainsString('max_execution_time', $found[0]);
        }
        // the lab left with the limit off (B's setting) is not the mark's setting
        $this->assertNotSame([], LabSettings::violations(self::unlimited(), LabSettings::LIMITED, null));
    }

    public function test_the_two_guards_never_both_pass_on_the_same_lab(): void
    {
        foreach ([['0', '4G'], ['30', '512M']] as [$php, $memory]) {
            $nginx = str_replace('fastcgi_read_timeout 120s;', 'fastcgi_read_timeout 600s;', self::NGINX);
            $s = LabSettings::fromReadings($nginx, self::FPM, self::ini($php, 'fpm-fcgi', $memory), '600s');
            $unlimited = LabSettings::violations($s, LabSettings::UNLIMITED, '600s') === [];
            $limited = LabSettings::violations($s, LabSettings::LIMITED, '600s') === [];
            $this->assertNotSame($unlimited, $limited, "max_execution_time $php, memory_limit $memory");
        }
    }

    public function test_the_memory_limit_is_read_from_the_probe_and_4g_is_4g_in_any_spelling(): void
    {
        $this->assertSame('4G', LabSettings::fromReadings(self::NGINX, self::FPM, self::ini('0', 'fpm-fcgi', '4G'), null)['php_memory_limit']);
        foreach (['4G', '4g', '4096M', '4194304K', '4294967296'] as $spelling) {
            $settings = ['php_memory_limit' => $spelling] + self::unlimited();
            $this->assertSame([], LabSettings::violations($settings, LabSettings::UNLIMITED, '600s'), $spelling);
        }
        foreach (['4095M', '4097M', '4G ', '4GB'] as $spelling) {
            $settings = ['php_memory_limit' => $spelling] + self::unlimited();
            $found = LabSettings::violations($settings, LabSettings::UNLIMITED, '600s');
            // '4G ' is trimmed and so is 4G; the others are not 4G
            $this->assertCount($spelling === '4G ' ? 0 : 1, $found, $spelling);
        }
    }

    public function test_a_probe_without_a_memory_limit_is_recorded_as_null_and_refused_by_both_guards(): void
    {
        $probe = json_encode(['sapi' => 'fpm-fcgi', 'max_execution_time' => '0']) . "\n";
        $nginx = str_replace('fastcgi_read_timeout 120s;', 'fastcgi_read_timeout 600s;', self::NGINX);
        $s = LabSettings::fromReadings($nginx, self::FPM, $probe, '600s');
        $this->assertNull($s['php_memory_limit']);
        $this->assertStringContainsString('memory_limit', implode(' | ', LabSettings::violations($s, LabSettings::UNLIMITED, '600s')));
        $thirty = LabSettings::fromReadings(self::NGINX, self::FPM, json_encode(['sapi' => 'fpm-fcgi', 'max_execution_time' => '30']), null);
        $this->assertStringContainsString('memory_limit', implode(' | ', LabSettings::violations($thirty, LabSettings::LIMITED, null)));
    }

    public function test_the_limited_guard_wants_512m_as_well_as_30(): void
    {
        $default = LabSettings::fromReadings(self::NGINX, self::FPM, self::ini('30'), null);
        $this->assertSame([], LabSettings::violations($default, LabSettings::LIMITED, null));
        // the memory limit left at 4G after B is not the mark's setting, even with the time limit back on
        $left = LabSettings::fromReadings(self::NGINX, self::FPM, self::ini('30', 'fpm-fcgi', '4G'), null);
        $found = LabSettings::violations($left, LabSettings::LIMITED, null);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('memory_limit', $found[0]);
        foreach (['256M', '1G', '-1'] as $value) {
            $settings = LabSettings::fromReadings(self::NGINX, self::FPM, self::ini('30', 'fpm-fcgi', $value), null);
            $this->assertCount(1, LabSettings::violations($settings, LabSettings::LIMITED, null), $value);
        }
    }

    public function test_an_unknown_requirement_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LabSettings::violations(self::unlimited(), 'whatever', '600s');
    }
}
