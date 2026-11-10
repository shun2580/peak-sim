<?php

declare(strict_types=1);

/*
 * A minimal FastCGI client: sends one GET to php-fpm and prints the body of the answer. It exists so that a value can be read
 * "through php-fpm" (what the app's requests see) and not from the command line, where php -i shows the CLI's own settings.
 * Runs in the lab's tools container, which reaches the app container on the internal network.
 *
 *   php fcgi_get.php <host:port> <script path inside the app container> [query string]
 *
 * Reads and writes nothing else. Exit codes: 0 answered / 2 usage / 3 no connection or no answer.
 */

if ($argc < 3) {
    fwrite(STDERR, "usage: fcgi_get.php host:port /script.php [query]\n");
    exit(2);
}
[$host, $port] = explode(':', $argv[1]) + [1 => '9000'];
$script = $argv[2];
$query = $argv[3] ?? '';

$record = static function (int $type, string $content): string {
    return pack('CCnnCC', 1, $type, 1, strlen($content), 0, 0) . $content;
};
$pair = static function (string $k, string $v): string {
    $len = static fn (string $s): string => strlen($s) < 128 ? chr(strlen($s)) : pack('N', strlen($s) | 0x80000000);

    return $len($k) . $len($v) . $k . $v;
};

$params = '';
foreach ([
    'GATEWAY_INTERFACE' => 'CGI/1.1', 'REQUEST_METHOD' => 'GET', 'SCRIPT_FILENAME' => $script, 'SCRIPT_NAME' => '/probe',
    'QUERY_STRING' => $query, 'REQUEST_URI' => '/probe' . ($query === '' ? '' : '?' . $query), 'SERVER_PROTOCOL' => 'HTTP/1.1',
    'SERVER_NAME' => 'probe', 'SERVER_PORT' => '80', 'REMOTE_ADDR' => '127.0.0.1', 'CONTENT_LENGTH' => '0',
] as $k => $v) {
    $params .= $pair($k, (string) $v);
}

$socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 5);
if ($socket === false) {
    fwrite(STDERR, "cannot connect: $errstr\n");
    exit(3);
}
stream_set_timeout($socket, 1200);
fwrite($socket, $record(1, pack('nCx5', 1, 0)) . $record(4, $params) . $record(4, '') . $record(5, ''));

$out = '';
$err = '';
while (!feof($socket)) {
    $head = '';
    while (strlen($head) < 8 && !feof($socket)) {
        $chunk = fread($socket, 8 - strlen($head));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $head .= $chunk;
    }
    if (strlen($head) < 8) {
        break;
    }
    $h = unpack('Cversion/Ctype/nid/nlen/Cpad/Creserved', $head);
    $body = '';
    while (strlen($body) < $h['len']) {
        $chunk = fread($socket, $h['len'] - strlen($body));
        if ($chunk === false || $chunk === '') {
            break 2;
        }
        $body .= $chunk;
    }
    if ($h['pad'] > 0) {
        fread($socket, $h['pad']);
    }
    if ($h['type'] === 6) {
        $out .= $body;
    } elseif ($h['type'] === 7) {
        $err .= $body;
    } elseif ($h['type'] === 3) {
        break;
    }
}
fclose($socket);
if ($out === '' && $err === '') {
    fwrite(STDERR, "no answer\n");
    exit(3);
}
$parts = explode("\r\n\r\n", $out, 2);
echo $parts[1] ?? $parts[0];
if ($err !== '') {
    fwrite(STDERR, $err);
}
exit(0);
