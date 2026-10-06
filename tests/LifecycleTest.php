<?php

/*
 * A worker's life: a drain that lets a request in flight finish, memory over many requests, and
 * the dev environment with its debug mode.
 */

it('finishes a slow request on SIGTERM, and logs no error', function () {
    [$proc, $addr, $log] = app_start(1);
    [$host, $port]       = \explode(':', $addr);
    $socket              = \stream_socket_client("tcp://$host:$port", timeout: 5);
    \fwrite($socket, "GET /slow?sleep=1 HTTP/1.1\r\nHost: $host\r\n\r\n");
    \usleep(300_000);
    \proc_terminate($proc, \SIGTERM);
    $response = \stream_get_contents($socket);
    \fclose($socket);
    // The exit code as proc_get_status() saw it: before PHP 8.3, proc_close() then returns -1
    for ($deadline = \microtime(true) + 5; ($status = \proc_get_status($proc))['running'] && \microtime(true) < $deadline;) {
        \usleep(50_000);
    }
    \proc_close($proc);

    expect($response)->toStartWith('HTTP/1.1 200')
        ->and($response)->toEndWith('slow done')
        ->and($status['exitcode'])->toBe(0)
        ->and(\preg_grep('/error|exception|warning|fatal/i', \file($log)))->toBe([]);
});

it('keeps memory flat over 10,000 requests, once warm', function () {
    [$proc, $addr] = app_start(1);
    $curl          = \curl_init();
    \curl_setopt($curl, \CURLOPT_RETURNTRANSFER, true);
    $get = static function (string $path) use ($curl, $addr) {
        \curl_setopt($curl, \CURLOPT_URL, "http://$addr$path");

        return \curl_exec($curl);
    };
    // The skeleton's page, a JSON route, and now and then a session written to SQLite
    $paths = static fn (int $i) => 0 === $i % 20 ? '/counter' : (0 === $i % 2 ? '/json' : '/');
    for ($i = 0; $i < 1_000; ++$i) {
        $get($paths($i));
    }
    // Three windows of 10,000. A leak grows in every window; a slow warm-up grows in the first, and
    // an array reaching a new peak size (PHP never shrinks one) grows now and then, in power-of-two
    // steps, on a slow machine. So one of the later windows must stay flat.
    $growth = [];
    for ($window = 0; $window < 3; ++$window) {
        $before = \json_decode($get('/memory'), true)['memory'];
        for ($i = 0; $i < 10_000; ++$i) {
            $get($paths($i));
        }
        $growth[] = \json_decode($get('/memory'), true)['memory'] - $before;
    }
    app_stop($proc);

    expect(\min($growth[1], $growth[2]))->toBeLessThan(200_000, 'growth per window: ' . \implode(', ', $growth));
});

it('runs in the dev environment, with debug on', function () {
    [$proc, $addr] = app_start(1, ['APP_ENV' => 'dev', 'APP_DEBUG' => '1']);
    $home          = http($addr, 'GET', '/');
    $missing       = http($addr, 'GET', '/missing');
    app_stop($proc);

    expect($home['status'])->toBe(200)
        ->and($missing['status'])->toBe(404)
        ->and($missing['body'])->toContain('<title>No route found for &quot;GET');
});
