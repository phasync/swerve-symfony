<?php

/*
 * Swerve\Http\WebSocket returned by Symfony controllers (tests/Fixtures/routes/src/Controller/
 * WebSocketController.php): both ways, server push, clients leaving, the user, many sockets
 * beside ordinary requests, a drain, and a refusal.
 */

it('echoes text and binary messages, several in a row', function () {
    $ws = ws_connect(app());
    foreach (['one', 'two', 'three'] as $message) {
        ws_send($ws, 1, $message);
    }
    ws_send($ws, 2, "\x00\xFF\x01");
    $large = \str_repeat('0123456789', 10_000);
    ws_send($ws, 1, $large);

    expect([ws_read($ws), ws_read($ws), ws_read($ws), ws_read($ws), ws_read($ws)])
        ->toBe([[1, 'echo: one'], [1, 'echo: two'], [1, 'echo: three'], [2, "\x00\xFF\x01"], [1, "echo: $large"]]);
    ws_send($ws, 8, \pack('n', 1000));
    expect(ws_read($ws))->toBe([8, \pack('n', 1000)])
        ->and(ws_read($ws))->toBeNull();
});

it('pushes every published message to every client on both workers, in order', function () {
    [$proc, $addr, $log] = app_start(2);
    try {
        $clients = [];
        for ($i = 0; $i < 12; ++$i) {
            $clients[] = ws_connect($addr, '/ws/news');
        }
        $live = ws_live($addr);
        expect($live)->toHaveCount(2)->and(\array_sum($live))->toBe(12)->and(\min($live))->toBeGreaterThan(0);

        // On one connection, so through one worker: messages published through different
        // workers at once can arrive in either order (phasync/swerve#5)
        $publisher = \curl_init("http://$addr/publish");
        \curl_setopt_array($publisher, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_POST => true]);
        for ($n = 1; $n <= 5; ++$n) {
            \curl_setopt($publisher, \CURLOPT_POSTFIELDS, "news $n");
            expect(\curl_exec($publisher))->toBe('published');
        }
        foreach ($clients as $ws) {
            expect([ws_read($ws), ws_read($ws), ws_read($ws), ws_read($ws), ws_read($ws)])
                ->toBe([[1, 'news 1'], [1, 'news 2'], [1, 'news 3'], [1, 'news 4'], [1, 'news 5']]);
            \fclose($ws);
        }
    } finally {
        app_stop($proc);
    }
    expect(\preg_grep('/error|exception|warning|fatal|critical/i', \file($log)))->toBe([]);
});

it('ends every callback when its client leaves, with or without a goodbye', function () {
    [$proc, $addr, $log] = app_start(2);
    try {
        $clients = [];
        for ($i = 0; $i < 16; ++$i) {
            $clients[] = ws_connect($addr, $i < 8 ? '/ws/news' : '/ws');
        }
        expect(\array_sum(ws_live($addr)))->toBe(16);

        // Half of each kind close without a word, half send a close frame
        foreach ($clients as $i => $ws) {
            if ($i % 2) {
                ws_send($ws, 8, \pack('n', 1000));
                expect(ws_read($ws))->toBe([8, \pack('n', 1000)]);
            }
            \fclose($ws);
        }
        for ($deadline = \microtime(true) + 5; \array_sum(ws_live($addr)) > 0 && \microtime(true) < $deadline;) {
            \usleep(50_000);
        }
        expect(ws_live($addr))->toHaveCount(2)->each->toBe(0);
    } finally {
        app_stop($proc);
    }
    expect(\preg_grep('/error|exception|warning|fatal|critical/i', \file($log)))->toBe([]);
});

it('gives each socket the user and session data taken before WebSocket::from()', function () {
    [$proc, $addr] = app_start(1, ['KERNELS' => '1']); // one kernel: every request uses its services
    try {
        $cookies = [];
        foreach (['alice' => 1, 'bob' => 2] as $user => $count) {
            $jar = \tempnam(\sys_get_temp_dir(), 'jar');
            http($addr, 'POST', '/login', [\CURLOPT_POSTFIELDS => "_username=$user&_password=secret"], $jar);
            for ($i = 0; $i < $count; ++$i) {
                http($addr, 'GET', '/counter', [], $jar);
            }
            \preg_match('/PHPSESSID\s+(\S+)/', \file_get_contents($jar), $id);
            $cookies[$user] = "Cookie: PHPSESSID=$id[1]";
        }
        $alice     = ws_connect($addr, '/ws/me', [$cookies['alice']]);
        $bob       = ws_connect($addr, '/ws/me', [$cookies['bob']]);
        $aliceLate = ws_connect($addr, '/ws/me-late', [$cookies['alice']]);
        ws_send($aliceLate, 1, 'who am I?');
        $before = ws_read($aliceLate);

        // Bob's request now uses the kernel alice's handshake used
        expect(\json_decode(http($addr, 'GET', '/me', [\CURLOPT_COOKIE => \substr($cookies['bob'], 8)])['body'], true))->toBe(['user' => 'bob']);
        foreach ([$alice, $bob, $aliceLate] as $ws) {
            ws_send($ws, 1, 'who am I?');
        }

        expect(ws_read($alice))->toBe([1, 'alice (count 1): who am I?'])
            ->and(ws_read($bob))->toBe([1, 'bob (count 2): who am I?'])
            // Read inside the callback, the token storage says whose request the kernel served last
            ->and($before)->toBe([1, 'alice: who am I?'])
            ->and(ws_read($aliceLate))->toBe([1, 'bob: who am I?']);
    } finally {
        app_stop($proc);
    }
});

it('answers ordinary requests promptly beside 200 open sockets per worker, on 2 kernels', function () {
    [$proc, $addr, $log] = app_start(2, ['KERNELS' => '2']);
    try {
        $clients = [];
        for ($i = 0; $i < 600; ++$i) {
            $clients[] = ws_connect($addr, 0 === $i % 2 ? '/ws' : '/ws/news');
        }
        $live = ws_live($addr);
        expect($live)->toHaveCount(2)->and(\array_sum($live))->toBe(600)->and(\min($live))->toBeGreaterThanOrEqual(200);

        // Sockets hold no kernel: requests get one of the 2 at once, also 16 at a time
        $slowest = 0.0;
        $kernels = [];
        for ($i = 0; $i < 50; ++$i) {
            $start                                  = \microtime(true);
            $data                                   = \json_decode(http($addr, 'GET', '/ws-live', [\CURLOPT_FORBID_REUSE => true])['body'], true);
            $slowest                                = \max($slowest, \microtime(true) - $start);
            $kernels[$data['pid']][$data['kernel']] = true;
        }
        $burst = http_all($addr, \array_fill(0, 16, ['GET', '/json', [], null]));
        expect($slowest)->toBeLessThan(0.25)
            ->and(\max(\array_map('count', $kernels)))->toBeLessThanOrEqual(2)
            ->and(\array_column($burst, 'status'))->toBe(\array_fill(0, 16, 200));

        // And the sockets still work, each of them
        http($addr, 'POST', '/publish', [\CURLOPT_POSTFIELDS => 'still pushing']);
        foreach ($clients as $i => $ws) {
            0 === $i % 2 && ws_send($ws, 1, 'still here');
        }
        foreach ($clients as $i => $ws) {
            expect(ws_read($ws))->toBe(0 === $i % 2 ? [1, 'echo: still here'] : [1, 'still pushing']);
            \fclose($ws);
        }
    } finally {
        app_stop($proc);
    }
    expect(\preg_grep('/error|exception|warning|fatal|critical/i', \file($log)))->toBe([]);
});

it('closes open sockets with 1001 on SIGTERM, ends their callbacks, and exits 0', function () {
    [$proc, $addr, $log] = app_start(2);
    $clients             = [];
    for ($i = 0; $i < 8; ++$i) {
        $clients[] = ws_connect($addr, 0 === $i % 2 ? '/ws' : '/ws/news');
    }
    expect(\array_sum(ws_live($addr)))->toBe(8);
    \proc_terminate($proc, \SIGTERM);
    // Each client answers the goodbye and closes, as a browser does
    $frames = \array_map(static function ($ws) {
        $frame = ws_read($ws);
        ws_send($ws, 8, \pack('n', 1001));
        \fclose($ws);

        return $frame;
    }, $clients);
    for ($deadline = \microtime(true) + 5; ($status = \proc_get_status($proc))['running'] && \microtime(true) < $deadline;) {
        \usleep(50_000);
    }
    \proc_close($proc);

    expect($frames)->toBe(\array_fill(0, 8, [8, \pack('n', 1001)]))
        ->and($status['running'])->toBeFalse()
        ->and($status['exitcode'])->toBe(0)
        ->and(\preg_grep('/error|exception|warning|fatal|critical/i', \file($log)))->toBe([]);
});

it('answers an ordinary GET to a WebSocket route with 426', function () {
    $response = http(app(), 'GET', '/ws');

    expect($response['status'])->toBe(426)
        ->and($response['headers']['upgrade'][0])->toBe('websocket')
        ->and($response['body'])->toBe('This address speaks WebSocket');
});
