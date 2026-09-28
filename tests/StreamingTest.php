<?php

/*
 * StreamedResponse and Server-Sent Events arrive as they are produced.
 */

/** Chunks of a response as they arrive, with the time each arrived; $stop ends it early. */
function arrivals(string $addr, string $path, ?Closure $stop = null): array
{
    $arrivals = [];
    $curl     = \curl_init("http://$addr$path");
    \curl_setopt($curl, \CURLOPT_WRITEFUNCTION, static function ($curl, $data) use (&$arrivals, $stop) {
        $arrivals[] = [\microtime(true), $data];

        return $stop && $stop($arrivals) ? -1 : \strlen($data);
    });
    \curl_exec($curl);

    return $arrivals;
}

it('sends each chunk of a StreamedResponse as it is produced', function () {
    $arrivals = arrivals(app(), '/stream?chunks=3&sleep=0.5');
    \preg_match('/chunk 3 at ([\d.]+)/', \implode('', \array_column($arrivals, 1)), $last);

    // The first chunk arrived before the last was produced, a second later
    expect((float) $last[1] - $arrivals[0][0])->toBeGreaterThan(0.8);
});

it('keeps the output of overlapping StreamedResponses apart', function () {
    [$proc, $addr] = app_start(1); // both on one worker
    $responses     = http_all($addr, [['GET', '/stream?name=A&chunks=5&sleep=0.1', [], null], ['GET', '/stream?name=B&chunks=5&sleep=0.1', [], null]]);
    app_stop($proc);

    expect(\preg_replace('/ at [\d.]+/', '', $responses[0]['body']))->toBe("A chunk 1\nA chunk 2\nA chunk 3\nA chunk 4\nA chunk 5\n")
        ->and(\preg_replace('/ at [\d.]+/', '', $responses[1]['body']))->toBe("B chunk 1\nB chunk 2\nB chunk 3\nB chunk 4\nB chunk 5\n");
});

it('stops a Server-Sent Events producer when the client leaves', function () {
    $id      = \bin2hex(\random_bytes(4));
    $stopped = __DIR__ . "/Fixtures/app/var/sse-stopped-$id";
    $events  = arrivals(app(), "/sse/$id", static fn ($arrivals) => \count($arrivals) >= 3);

    expect($events[0][1])->toBe("data: event 1\n\n");
    for ($deadline = \microtime(true) + 5; !\file_exists($stopped) && \microtime(true) < $deadline;) {
        \usleep(50_000);
    }
    expect(\file_exists($stopped))->toBeTrue();
});
