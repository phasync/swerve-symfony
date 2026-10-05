<?php

/*
 * The tests run the Symfony application in tests/Fixtures/app (made by tests/create-app.sh) on
 * a real swerve, the way users run it. SWERVE_PHP_ARGS adds PHP options, such as loading
 * phasync-ext: CI runs the suite without and with it.
 */

/** A free port from 18760 to 18799. */
function free_port(): int
{
    static $next = 18760;
    for ($tries = 0; $tries < 40; ++$tries, $next = 18760 + ($next - 18759) % 40) {
        if ($socket = @\stream_socket_server("tcp://127.0.0.1:$next")) {
            \fclose($socket);

            return $next++;
        }
    }
    throw new RuntimeException('No free port from 18760 to 18799');
}

/**
 * Start swerve on a free port with the fixture application and wait until it answers.
 *
 * @return array{0: resource, 1: string, 2: string} the process, its address, its log file
 */
function app_start(int $workers = 2, array $env = []): array
{
    $addr     = '127.0.0.1:' . free_port();
    $log      = \tempnam(\sys_get_temp_dir(), 'swerve-log');
    $app      = __DIR__ . '/Fixtures/app';
    $php      = \trim((string) \getenv('SWERVE_PHP_ARGS'));
    $cmd      = 'exec ' . \PHP_BINARY . " $php " . \escapeshellarg("$app/vendor/bin/swerve") . " --workers=$workers --grace=2 --http=$addr --log=" . \escapeshellarg($log);
    $proc     = \proc_open($cmd, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $app, $env + \getenv());
    $deadline = \microtime(true) + 30;
    $curl     = \curl_init("http://$addr/json");
    \curl_setopt_array($curl, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_TIMEOUT => 1]);
    while (false === \curl_exec($curl)) {
        if (\microtime(true) > $deadline) {
            throw new RuntimeException("swerve did not start:\n" . \file_get_contents($log));
        }
        \usleep(100_000);
    }

    return [$proc, $addr, $log];
}

/** Stop swerve as SIGTERM does (a graceful drain), and return its exit code. */
function app_stop($proc): int
{
    \proc_terminate($proc, \SIGTERM);
    $deadline = \microtime(true) + 10;
    while (\proc_get_status($proc)['running'] && \microtime(true) < $deadline) {
        \usleep(50_000);
    }

    return \proc_close($proc);
}

/** The address of a swerve with 2 workers, shared by the tests that don't need their own. */
function app(): string
{
    static $app;
    if (null === $app) {
        $app = app_start();
        \register_shutdown_function(static fn () => app_stop($app[0]));
    }

    return $app[1];
}

/**
 * An HTTP request with curl; a $jar file keeps cookies between requests.
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function http(string $addr, string $method, string $path, array $options = [], ?string $jar = null): array
{
    $curl = \curl_init("http://$addr$path");
    \curl_setopt_array($curl, http_options($method, $options, $jar));
    $body = \curl_exec($curl);

    return http_result($curl, $body);
}

/** Several requests at once; each is [method, path, options, jar]. */
function http_all(string $addr, array $requests): array
{
    $multi   = \curl_multi_init();
    $handles = [];
    foreach ($requests as $key => [$method, $path, $options, $jar]) {
        $handles[$key] = \curl_init("http://$addr$path");
        \curl_setopt_array($handles[$key], http_options($method, $options, $jar) + [\CURLOPT_FORBID_REUSE => true]);
        \curl_multi_add_handle($multi, $handles[$key]);
    }
    do {
        \curl_multi_exec($multi, $running);
        \curl_multi_select($multi, 0.05);
    } while ($running > 0);

    return \array_map(static fn ($curl) => http_result($curl, \curl_multi_getcontent($curl)), $handles);
}

function http_options(string $method, array $options, ?string $jar): array
{
    return $options + [
        \CURLOPT_CUSTOMREQUEST  => $method,
        \CURLOPT_NOBODY         => 'HEAD' === $method,
        \CURLOPT_RETURNTRANSFER => true,
        \CURLOPT_HEADER         => true,
        \CURLOPT_TIMEOUT        => 20,
        \CURLOPT_HTTPHEADER     => ['Expect:'],
    ] + ($jar ? [\CURLOPT_COOKIEFILE => $jar, \CURLOPT_COOKIEJAR => $jar] : []);
}

function http_result(CurlHandle $curl, string|false $response): array
{
    if (false === $response) {
        throw new RuntimeException(\curl_error($curl));
    }
    $size    = \curl_getinfo($curl, \CURLINFO_HEADER_SIZE);
    $headers = [];
    foreach (\explode("\r\n", \substr($response, 0, $size)) as $line) {
        if (\str_contains($line, ':')) {
            [$name, $value]                   = \explode(':', $line, 2);
            $headers[\strtolower($name)][]    = \trim($value);
        }
    }

    return ['status' => \curl_getinfo($curl, \CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => \substr($response, $size)];
}

/**
 * A WebSocket to $path, after the 101; $headers such as a Cookie go with the handshake.
 *
 * @return resource
 */
function ws_connect(string $addr, string $path = '/ws', array $headers = [])
{
    [$host, $port] = \explode(':', $addr);
    $conn          = \stream_socket_client("tcp://$host:$port", timeout: 5);
    \stream_set_timeout($conn, 5);
    $key = \base64_encode(\random_bytes(16));
    \fwrite($conn, "GET $path HTTP/1.1\r\nHost: $host\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n" . \implode('', \array_map(static fn ($h) => "$h\r\n", $headers)) . "\r\n");
    $head = '';
    while (!\str_ends_with($head, "\r\n\r\n") && '' !== ($byte = (string) \fread($conn, 1))) {
        $head .= $byte;
    }
    expect($head)->toStartWith('HTTP/1.1 101')
        ->and($head)->toContain('Sec-WebSocket-Accept: ' . \base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)));

    return $conn;
}

/** Send one frame, masked as a client must. */
function ws_send($conn, int $opcode, string $payload): void
{
    $n    = \strlen($payload);
    $mask = \random_bytes(4);
    $head = \chr(0x80 | $opcode) . match (true) {
        $n < 126   => \chr(0x80 | $n),
        $n < 65536 => \chr(0x80 | 126) . \pack('n', $n),
        default    => \chr(0x80 | 127) . \pack('J', $n),
    };
    \fwrite($conn, $head . $mask . ($payload ^ \substr(\str_repeat($mask, \intdiv($n, 4) + 1), 0, $n)));
}

/**
 * The next frame from the server, as [opcode, payload], or null when the connection ended.
 *
 * @return array{0: int, 1: string}|null
 */
function ws_read($conn): ?array
{
    $read = static function (int $n) use ($conn): ?string {
        for ($bytes = ''; \strlen($bytes) < $n; $bytes .= $chunk) {
            if ('' === ($chunk = (string) \fread($conn, $n - \strlen($bytes)))) {
                return null;
            }
        }

        return $bytes;
    };
    if (null === ($head = $read(2))) {
        return null;
    }
    $length = \ord($head[1]) & 0x7F;
    if (126 === $length) {
        $length = \unpack('n', $read(2))[1];
    } elseif (127 === $length) {
        $length = \unpack('J', $read(8))[1];
    }

    return [\ord($head[0]) & 0x0F, $length > 0 ? $read($length) : ''];
}

/**
 * The WebSocket callbacks running in each worker, by pid, from /ws-live: asked until $workers
 * workers answered.
 *
 * @return array<int, int>
 */
function ws_live(string $addr, int $workers = 2): array
{
    $live = [];
    for ($i = 0; $i < 100 && \count($live) < $workers; ++$i) {
        $data               = \json_decode(http($addr, 'GET', '/ws-live', [\CURLOPT_FORBID_REUSE => true])['body'], true);
        $live[$data['pid']] = $data['live'];
    }

    return $live;
}
