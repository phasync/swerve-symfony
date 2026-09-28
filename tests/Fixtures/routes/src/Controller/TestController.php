<?php

namespace App\Controller;

use App\Service\RequestLabel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class TestController extends AbstractController
{
    /** Wait as a coroutine: sleep() waits so with phasync-ext, phasync::sleep() without it. */
    private static function wait(float $seconds): void
    {
        \extension_loaded('phasync') ? \usleep((int) ($seconds * 1e6)) : \phasync::sleep($seconds);
    }

    #[Route('/', name: 'home')]
    public function home(): Response
    {
        return $this->render('home.html.twig', ['version' => Kernel::VERSION]);
    }

    #[Route('/json', methods: ['GET'])]
    public function jsonRoute(): JsonResponse
    {
        return new JsonResponse(['framework' => 'symfony', 'version' => Kernel::VERSION]);
    }

    #[Route('/form', methods: ['GET'])]
    public function form(): Response
    {
        return $this->render('form.html.twig');
    }

    #[Route('/form', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('contact', $request->request->getString('_token'))) {
            return new Response('Invalid CSRF token', 403);
        }

        return new Response('Thanks, ' . $request->request->getString('name'));
    }

    #[Route('/api/echo', methods: ['POST'])]
    public function echoJson(Request $request): JsonResponse
    {
        return new JsonResponse(['received' => $request->toArray()]);
    }

    #[Route('/upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $file   = $request->files->get('document');
        $target = $this->getParameter('kernel.project_dir') . '/var/uploads';
        $name   = $file->getClientOriginalName();
        $sha1   = \sha1_file($file->getPathname());
        $moved  = $file->move($target, \bin2hex(\random_bytes(8)) . '-' . $name);

        return new JsonResponse(['name' => $name, 'size' => $moved->getSize(), 'sha1' => $sha1, 'field' => $request->request->get('title'), 'none' => $request->files->get('none')]);
    }

    /** Stores the name in every request-scoped place, waits, and reads them back. */
    #[Route('/isolation/{name}')]
    public function isolation(string $name, Request $request, RequestStack $stack, RequestLabel $label, TokenStorageInterface $tokens): JsonResponse
    {
        $request->attributes->set('mine', $name);
        $session = $request->getSession();
        $before  = $session->get('name');
        $session->set('name', $name);
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($name, null), 'main'));
        self::wait((float) $request->query->get('sleep', 0));

        return new JsonResponse([
            'attribute'      => $stack->getCurrentRequest()->attributes->get('mine'),
            'route'          => $stack->getCurrentRequest()->attributes->get('_route_params')['name'],
            'main'           => $stack->getMainRequest()->attributes->get('name'),
            'service'        => $label->label,
            'user'           => $tokens->getToken()?->getUserIdentifier(),
            'session'        => $stack->getSession()->get('name'),
            'sessionBefore'  => $before,
        ]);
    }

    #[Route('/counter')]
    public function counter(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $session->set('count', $count = $session->get('count', 0) + 1);

        return new JsonResponse(['count' => $count, 'pid' => \getmypid()]);
    }

    #[Route('/flash/add')]
    public function flashAdd(): Response
    {
        $this->addFlash('notice', 'Saved!');

        return new Response('added');
    }

    #[Route('/flash/show')]
    public function flashShow(Request $request): JsonResponse
    {
        return new JsonResponse($request->getSession()->getFlashBag()->get('notice'));
    }

    #[Route('/login', name: 'login')]
    public function login(AuthenticationUtils $utils): Response
    {
        return $this->render('login.html.twig', ['error' => $utils->getLastAuthenticationError()]);
    }

    #[Route('/logout', name: 'logout')]
    public function logout(): never
    {
        throw new \LogicException('The firewall handles this');
    }

    #[Route('/me', name: 'me')]
    public function me(): JsonResponse
    {
        return new JsonResponse(['user' => $this->getUser()?->getUserIdentifier()]);
    }

    /** Chunks with the time each was produced, $wait seconds apart. */
    #[Route('/stream')]
    public function chunks(Request $request): StreamedResponse
    {
        $name  = $request->query->getString('name', 'stream');
        $count = $request->query->getInt('chunks', 3);
        $wait  = (float) $request->query->get('sleep', 0.2);

        return new StreamedResponse(static function () use ($name, $count, $wait) {
            for ($i = 1; $i <= $count; ++$i) {
                echo "$name chunk $i at ", \microtime(true), "\n";
                \flush();
                $i < $count && self::wait($wait);
            }
        }, 200, ['Content-Type' => 'text/plain']);
    }

    /** Server-Sent Events until the client leaves; then a file says the producer stopped. */
    #[Route('/sse/{id}')]
    public function sse(string $id): StreamedResponse
    {
        $stopped = $this->getParameter('kernel.project_dir') . "/var/sse-stopped-$id";

        return new StreamedResponse(static function () use ($stopped) {
            try {
                for ($i = 1;; ++$i) {
                    echo "data: event $i\n\n";
                    \flush();
                    self::wait(0.05);
                }
            } finally {
                \touch($stopped);
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache']);
    }

    #[Route('/file')]
    public function download(): Response
    {
        return $this->file($this->getParameter('kernel.project_dir') . '/composer.lock');
    }

    #[Route('/slow')]
    public function slow(Request $request): Response
    {
        self::wait((float) $request->query->get('sleep', 1));

        return new Response('slow done');
    }

    /** A blocking wait, as for a database query: cooperative with phasync-ext only. */
    #[Route('/usleep')]
    public function blockingWait(Request $request): JsonResponse
    {
        \usleep(1000 * $request->query->getInt('ms', 10));

        return new JsonResponse(['waited' => $request->query->getInt('ms', 10)]);
    }

    #[Route('/memory')]
    public function memory(): JsonResponse
    {
        \gc_collect_cycles();

        return new JsonResponse(['memory' => \memory_get_usage(), 'pid' => \getmypid()]);
    }
}
