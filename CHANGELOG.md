# Changelog

## Unreleased

### Changed

- Requires phasync and swerve `dev-main`: phasync's `Scope` + `Frame`, and swerve's request model,
  where each request runs in a `Scope` of its own. The adapter used no contexts.
- A stopping worker ends its requests at once (swerve's new stop): a request in flight is answered
  `503` by swerve. A `kernel.exception` listener throws swerve's `WorkerStoppingException` on, out
  of the kernel, before Symfony logs it or renders an error page.

## 0.1.0-alpha4 (2026-10-06)

### Changed

- Breaking: the adapter follows swerve's new handler contract. `Swerve\Symfony\entry()` is the
  entry point, declared in `extra.swerve` of this package's own composer.json: swerve's workers
  call it once each, after the fork, with the application directory; `swerve.php` is no longer
  read. The front controller (`public/index.php`) is unchanged, so the application still runs
  under PHP-FPM.
- Breaking: the Symfony `Request`/`Response` are built from and sent to swerve's `ClientRequest`
  directly, with no PSR-7 in between. A controller that returned a PSR-7 response (such as the
  old `Swerve\Http\WebSocket::from()`) now returns an ordinary Symfony `Response`; the
  `kernel.VIEW` listener that converted one is gone, and so are the `psr/http-server-handler`
  dependency and `Handler`'s `kernels:` constructor argument.
- The kernel pool has no cap: `phasync\Util\Pool` makes a kernel whenever every existing one is
  busy, and keeps the peak used in the last 30 seconds (its default `$window`). A worker that
  never overlaps requests (no phasync-ext, or no I/O wait) still keeps exactly one.
- `Swerve\Symfony\WebSocketResponse::from($request, $handler, ...)`: a Symfony-native `101`.
  Outbound frames are `echo`ed and `flush()`ed inside a `StreamedResponse` callback, so a
  `kernel.response` listener may wrap it (or `ob_start()` of its own) to see every one; inbound
  frames are read from `$request->getContent(true)`, a stream resource over the raw connection for
  a handshake request. A listener that changes the response's status (a `403`, say) is sent as
  that response, with an empty body: the handler never runs. The kernel goes back to the pool
  before the callback runs.

### Added

- `Swerve\Symfony\Handler::booted()`: how many kernels a worker has booted, for tests that check a
  WebSocket never grows the pool.

## 0.1.0-alpha1

- First release: Symfony 6.4 and 7.x on swerve.
