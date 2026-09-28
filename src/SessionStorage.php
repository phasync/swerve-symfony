<?php

namespace Swerve\Symfony;

use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * A request's session, loaded and saved through a \SessionHandlerInterface (PdoSessionHandler,
 * RedisSessionHandler, MemcachedSessionHandler, ...) without PHP's session module: no
 * session_start(), no $_SESSION, nothing shared with another request. Symfony's session listener
 * reads and writes the cookie, as with every storage. Made by SessionStorageFactory.
 *
 * - An id the handler doesn't know gets a new one, as with session.use_strict_mode.
 * - A session left with nothing but its metadata is deleted, not saved.
 * - gc() runs on gc_probability / gc_divisor of the saves, as PHP's session module does.
 */
final class SessionStorage extends MockArraySessionStorage
{
    public function __construct(private readonly \SessionHandlerInterface $handler, private readonly array $options)
    {
        parent::__construct($options['name'] ?? \ini_get('session.name'), new MetadataBag());
    }

    public function start(): bool
    {
        if ($this->started) {
            return true;
        }
        $this->handler->open('', $this->name);
        if ('' === $this->id || ($this->handler instanceof \SessionUpdateTimestampHandlerInterface && !$this->handler->validateId($this->id))) {
            $this->id = $this->generateId();
        }
        $data       = $this->handler->read($this->id);
        $this->data = '' === $data ? [] : (\unserialize($data) ?: []);
        $this->loadSession();

        return true;
    }

    public function regenerate(bool $destroy = false, ?int $lifetime = null): bool
    {
        $this->start();
        if ($destroy) {
            $this->handler->destroy($this->id);
        }

        return parent::regenerate($destroy, $lifetime);
    }

    public function save(): void
    {
        if (!$this->started) {
            throw new \RuntimeException('Trying to save a session that was not started yet or was already closed.');
        }
        $this->started = false;
        if ([$this->metadataBag->getStorageKey()] === \array_keys(\array_filter($this->data))) {
            $this->handler->destroy($this->id);
        } else {
            $this->handler->write($this->id, \serialize($this->data));
        }
        $probability = (int) ($this->options['gc_probability'] ?? \ini_get('session.gc_probability'));
        if ($probability > 0 && \random_int(1, (int) ($this->options['gc_divisor'] ?? \ini_get('session.gc_divisor')) ?: 100) <= $probability) {
            $this->handler->gc((int) ($this->options['gc_maxlifetime'] ?? \ini_get('session.gc_maxlifetime')));
        }
        $this->handler->close();
    }

    protected function generateId(): string
    {
        return \bin2hex(\random_bytes(16));
    }
}
