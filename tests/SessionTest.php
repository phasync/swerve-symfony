<?php

/*
 * Sessions through Swerve\Symfony\SessionStorageFactory, with PdoSessionHandler on SQLite, as the
 * README configures them.
 */

it('counts in the session across requests landing on both workers', function () {
    $jar  = \tempnam(\sys_get_temp_dir(), 'jar');
    $pids = [];
    for ($n = 1; $n <= 20; ++$n) {
        $data = \json_decode(http(app(), 'GET', '/counter', [], $jar)['body'], true);
        expect($data['count'])->toBe($n);
        $pids[$data['pid']] = true;
    }
    expect($pids)->toHaveCount(2);
});

it('shows a flash message once', function () {
    $jar = \tempnam(\sys_get_temp_dir(), 'jar');
    http(app(), 'GET', '/flash/add', [], $jar);

    expect(\json_decode(http(app(), 'GET', '/flash/show', [], $jar)['body']))->toBe(['Saved!'])
        ->and(\json_decode(http(app(), 'GET', '/flash/show', [], $jar)['body']))->toBe([]);
});

it('logs in and out with the security bundle', function () {
    $jar = \tempnam(\sys_get_temp_dir(), 'jar');
    $me  = fn () => \json_decode(http(app(), 'GET', '/me', [], $jar)['body'], true)['user'];

    expect($me())->toBeNull()
        ->and(http(app(), 'POST', '/login', [\CURLOPT_POSTFIELDS => '_username=alice&_password=wrong'], $jar)['headers']['location'][0])->toEndWith('/login')
        ->and($me())->toBeNull()
        ->and(http(app(), 'POST', '/login', [\CURLOPT_POSTFIELDS => '_username=alice&_password=secret'], $jar)['headers']['location'][0])->toEndWith('/me')
        ->and($me())->toBe('alice')
        ->and($me())->toBe('alice');
    http(app(), 'GET', '/logout', [], $jar);
    expect($me())->toBeNull();
});

it('gives a session id the server never handed out a new one', function () {
    $response = http(app(), 'GET', '/counter', [\CURLOPT_COOKIE => 'PHPSESSID=chosen-by-an-attacker']);
    expect($response['headers']['set-cookie'][0])->toStartWith('PHPSESSID=')->not->toContain('chosen-by-an-attacker');
});
