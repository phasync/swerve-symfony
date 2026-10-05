<?php

/*
 * Requests that overlap on one worker each see only their own state: phasync::sleep()
 * lets the others run, as any I/O wait does.
 */

it('keeps 10 overlapping requests apart: request, route, main request, service, user, session', function () {
    [$proc, $addr] = app_start(1);
    $requests      = [];
    for ($i = 0; $i < 10; ++$i) {
        $jar = \tempnam(\sys_get_temp_dir(), 'jar');
        http($addr, 'GET', '/counter', [], $jar); // each visitor has a session of its own already
        $requests[] = ['GET', "/isolation/user$i?sleep=0.5", [], $jar];
    }
    $start     = \microtime(true);
    $responses = http_all($addr, $requests);
    $elapsed   = \microtime(true) - $start;
    // A visitor without a session cookie, after them
    $anonymous = http($addr, 'GET', '/isolation/anonymous');
    app_stop($proc);

    foreach ($responses as $i => $response) {
        expect(\json_decode($response['body'], true))->toBe(\array_fill_keys(['attribute', 'route', 'main', 'service', 'user', 'session'], "user$i") + ['sessionBefore' => null]);
    }
    expect($elapsed)->toBeLessThan(2.5) // they ran at once, each on a kernel of its own
        ->and(\json_decode($anonymous['body'], true)['sessionBefore'])->toBeNull()
        ->and($anonymous['headers']['set-cookie'][0])->toStartWith('PHPSESSID=');
});
