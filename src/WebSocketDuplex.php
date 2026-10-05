<?php

namespace Swerve\Symfony;

use phasync\Net\Duplex;
use Swerve\ClientRequest;

/**
 * The connection {@see \Swerve\WebSocket::run()} reads and writes for a {@see WebSocketResponse}:
 * built inside its `StreamedResponse` callback, from `$request->getContent(true)` for the inbound
 * frames (a Symfony-native stream resource, see {@see ClientRequestStream}).
 *
 * `write()` echoes and flushes, Symfony-native, so a `kernel.response` listener that wraps the
 * callback (`setCallback()`, or its own `ob_start()`) sees every outbound frame: each call opens
 * its own output buffer, echoes into it, and pops exactly what it wrote, so it is correct even
 * though `pump()` ({@see \Swerve\WebSocket}) runs the application's callback in a coroutine of
 * its own and a send may come from a different fiber than the one that built this connection.
 * What it captured is sent straight to the client; if another buffer is still open after popping
 * its own, it echoes the same bytes once more so that one sees them too, for observers only (the
 * client's copy never depends on it).
 *
 * @internal built by {@see WebSocketResponse}
 */
final class WebSocketDuplex implements Duplex
{
    /** @var ClientRequest|null set by {@see Handler::runWebSocket()} just before building this, consumed at once: no fiber switch happens in between */
    private static ?ClientRequest $pendingClient = null;

    private bool $ended = false;
    private readonly ClientRequest $client;

    /** @param resource $resource read for the inbound frames, see {@see ClientRequestStream} */
    public function __construct(private readonly mixed $resource)
    {
        $this->client        = self::$pendingClient ?? throw new \LogicException('A WebSocketDuplex must be built while Handler::runWebSocket() is sending the response');
        self::$pendingClient = null;
    }

    /** The client the next `WebSocketDuplex` built (synchronously, right after) sends to. */
    public static function bind(ClientRequest $client): void
    {
        self::$pendingClient = $client;
    }

    public function read(int $max = 65536, ?float $timeout = null): string
    {
        $data = \fread($this->resource, $max);
        if (false === $data || '' === $data) {
            $this->ended = \feof($this->resource);

            return '';
        }

        return $data;
    }

    public function write(string $bytes, ?float $timeout = null): void
    {
        \ob_start();
        echo $bytes;
        $sent = \ob_get_clean();
        if ('' !== $sent && \ob_get_level() > 0) {
            echo $sent; // an outer buffer (a kernel.response listener's) still open: let it see this too
            \flush();
        }
        $this->client->write($sent, $timeout);
    }

    public function eof(): bool
    {
        return $this->ended || \feof($this->resource);
    }

    public function pending(): bool
    {
        return !$this->eof();
    }

    /** Finish our side: WebSocket::end() calls this after its close frame; the real end comes from Handler. */
    public function end(): void
    {
    }

    public function close(): void
    {
        $this->ended = true;
    }

    public function isClosed(): bool
    {
        return $this->ended;
    }

    public function peer(): string
    {
        return $this->client->peer();
    }

    public function local(): string
    {
        return $this->client->local();
    }
}
