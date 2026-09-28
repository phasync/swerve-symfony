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
    for ($deadline = \microtime(true) + 5; \proc_get_status($proc)['running'] && \microtime(true) < $deadline;) {
        \usleep(50_000);
    }

    expect($response)->toStartWith('HTTP/1.1 200')
        ->and($response)->toEndWith('slow done')
        ->and(\proc_close($proc))->toBe(0)
        ->and(\preg_grep('/error|exception|warning|fatal/i', \file($log)))->toBe([]);
});

it('keeps memory flat over 10,000 requests', function () {
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
    $before = \json_decode($get('/memory'), true)['memory'];
    for ($i = 0; $i < 10_000; ++$i) {
        $get($paths($i));
    }
    $after = \json_decode($get('/memory'), true)['memory'];
    app_stop($proc);

    expect($after - $before)->toBeLessThan(200_000);
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
