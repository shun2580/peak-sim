<?php

declare(strict_types=1);

/*
 * Read through php-fpm (poc/lab/diagnosis/fcgi_get.php), never by nginx: it prints the settings that decide when php-fpm cuts a
 * request, as the app's own requests see them. It opens no database connection and reads and writes nothing.
 *
 *   (no query)      the settings, as JSON
 *   mode=sleep      sets max_execution_time to 2 seconds, then waits 4 seconds (no CPU used): does the limit count waiting?
 *   mode=spin       sets max_execution_time to 2 seconds, then burns CPU: does the limit count CPU time?
 *
 * The last two show, with a real output, whether php-fpm's PHP counts the time the process spends waiting. They end in the
 * PHP fatal error "Maximum execution time of 2 seconds exceeded" when the limit counted that kind of time.
 */

header('Content-Type: application/json');
$mode = $_GET['mode'] ?? '';
if ($mode === 'sleep' || $mode === 'spin') {
    ini_set('max_execution_time', '2');
    $t0 = microtime(true);
    $c0 = getrusage();
    register_shutdown_function(static function () use ($mode, $t0, $c0): void {
        $c1 = getrusage();
        $cpu = ($c1['ru_utime.tv_sec'] - $c0['ru_utime.tv_sec']) + ($c1['ru_utime.tv_usec'] - $c0['ru_utime.tv_usec']) / 1e6
            + ($c1['ru_stime.tv_sec'] - $c0['ru_stime.tv_sec']) + ($c1['ru_stime.tv_usec'] - $c0['ru_stime.tv_usec']) / 1e6;
        echo "\n" . json_encode(['mode' => $mode, 'ended_by_the_limit' => (error_get_last()['message'] ?? '') !== '' && str_contains((string) (error_get_last()['message'] ?? ''), 'Maximum execution time'), 'wall_s' => round(microtime(true) - $t0, 2), 'cpu_s' => round($cpu, 2)]);
    });
    if ($mode === 'sleep') {
        sleep(4);
        echo json_encode(['mode' => 'sleep', 'finished_the_sleep' => true]);
    } else {
        $end = microtime(true) + 6;
        while (microtime(true) < $end) {
            // burn CPU
        }
        echo json_encode(['mode' => 'spin', 'finished_the_loop' => true]);
    }
    exit;
}

echo json_encode([
    'sapi' => PHP_SAPI,
    'php' => PHP_VERSION,
    'max_execution_time' => ini_get('max_execution_time'),
    'memory_limit' => ini_get('memory_limit'),
    'default_socket_timeout' => ini_get('default_socket_timeout'),
    'ini_files' => php_ini_scanned_files() === false ? null : array_map('trim', explode(',', (string) php_ini_scanned_files())),
], JSON_UNESCAPED_SLASHES);
