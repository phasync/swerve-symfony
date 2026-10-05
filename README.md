# swerve for Symfony

[![CI](https://github.com/phasync/swerve-symfony/actions/workflows/ci.yaml/badge.svg?branch=main)](https://github.com/phasync/swerve-symfony/actions/workflows/ci.yaml)
[![Packagist](https://img.shields.io/packagist/v/phasync/swerve-symfony)](https://packagist.org/packages/phasync/swerve-symfony)
[![PHP](https://img.shields.io/packagist/dependency-v/phasync/swerve-symfony/php)](https://packagist.org/packages/phasync/swerve-symfony)
![License](https://img.shields.io/github/license/phasync/swerve-symfony)

**Your Symfony application, booted once and kept warm.** [swerve](https://github.com/phasync/swerve)
is a PHP application server: long-running workers that serve HTTP/1.1 themselves, stream
request and response bodies, and hold WebSockets and Server-Sent Events. This package lets it
run a Symfony application unchanged, and lets its controllers hold [WebSockets](#websockets).

```bash
composer config minimum-stability beta   # while swerve is in beta
composer config prefer-stable true         # everything else stays stable
composer require phasync/swerve-symfony
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public
```

That's the whole setup, apart from [sessions](#sessions). `public/index.php` stays as it is, so
the same application still runs under PHP-FPM: swerve finds this package's entry point from its
own composer.json (`extra.swerve`), with no `swerve.php` of your own.

## WebSockets

A controller returns `Swerve\Symfony\WebSocketResponse::from()`, an ordinary Symfony `Response`,
with the callback that runs on the connection:

```php
use Swerve\Symfony\WebSocketResponse;
use Swerve\WebSocket;

#[Route('/chat')]
public function chat(Request $request): Response
{
    return WebSocketResponse::from($request, static function (WebSocket $ws) {
        foreach ($ws as $message) {                     // text or binary, until the client leaves
            $ws->isBinary() ? $ws->sendBinary($message) : $ws->send("echo: $message");
        }
    });
}
```

An ordinary `GET` to the route is answered `426 Upgrade Required`. A connection holds no kernel:
the kernel goes back to the pool with the `101`, so a worker holds hundreds of sockets and
serves requests beside them with as few kernels as those requests need. Outbound frames are
`echo`ed and `flush()`ed inside a Symfony `StreamedResponse` callback (`$response->getCallback()`,
`setCallback()`), so a `kernel.response` listener may wrap it, or do its own `ob_start()`, to see
every one; a listener that changes the response's status (a `403`, say) sends that response, with
an empty body, and the handler never runs.

**Server push.** A callback that only forwards a topic ends when its client leaves, and any
route, in any worker, publishes to it:

```php
#[Route('/news', methods: ['GET'])]
public function news(Request $request): Response
{
    return WebSocketResponse::from($request, static function (WebSocket $ws) {
        foreach (Swerve::subscribe('news') as $message) {
            $ws->send($message);
        }
    });
}

#[Route('/news', methods: ['POST'])]
public function publish(Request $request): Response
{
    Swerve::publish('news', $request->getContent());

    return new Response('published');
}
```

**The user and the session: take them first.** The callback runs after the kernel went back to
the pool, when its services (the security token storage, the `RequestStack`, the session, your
own) serve whichever request comes next: read inside the callback, `$this->getUser()` may give
another user. Take what the callback needs before `WebSocketResponse::from()`, and pass it in:

```php
#[Route('/me')]
public function me(Request $request): Response
{
    $user = $this->getUser()?->getUserIdentifier();
    $cart = $request->getSession()->get('cart', []);

    return WebSocketResponse::from($request, static function (WebSocket $ws) use ($user, $cart) {
        foreach ($ws as $message) {
            $ws->send("$user: $message");
        }
    });
}
```

A `static` closure keeps `$this` (the controller and its container) out of it. On a shutdown or
reload, open sockets are closed with `1001`, and the worker exits once their callbacks end.

## Server-Sent Events

No class of this package's own: a `StreamedResponse` (or Symfony 7.3's native
`EventStreamResponse`) with `text/event-stream` streams like any other, through the same path as
[Streaming](#how-it-runs) below. `flush()` after each `echo` sends it at once:

```php
#[Route('/ticks')]
public function ticks(): StreamedResponse
{
    return new StreamedResponse(static function () {
        foreach (Swerve::subscribe('ticks') as $tick) {
            echo "data: $tick\n\n";
            \flush();
        }
    }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache']);
}
```

## What changes

The Symfony 7.4 skeleton, 4 workers each, requests per second:

| | PHP-FPM | swerve | swerve + phasync-ext |
|---|---:|---:|---:|
| JSON route | [1,857](benchmarks/results/fpm-json.txt) | [10,199](benchmarks/results/swerve-json.txt) (5.5×) | [9,841](benchmarks/results/swerve-ext-json.txt) (5.3×) |
| Page with a session (SQLite) | [261](benchmarks/results/fpm-session.txt) | [2,096](benchmarks/results/swerve-session.txt) (8.0×) | [2,135](benchmarks/results/swerve-ext-session.txt) (8.2×) |
| Route waiting 10 ms, as for a query | [255](benchmarks/results/fpm-wait.txt) | [358](benchmarks/results/swerve-wait.txt) (1.4×) | [5,046](benchmarks/results/swerve-ext-wait.txt) (19.8×) |

[Method and raw results](benchmarks/).

## How it runs

- **Once per worker:** the front controller returns its closure (Symfony Runtime's
  `autoload_runtime.php` returns early when the autoloader is already loaded), and
  `Symfony\Component\Runtime\SymfonyRuntime` resolves it as under PHP-FPM: `.env` files,
  `APP_ENV`, `APP_DEBUG`, `extra.runtime` options in composer.json. One kernel is booted.
  `APP_RUNTIME_MODE` is `web=1&worker=1`, as Symfony's FrankenPHP runner sets it.
- **Per request:** swerve's `ClientRequest` is converted straight to an HttpFoundation `Request`
  (the body read, uploads as `UploadedFile`, swerve's own `Swerve\Psr\FormBody` doing the
  parsing) with no PSR-7 in between; the request borrows a kernel of its own from a pool
  (`phasync\Util\Pool`), and `handle()` runs. The `Response` is sent straight back to the
  `ClientRequest` the same way; `kernel.terminate` runs once swerve has it (as after
  `fastcgi_finish_request()`), and the kernel returns to the pool. Symfony resets the kernel's
  services (`services_resetter`, every `kernel.reset` service) when it starts its next request,
  as with Symfony Runtime's FrankenPHP and Swoole runners.
- **Concurrency:** a kernel serves one request at a time, so its `RequestStack`, security token,
  session and stateful services belong to that request alone. The pool has no cap: it makes a
  kernel whenever every existing one is busy, so with phasync-ext, a request waiting for I/O
  (MySQL, curl, `sleep()`) lets the worker serve others on one of its own; idle kernels beyond
  the peak used in the last 30 seconds are let go. Each kernel after the first costs about 120 KiB
  in the test application (security, Twig, sessions), since the classes and the compiled
  container are shared. Without phasync-ext requests rarely overlap, and the pool stays at one.
- **Streaming:** a `StreamedResponse` is sent as its callback echoes; a `BinaryFileResponse` as
  the client reads it, with `Range` support. Both hold their kernel until they end. When the
  client leaves, the callback's next `echo` throws, ending it as PHP-FPM ends a script.
- **WebSockets from controllers:** a controller may return a `WebSocketResponse`
  ([above](#websockets)), which swerve sends as a `101` and then runs, the kernel already back in
  the pool.

### Sessions

PHP's session module keeps one session per process, so Symfony's default session storage
fails in swerve. `Swerve\Symfony\SessionStorageFactory` gives each request a storage of its own,
loaded and saved through the session handler you configure (`PdoSessionHandler`, Redis,
Memcached), without the session module. It also works under PHP-FPM.

```yaml
# config/packages/framework.yaml
framework:
    session:
        storage_factory_id: Swerve\Symfony\SessionStorageFactory
        handler_id: '%env(DATABASE_URL)%'   # or redis://..., or a handler service's id
```

```yaml
# config/services.yaml, under services: (autowired)
    Swerve\Symfony\SessionStorageFactory: ~
```

An id the handler doesn't know gets a new one (as `session.use_strict_mode`), a session with
nothing in it is deleted, and garbage collection runs on `gc_probability / gc_divisor` of the
saves, as in PHP.

## Before you deploy

- `exit` and `dd()` end the worker, and the requests it is serving with it.
- **Sessions** need the configuration above. PHP's own handlers (files, the default) need the
  session module and are refused. The data is stored with `serialize()`, not PHP's session
  format, so sessions from before the switch are not read: users log in once more. With SQLite,
  set `lock_mode: 0` on `PdoSessionHandler`: its default lock holds a transaction for the whole
  request, which blocks the other workers.
- **Process-wide state is shared by the kernels of a worker.** `\Locale::setDefault()` (which
  `Request::setLocale()` calls), `setlocale()`, `date_default_timezone_set()` and static
  properties of your own code change for every request of the worker. Pass the locale to
  formatters explicitly.
- **Output buffering is one stack per process.** Each `StreamedResponse`'s output reaches its
  own response, but a callback that does `ob_start()`, waits, then `ob_get_clean()` may catch
  another request's output: don't wait inside your own output buffer. `echo` in a controller
  (not in a `StreamedResponse`) goes to swerve's log, not the response.
- **A `StreamedResponse` holds a kernel until it ends**, so each open Server-Sent Events stream
  takes one; the pool has no cap, so this costs memory (a kernel each), not a queue. Code that
  runs after the controller returned (a `WebSocket` callback) must not use the kernel's services,
  which serve the next request by then: take the user and session data first, as in
  [WebSockets](#websockets).
- **A WebSocket client whose connection is reset** (not closed) is logged as an error, `Unhandled
  exception in a coroutine: ... Connection reset by the client` ([phasync/swerve#7](https://github.com/phasync/swerve/issues/7)).
  Nothing else goes wrong: its callback ends, as for any client that leaves.
- **A `StreamedResponse` is not slowed down by a slow client**: what it echoes faster than the
  client reads is kept in memory. Send files with `BinaryFileResponse`, which is read as the
  client takes it.
- **A raw request body** (a JSON or `PUT` body, not a form) is read into memory before the
  controller runs, as `Request::getContent()` needs it; swerve's `--max-body` caps it (8 MiB by
  default). Form uploads are streamed to temporary files.
- **Without phasync-ext** a blocking call (PDO, curl, `sleep()`) blocks the whole worker, and more
  kernels don't help. Load the extension in production.

## Compatibility

| Symfony | PHP | phasync-ext |
|---|---|---|
| 6.4, 7.x (tested: 6.4, 7.4) | 8.2 – 8.5 | optional; tested with and without |

## License

MIT. See [the Ennerd philosophy](PHILOSOPHY.md) for why this stack is built to be owned.
