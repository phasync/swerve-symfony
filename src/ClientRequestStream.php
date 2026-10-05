<?php

namespace Swerve\Symfony;

use Swerve\ClientRequest;

/**
 * A PHP stream resource over a {@see ClientRequest}'s raw bytes: what a WebSocket handshake's
 * `Request` carries as its content, so `$request->getContent(true)` ({@see WebSocketResponse})
 * is a Symfony-native way to read the inbound frames, with no body read eagerly for a connection
 * that is about to become one.
 *
 * `rewind()` (which `Request::getContent(true)` calls once, before anything was read) is a no-op:
 * real seeking is not possible on a live connection, and nothing has been consumed yet.
 *
 * @internal opened by {@see Handler::toSymfony()}
 */
final class ClientRequestStream
{
    private const PROTOCOL = 'swerve-symfony-client';

    private ClientRequest $client;
    private int $position = 0;

    /** @return resource */
    public static function open(ClientRequest $client)
    {
        if (!\in_array(self::PROTOCOL, \stream_get_wrappers(), true)) {
            \stream_wrapper_register(self::PROTOCOL, self::class);
        }
        $context = \stream_context_create([self::PROTOCOL => ['client' => $client]]);

        return \fopen(self::PROTOCOL . '://', 'rb', false, $context);
    }

    /** @var resource set by PHP before stream_open() is called */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->client = \stream_context_get_options($this->context)[self::PROTOCOL]['client'];

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->client->eof()) {
            return '';
        }
        $data = $this->client->read($count);
        $this->position += \strlen($data);

        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->client->eof();
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence = \SEEK_SET): bool
    {
        return 0 === $offset && \SEEK_SET === $whence && 0 === $this->position; // rewind() before anything was read: a no-op
    }

    public function stream_close(): void
    {
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return []; // no size, no mtime: a connection, not a file
    }
}
