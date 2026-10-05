<?php

namespace Swerve\Symfony;

use Swerve\WebSocket;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The response that upgrades a Symfony request to a WebSocket, Symfony-native: the entry of the
 * adapter.
 *
 * The handler of the application is always given swerve's own {@see WebSocket}, the same class in
 * every framework adapter, so the examples in swerve's `docs/websocket.md` apply as they are.
 *
 * ```php
 * #[Route('/chat')]
 * public function chat(Request $request): Response
 * {
 *     $user = $this->getUser()?->getUserIdentifier();   // read the request now: the handler runs after the kernel went back to the pool
 *
 *     return WebSocketResponse::from($request, function (WebSocket $ws) use ($user) {
 *         foreach ($ws as $message) {
 *             $ws->send("$user: $message");
 *         }
 *     });
 * }
 * ```
 *
 * `from()` checks the handshake in the request, with swerve's own decision ({@see WebSocket::handshake()}),
 * and returns an ordinary Symfony `Response` either way. A request that is no handshake gets the
 * refusal swerve itself would send (426 for a plain GET, 400 for an invalid handshake, 403 for an
 * origin that is not allowed), a plain response that a `kernel.response` listener decorates like
 * any other. A handshake gets a `101` `StreamedResponse` with `Upgrade`, `Connection`,
 * `Sec-WebSocket-Accept` and the chosen `Sec-WebSocket-Protocol`.
 *
 * Its callback (`getCallback()`/`setCallback()`, as any `StreamedResponse`) runs `WebSocket::run()`
 * over an internal connection: outbound frames are `echo`ed and `flush()`ed, so a `kernel.response`
 * listener that wraps the callback (or does its own `ob_start()`) sees every one; inbound frames
 * are read from `$request->getContent(true)`. The handler itself does not run before the adapter
 * calls the callback, which it does only once the response is still a `WebSocketResponse` with
 * status `101`: a `kernel.response` listener that changes the status (a `403`, say) is sent as
 * that response, with an empty body, and the handler never runs. The kernel goes back to the
 * pool before the callback runs: take the user or the session from `$request` first, and let the
 * handler capture it.
 */
final class WebSocketResponse extends StreamedResponse
{
    private function __construct(array $headers, \Closure $callback)
    {
        parent::__construct($callback, 101, $headers);
    }

    /**
     * Fixes only the protocol version: `Response::prepare()` treats a `1xx` as bodyless and
     * would call `setContent(null)`, which `StreamedResponse` takes as "already streamed", so
     * the adapter's later `sendContent()` would do nothing and the handler would never run.
     */
    public function prepare(Request $request): static
    {
        if ('HTTP/1.0' !== $request->server->get('SERVER_PROTOCOL')) {
            $this->setProtocolVersion('1.1');
        }

        return $this;
    }

    /**
     * The response to a WebSocket request: a `101` that upgrades it and runs `$handler`, or the refusal.
     *
     * @param callable(WebSocket): void $handler
     * @param string[]                  $subprotocols the subprotocols you speak, in your order of preference: the first one the client offered is chosen
     * @param string[]|null             $origins      the allowed `Origin`s (compared case-insensitively), null: any; a client that sends none is let through
     * @param int                       $maxMessage   the largest message received, in bytes; a larger one closes with 1009
     */
    public static function from(Request $request, callable $handler, array $subprotocols = [], ?array $origins = null, int $maxMessage = WebSocket::MAX_MESSAGE): Response
    {
        $length  = $request->headers->get('Content-Length');
        $hasBody = null !== $length ? ((int) $length > 0) : $request->headers->has('Transfer-Encoding');
        $version = \str_starts_with((string) $request->getProtocolVersion(), 'HTTP/') ? \substr((string) $request->getProtocolVersion(), 5) : (string) $request->getProtocolVersion();

        $handshake = WebSocket::handshake($request->getMethod(), $version, $request->headers->all(), $hasBody, $subprotocols, $origins);
        if (!$handshake->accepted()) {
            return new Response($handshake->body, $handshake->status, self::canonical($handshake->headers));
        }

        $subprotocol = \array_change_key_case($handshake->headers)['sec-websocket-protocol'] ?? null;
        $subprotocol = \is_array($subprotocol) ? $subprotocol[0] : $subprotocol;
        $callback    = $handler(...);

        return new self(self::canonical($handshake->headers), static function () use ($request, $callback, $subprotocol, $maxMessage) {
            $connection = new WebSocketDuplex($request->getContent(true));
            WebSocket::run($connection, $callback, $subprotocol, $maxMessage);
        });
    }

    /**
     * swerve's handshake headers, lowercase as every `ClientRequest` header is, in the case
     * clients and the HTTP spec expect to see on the wire; Symfony's `HeaderBag` sends header
     * names exactly as given.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @return array<string, string|list<string>>
     */
    private static function canonical(array $headers): array
    {
        static $names = [
            'upgrade'                => 'Upgrade',
            'connection'             => 'Connection',
            'sec-websocket-accept'   => 'Sec-WebSocket-Accept',
            'sec-websocket-protocol' => 'Sec-WebSocket-Protocol',
            'sec-websocket-version'  => 'Sec-WebSocket-Version',
            'content-type'           => 'Content-Type',
            'content-length'         => 'Content-Length',
        ];
        $canonical = [];
        foreach ($headers as $name => $value) {
            $canonical[$names[$name] ?? $name] = $value;
        }

        return $canonical;
    }
}
