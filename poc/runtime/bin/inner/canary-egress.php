<?php

declare(strict_types=1);

// Runs INSIDE the PHP container. Tries to leave by every plain-PHP route and records that each attempt fails.
// Exit 0 only if every attempt failed. (The Laravel HTTP client path is faked by the app booter; see the design.)

require dirname(__DIR__, 2) . '/bootstrap.php';

$attempts = [];

$attempts['dns gethostbyname(example.com)'] = static function (): string {
    $ip = gethostbyname('example.com');
    if ($ip === 'example.com') {
        throw new RuntimeException('name did not resolve');
    }

    return 'resolved ' . $ip;
};

$attempts['stream_socket_client(1.1.1.1:443)'] = static function (): string {
    $s = @stream_socket_client('tcp://1.1.1.1:443', $errno, $error, 3);
    if ($s === false) {
        throw new RuntimeException("$error ($errno)");
    }

    return 'connected';
};

$attempts['file_get_contents(http://example.com)'] = static function (): string {
    $body = @file_get_contents('http://example.com/', false, stream_context_create(['http' => ['timeout' => 3]]));
    if ($body === false) {
        throw new RuntimeException('no response');
    }

    return 'got ' . strlen($body) . ' bytes';
};

if (function_exists('curl_init')) {
    $attempts['curl(https://example.com)'] = static function (): string {
        $ch = curl_init('https://example.com/');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $code = curl_errno($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException("$error ($code)");
        }

        return 'got a response';
    };
}

$leaked = 0;
foreach ($attempts as $label => $attempt) {
    try {
        $outcome = $attempt();
        $leaked++;
        printf("LEAK    %-42s %s\n", $label, $outcome);
    } catch (Throwable $e) {
        printf("BLOCKED %-42s %s\n", $label, substr($e->getMessage(), 0, 100));
    }
}

printf("attempts=%d leaked=%d\n", count($attempts), $leaked);
exit($leaked === 0 ? 0 : 1);
