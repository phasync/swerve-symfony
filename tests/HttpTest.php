<?php

/*
 * The skeleton's pages and the usual kinds of requests, through the adapter.
 */

it('serves the home page, rendered by Twig', function () {
    $response = http(app(), 'GET', '/');
    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'][0])->toBe('text/html; charset=UTF-8')
        ->and($response['body'])->toContain('<h1>Hello from Symfony ');
});

it('serves a JSON route', function () {
    $response = http(app(), 'GET', '/json');
    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'][0])->toBe('application/json')
        ->and(\json_decode($response['body'], true)['framework'])->toBe('symfony');
});

it('answers 404 for a missing route', function () {
    expect(http(app(), 'GET', '/missing')['status'])->toBe(404);
});

it('answers HEAD without a body', function () {
    $response = http(app(), 'HEAD', '/json');
    expect($response['status'])->toBe(200)->and($response['body'])->toBe('');
});

it('accepts a form POST with a valid CSRF token, and refuses one without', function () {
    $jar  = \tempnam(\sys_get_temp_dir(), 'jar');
    $form = http(app(), 'GET', '/form', [], $jar)['body'];
    \preg_match('/name="_token" value="([^"]+)"/', $form, $m);
    $post = fn (string $token) => http(app(), 'POST', '/form', [\CURLOPT_POSTFIELDS => \http_build_query(['_token' => $token, 'name' => 'Bob'])], $jar);

    expect($post($m[1]))->toMatchArray(['status' => 200, 'body' => 'Thanks, Bob'])
        ->and($post('forged')['status'])->toBe(403);
});

it('reads a JSON POST body', function () {
    $response = http(app(), 'POST', '/api/echo', [\CURLOPT_POSTFIELDS => '{"numbers":[1,2,3],"name":"æøå"}', \CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    expect(\json_decode($response['body'], true))->toBe(['received' => ['numbers' => [1, 2, 3], 'name' => 'æøå']]);
});

it('receives an upload as an UploadedFile that can be moved', function () {
    $file = \tempnam(\sys_get_temp_dir(), 'upload');
    \file_put_contents($file, $content = \random_bytes(300_000));
    $response = http(app(), 'POST', '/upload', [\CURLOPT_POSTFIELDS => ['document' => new CURLFile($file, 'application/octet-stream', 'report.bin'), 'title' => 'Report']]);

    expect(\json_decode($response['body'], true))->toBe(['name' => 'report.bin', 'size' => 300_000, 'sha1' => \sha1($content), 'field' => 'Report', 'none' => null]);
});

it('sends a BinaryFileResponse, and a range of it', function () {
    $file = \file_get_contents(__DIR__ . '/Fixtures/app/composer.lock');
    $full = http(app(), 'GET', '/file');
    $part = http(app(), 'GET', '/file', [\CURLOPT_RANGE => '100-199']);

    expect($full['body'])->toBe($file)
        ->and($part['status'])->toBe(206)
        ->and($part['headers']['content-range'][0])->toBe('bytes 100-199/' . \strlen($file))
        ->and($part['body'])->toBe(\substr($file, 100, 100));
});
