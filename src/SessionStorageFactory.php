<?php

namespace Swerve\Symfony;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\StrictSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageFactoryInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/**
 * Sessions without PHP's session module, for swerve: a SessionStorage per request, through the
 * session handler configured in framework.session.handler_id.
 *
 *     # config/packages/framework.yaml
 *     framework:
 *         session:
 *             storage_factory_id: Swerve\Symfony\SessionStorageFactory
 *             handler_id: '%env(DATABASE_URL)%'   # or redis://..., or a handler service's id
 *
 *     # config/services.yaml, under services:
 *     Swerve\Symfony\SessionStorageFactory: ~
 */
final class SessionStorageFactory implements SessionStorageFactoryInterface
{
    public function __construct(
        private readonly \SessionHandlerInterface $handler,
        #[Autowire('%session.storage.options%')]
        private readonly array $options = [],
    ) {
        if ($handler instanceof \SessionHandler || $handler instanceof StrictSessionHandler) {
            throw new \LogicException('PHP\'s own session handlers (files, the default) need its session module, which swerve can\'t use: set framework.session.handler_id to a database or Redis DSN, or a handler service');
        }
    }

    public function createStorage(?Request $request): SessionStorageInterface
    {
        return new SessionStorage($this->handler, $this->options);
    }
}
