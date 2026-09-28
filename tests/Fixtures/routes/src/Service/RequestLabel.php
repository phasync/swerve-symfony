<?php

namespace App\Service;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Service\ResetInterface;

/** A request-scoped service: set on kernel.request, reset by kernel.reset. */
final class RequestLabel implements ResetInterface
{
    public ?string $label = null;

    #[AsEventListener]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->label = $event->getRequest()->attributes->get('name');
        }
    }

    public function reset(): void
    {
        $this->label = null;
    }
}
