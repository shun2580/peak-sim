<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PeakSim\Runtime\LabLoad\LabSettings;
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

    private static function ini(string $maxExecutionTime, string $sapi = 'fpm-fcgi'): string
    {
        return json_encode(['sapi' => $sapi, 'php' => '8.3.36', 'max_execution_time' => $maxExecutionTime, 'memory_limit' => '512M']) . "\n";
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
}
