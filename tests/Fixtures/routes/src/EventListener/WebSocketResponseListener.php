<?php

namespace App\EventListener;

use Swerve\Symfony\WebSocketResponse;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Proof that a kernel.response listener may treat a WebSocketResponse like any other response:
 * wrap its callback to see every outbound frame (route 'ws_logged', which writes each one to
 * var/ws-frames-{log}), or change its status so the handler never runs (route 'ws_blocked').
 */
final class WebSocketResponseListener
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$response instanceof WebSocketResponse) {
            return;
        }
        $route = $event->getRequest()->attributes->get('_route');
        if ('ws_blocked' === $route) {
            $response->setStatusCode(403);

            return;
        }
        if ('ws_logged' === $route) {
            $original = $response->getCallback();
            $path     = $this->projectDir . '/var/ws-frames-' . $event->getRequest()->query->getString('log');
            $response->setCallback(static function () use ($original, $path) {
                \ob_start(static function (string $buffer) use ($path) {
                    if ('' !== $buffer) {
                        \file_put_contents($path, $buffer, \FILE_APPEND);
                    }

                    return $buffer; // falls through to the adapter's own capture handler, which sends it
                }, 1);
                try {
                    $original();
                } finally {
                    \ob_end_flush();
                }
            });
        }
    }
}
