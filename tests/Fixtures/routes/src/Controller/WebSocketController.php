<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Http\WebSocket;
use Swerve\Swerve;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** WebSockets from controllers: an echo, a topic forwarded, and the user taken first. */
final class WebSocketController extends AbstractController
{
    /** The WebSocket callbacks running in this worker. */
    private static int $live = 0;

    /** Text and binary messages echoed, each as it came. */
    #[Route('/ws')]
    public function echo(Request $request): ResponseInterface
    {
        return WebSocket::from($request->attributes->get(ServerRequestInterface::class), static function (WebSocket $ws) {
            ++self::$live;
            try {
                foreach ($ws as $message) {
                    $ws->isBinary() ? $ws->sendBinary($message) : $ws->send("echo: $message");
                }
            } finally {
                --self::$live;
            }
        });
    }

    /** Everything published to 'news', forwarded: the callback only sends. */
    #[Route('/ws/news')]
    public function news(Request $request): ResponseInterface
    {
        return WebSocket::from($request->attributes->get(ServerRequestInterface::class), static function (WebSocket $ws) {
            ++self::$live;
            try {
                foreach (Swerve::subscribe('news') as $message) {
                    $ws->send($message);
                }
            } finally {
                --self::$live;
            }
        });
    }

    #[Route('/publish', methods: ['POST'])]
    public function publish(Request $request): Response
    {
        Swerve::publish('news', $request->getContent());

        return new Response('published');
    }

    /** The user and the session's data, taken from the request before the connection. */
    #[Route('/ws/me')]
    public function me(Request $request): ResponseInterface
    {
        $user  = $this->getUser()?->getUserIdentifier() ?? 'anonymous';
        $count = $request->getSession()->get('count', 0);

        return WebSocket::from($request->attributes->get(ServerRequestInterface::class), static function (WebSocket $ws) use ($user, $count) {
            foreach ($ws as $message) {
                $ws->send("$user (count $count): $message");
            }
        });
    }

    /**
     * Wrong: the user read from a service inside the callback. The callback runs after the
     * kernel went back to the pool, when its services belong to whichever request came next.
     */
    #[Route('/ws/me-late')]
    public function meLate(Request $request, TokenStorageInterface $tokens): ResponseInterface
    {
        return WebSocket::from($request->attributes->get(ServerRequestInterface::class), static function (WebSocket $ws) use ($tokens) {
            foreach ($ws as $message) {
                $ws->send(($tokens->getToken()?->getUserIdentifier() ?? 'anonymous') . ": $message");
            }
        });
    }

    /** This worker, its running WebSocket callbacks, and the kernel serving this request. */
    #[Route('/ws-live')]
    public function live(KernelInterface $kernel): JsonResponse
    {
        return new JsonResponse(['pid' => \getmypid(), 'live' => self::$live, 'kernel' => \spl_object_id($kernel)]);
    }
}
