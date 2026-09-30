<?php

namespace Swerve\Symfony;

use phasync\IOException;
use phasync\Psr\Response as PsrResponse;
use phasync\Psr\UnbufferedStream;
use phasync\TimeoutException;
use phasync\Util\Pool;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

/**
 * A Symfony application as swerve's request handler, from the project's swerve.php:
 *
 *     return new Swerve\Symfony\Handler(__DIR__);
 *
 * Once per worker: the front controller (public/index.php) gives the closure that makes the
 * kernel, resolved by Symfony Runtime as under PHP-FPM (.env files, APP_ENV, APP_DEBUG), and one
 * kernel is booted.
 *
 * Per request: the request borrows a kernel of its own from a pool, which makes more, up to
 * $kernels, while requests overlap (with phasync-ext, whenever one waits for I/O). A kernel
 * serves one request at a time, so its RequestStack, session and stateful services are that
 * request's; Symfony resets the services (kernel.reset) when the kernel starts its next request,
 * as with Symfony Runtime's FrankenPHP and Swoole runners. terminate() runs after the response,
 * and the kernel goes back to the pool after it. StreamedResponse and BinaryFileResponse are
 * sent as they are produced, the kernel held until they end. A PSR-7 response from a controller,
 * such as a WebSocket's 101, releases the kernel at once: its body or callback must not use the
 * kernel's services.
 */
final class Handler implements RequestHandlerInterface
{
    /** @var Pool<HttpKernelInterface> */
    private readonly Pool $kernels;

    /** @var array<string, mixed> as PHP-FPM would give it, less the per-request part */
    private readonly array $server;

    /** @var \WeakMap<\Fiber, \WeakReference<UnbufferedStream>> where each coroutine sending a StreamedResponse echoes to */
    private static \WeakMap $outputs;

    /** @var \WeakMap<Response, ResponseInterface> PSR-7 responses returned by controllers */
    private static \WeakMap $psrResponses;

    /**
     * @param string $root            the application's root directory, where composer.json is
     * @param int    $kernels         the most kernels per worker, so the most requests served at
     *                                once; 1 serves one request at a time
     * @param string $frontController the front controller returning the kernel's closure, from $root
     */
    public function __construct(string $root, int $kernels = 16, string $frontController = 'public/index.php')
    {
        self::$outputs ??= new \WeakMap();
        self::$psrResponses ??= new \WeakMap();

        // With the autoloader loaded, autoload_runtime.php returns at once, and the front
        // controller returns its closure instead of running
        require_once $root . '/vendor/autoload.php';
        $app = require $root . '/' . $frontController;
        if (!$app instanceof \Closure) {
            throw new \LogicException("$frontController must return a closure, as Symfony Runtime's front controllers do");
        }
        // The options autoload_runtime.php gets: APP_RUNTIME_OPTIONS and composer.json's extra.runtime
        $options = $_SERVER['APP_RUNTIME_OPTIONS'] ?? $_ENV['APP_RUNTIME_OPTIONS'] ?? [];
        $options = (\is_string($options) ? \json_decode($options, true, 512, \JSON_THROW_ON_ERROR) : $options)
            + (\json_decode((string) \file_get_contents("$root/composer.json"), true)['extra']['runtime'] ?? [])
            + ['project_dir' => $root];
        [$app, $arguments] = (new SymfonyRuntime($options))->getResolver($app)->resolve();
        // As Symfony's FrankenPHP runner says: a long-running web server (web error pages, not CLI ones)
        $_SERVER['APP_RUNTIME_MODE'] ??= 'web=1&worker=1';

        $script       = '/' . \basename($frontController);
        $this->server = [
            'SCRIPT_NAME'     => $script,
            'PHP_SELF'        => $script,
            'SCRIPT_FILENAME' => "$root/$frontController",
            'DOCUMENT_ROOT'   => \dirname("$root/$frontController"),
        ] + \array_diff_key($_SERVER, ['argv' => 1, 'argc' => 1]);

        $this->kernels = new Pool(static function () use ($app, $arguments): HttpKernelInterface {
            $kernel = $app(...$arguments);
            if ($kernel instanceof KernelInterface) {
                $kernel->boot();
                // A controller may return a PSR-7 response, such as Swerve\Http\WebSocket::from()'s
                $kernel->getContainer()->get('event_dispatcher')->addListener(KernelEvents::VIEW, static function (ViewEvent $event) {
                    $psr = $event->getControllerResult();
                    if ($psr instanceof ResponseInterface) {
                        $event->setResponse($response = new Response('', $psr->getStatusCode(), $psr->getHeaders()));
                        self::$psrResponses[$response] = $psr;
                    }
                });
            }

            return $kernel;
        }, $kernels);
        // The first now, at the worker's start; the others when requests overlap
        $this->kernels->release($this->kernels->borrow());
    }

    public function handle(ServerRequestInterface $psrRequest): ResponseInterface
    {
        $request = $this->toSymfony($psrRequest);
        $kernel  = $this->kernels->borrow();
        try {
            $response = $kernel->handle($request);
        } catch (\Throwable $e) {
            // handle() turns the application's exceptions into error pages: this kernel is broken
            $this->kernels->discard($kernel);
            throw $e;
        }
        // As after fastcgi_finish_request(): kernel.terminate listeners run after the response
        $finish = function () use ($kernel, $request, $response) {
            try {
                if ($kernel instanceof TerminableInterface) {
                    $kernel->terminate($request, $response);
                }
            } finally {
                $this->kernels->release($kernel);
            }
        };

        $headers = $response->headers->allPreserveCaseWithoutCookies();
        foreach ($response->headers->getCookies() as $cookie) {
            $headers['Set-Cookie'][] = (string) $cookie;
        }
        $empty = 'HEAD' === $request->getMethod() || $response->isInformational() || $response->isEmpty();
        if (!$empty && ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse)) {
            $body = $this->stream($response, $finish); // runs $finish when it ends
        } else {
            $body = isset(self::$psrResponses[$response]) ? self::$psrResponses[$response]->getBody() : ($empty ? '' : (string) $response->getContent());
            \phasync::go($finish);
        }

        return new PsrResponse($response->getStatusCode(), $headers, $body, $response->getProtocolVersion());
    }

    private function toSymfony(ServerRequestInterface $psr): Request
    {
        $uri    = $psr->getUri();
        $server = [
            'SERVER_NAME'  => $uri->getHost(),
            'SERVER_PORT'  => $uri->getPort() ?? ('https' === $uri->getScheme() ? 443 : 80),
            'QUERY_STRING' => $uri->getQuery(),
        ] + $psr->getServerParams() + $this->server;
        if ('https' === $uri->getScheme()) {
            $server['HTTPS'] = 'on';
        }
        foreach ($psr->getHeaders() as $name => $values) {
            $name                                                                                  = \strtoupper(\strtr($name, '-', '_'));
            $server['CONTENT_TYPE' === $name || 'CONTENT_LENGTH' === $name ? $name : "HTTP_$name"] = \implode(', ', $values);
        }
        $parsed = $psr->getParsedBody();
        $files  = self::files($psr->getUploadedFiles());
        // The body is read now, as PHP-FPM does, but not an upgrade's (a WebSocket's): its body
        // is the connection, and reading it here would refuse the upgrade
        $upgrade = $psr->hasHeader('Upgrade') && !$psr->hasHeader('Content-Length') && !$psr->hasHeader('Transfer-Encoding');
        $request = new Request($psr->getQueryParams(), \is_array($parsed) ? $parsed : [], [], $psr->getCookieParams(), $files, $server, $upgrade ? '' : $psr->getBody()->getContents());
        // For WebSocket::from() in a controller; it also keeps the uploads' temporary files
        $request->attributes->set(ServerRequestInterface::class, $psr);

        return $request;
    }

    /** $_FILES as Symfony has it, from PSR-7 uploaded files: no file is null. */
    private static function files(array $files): array
    {
        foreach ($files as $key => $file) {
            $files[$key] = match (true) {
                !$file instanceof UploadedFileInterface   => self::files($file),
                \UPLOAD_ERR_NO_FILE === $file->getError() => null,
                // Swerve's temporary file; "test" since it is no upload of PHP's own
                default => new UploadedFile(\UPLOAD_ERR_OK === $file->getError() ? $file->getStream()->getMetadata('uri') : '', (string) $file->getClientFilename(), $file->getClientMediaType(), $file->getError(), true),
            };
        }

        return $files;
    }

    /**
     * The body of a StreamedResponse or BinaryFileResponse, produced by a coroutine that holds
     * the kernel until it ends, and then runs $finish.
     */
    private function stream(StreamedResponse|BinaryFileResponse $response, \Closure $finish): UnbufferedStream
    {
        // A StreamedResponse echoes inside an output handler, which can't wait for the client:
        // its buffer is unlimited. A file is read as the client takes it.
        $body = $response instanceof StreamedResponse ? new UnbufferedStream(\PHP_INT_MAX, \PHP_FLOAT_MAX) : new UnbufferedStream();
        // Held weakly: when the client leaves, swerve drops the body, and the producer stops
        $out = \WeakReference::create($body);
        \phasync::go(static function () use ($response, $out, $finish) {
            try {
                if ($response instanceof BinaryFileResponse) {
                    self::sendFile($response, $out);
                } else {
                    self::$outputs[\Fiber::getCurrent()] = $out;
                    \ob_start(self::capture(...), 1);
                    try {
                        $response->sendContent();
                    } finally {
                        \ob_end_clean();
                    }
                }
            } catch (IOException|TimeoutException $e) {
                if (null !== $out->get()) {
                    throw $e;
                }
            } finally {
                $out->get()?->end();
                $finish();
            }
        });

        return $body;
    }

    /**
     * The output handler of StreamedResponse callbacks, which gets every echo at once (chunk
     * size 1). Output buffers are one stack for the whole process, and any of these handlers may
     * be on top, so each gives the output to the body of the coroutine that echoed it.
     */
    private static function capture(string $buffer): string
    {
        $fiber = \Fiber::getCurrent();
        if (null === $fiber || !isset(self::$outputs[$fiber])) {
            return $buffer; // not a StreamedResponse's: on to the next buffer, or the terminal
        }
        if ('' !== $buffer) {
            // Throwing ends the callback, as a script under PHP-FPM ends when its client left
            (self::$outputs[$fiber]->get() ?? throw new IOException('The client left'))->append($buffer);
        }

        return '';
    }

    /** What BinaryFileResponse::sendContent() writes, read as the client takes it. */
    private static function sendFile(BinaryFileResponse $response, \WeakReference $out): void
    {
        [$offset, $length, $temporary, $delete] = (fn () => [$this->offset, $this->maxlen, $this->tempFileObject ?? null, $this->deleteFileAfterSend])->call($response);
        $path                                   = $response->getFile()->getPathname();
        try {
            if (!$response->isSuccessful() || 0 === $length) {
                return;
            }
            $file = $temporary ?? new \SplFileObject($path, 'r');
            $file->fseek($offset);
            while (0 !== $length && !$file->eof()) {
                $data = $file->fread($length > 0 ? \min($length, 65536) : 65536);
                if (false === $data || '' === $data) {
                    break;
                }
                ($out->get() ?? throw new IOException('The client left'))->append($data);
                $length -= $length > 0 ? \strlen($data) : 0;
            }
        } finally {
            if (null === $temporary && $delete && \is_file($path)) {
                \unlink($path);
            }
        }
    }
}
