<?php

namespace Swerve\Symfony;

use phasync;
use phasync\Psr\ServerRequest as PsrServerRequest;
use phasync\Util\Pool;
use Psr\Http\Message\UploadedFileInterface;
use Swerve\ClientRequest;
use Swerve\Psr\FormBody;
use Swerve\Psr\RequestBody;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

/**
 * A Symfony application driven directly from swerve's {@see ClientRequest}: no PSR-7 in between.
 * Built once per worker by {@see entry()}.
 *
 * Once per worker: the front controller (public/index.php) gives the closure that makes the
 * kernel, resolved by Symfony Runtime as under PHP-FPM (.env files, APP_ENV, APP_DEBUG), and one
 * kernel is booted.
 *
 * Per request: the request borrows a kernel from a pool, which makes one whenever all the
 * existing ones are busy (no cap: one kernel per request overlapping with others, see
 * `phasync\Util\Pool`) and keeps the peak of the last 30 s. A kernel serves one request at a
 * time, so its RequestStack, session and stateful services are that request's; Symfony resets
 * the services (kernel.reset) when the kernel starts its next request, as with Symfony
 * Runtime's FrankenPHP and Swoole runners. terminate() runs after the response, and the kernel
 * goes back to the pool after it. StreamedResponse and BinaryFileResponse are sent as they are
 * produced, the kernel held until they end. A {@see WebSocketResponse} releases the kernel before
 * its callback runs: a socket must not hold one of the pooled kernels.
 */
final class Handler
{
    /** @var Pool<HttpKernelInterface> */
    private readonly Pool $kernels;

    /** @var array<string, mixed> as PHP-FPM would give it, less the per-request part */
    private readonly array $server;

    /** @var \WeakMap<\Fiber, ClientRequest> the client each coroutine streaming a response writes to, see capture() */
    private static \WeakMap $outputs;

    /** How many kernels this worker has ever booted: for tests, which check sockets hold none. */
    private static int $booted = 0;

    /**
     * @param string $root            the application's root directory, where composer.json is
     * @param string $frontController the front controller returning the kernel's closure, from $root
     */
    public function __construct(string $root, string $frontController = 'public/index.php')
    {
        self::$outputs ??= new \WeakMap();

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
            ++self::$booted;
            $kernel = $app(...$arguments);
            if ($kernel instanceof KernelInterface) {
                $kernel->boot();
            }

            return $kernel;
        }, \PHP_INT_MAX, window: 30.0);
        // The first now, at the worker's start; the others when requests overlap
        $this->kernels->release($this->kernels->borrow());
    }

    /** How many kernels this worker has booted so far, for tests. */
    public static function booted(): int
    {
        return self::$booted;
    }

    public function handle(ClientRequest $client): void
    {
        $request = $this->toSymfony($client);
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
        $this->send($client, $request, $response, $finish);
    }

    private function toSymfony(ClientRequest $client): Request
    {
        $method        = $client->getMethod();
        $target        = $client->getTarget();
        $headers       = $client->getRequestHeaders();
        $now           = \microtime(true);
        [$host, $port] = self::hostPort($headers['host'][0] ?? '', 'https' === $client->getScheme() ? 443 : 80);
        $server        = [
            'SERVER_NAME'        => $host,
            'SERVER_PORT'        => $port,
            'REQUEST_METHOD'     => $method,
            'REQUEST_URI'        => $target,
            'QUERY_STRING'       => \parse_url($target, \PHP_URL_QUERY) ?: '',
            'SERVER_PROTOCOL'    => 'HTTP/' . $client->getProtocolVersion(),
            'REQUEST_TIME'       => (int) $now,
            'REQUEST_TIME_FLOAT' => $now,
        ] + $this->server;
        if ('https' === $client->getScheme()) {
            $server['HTTPS'] = 'on';
        }
        if (\preg_match('/^\[?(.+?)\]?:(\d+)$/', $client->peer(), $peer)) {
            $server['REMOTE_ADDR'] = $peer[1];
            $server['REMOTE_PORT'] = (int) $peer[2];
        }
        foreach ($headers as $name => $values) {
            $name                                                                                  = \strtoupper(\strtr($name, '-', '_'));
            $server['CONTENT_TYPE' === $name || 'CONTENT_LENGTH' === $name ? $name : "HTTP_$name"] = \implode(', ', $values);
        }

        $query = [];
        \parse_str($server['QUERY_STRING'], $query);
        $cookies = isset($headers['cookie']) ? PsrServerRequest::cookies(\implode('; ', $headers['cookie'])) : [];

        // A request has a body when it says so; its length is known unless it is chunked. An
        // upgrade request (a WebSocket handshake) never has one: its body is the connection
        // itself, read lazily by WebSocketResponse, never buffered here.
        $upgrade = isset($headers['upgrade']) && !isset($headers['content-length']) && !isset($headers['transfer-encoding']);
        if ($upgrade) {
            $request = new Request($query, [], [], $cookies, [], $server, ClientRequestStream::open($client));
        } else {
            $size   = isset($headers['transfer-encoding']) ? null : (int) ($headers['content-length'][0] ?? 0);
            $body   = new RequestBody($client, $size);
            $form   = isset($headers['content-type']) ? FormBody::for($method, $headers['content-type'][0], $body) : null;
            // fields()/files() parse the body (lazily, once); input() is only correct once that
            // has happened, since parsing is what leaves it still readable (or empty)
            $fields  = $form?->fields() ?? [];
            $files   = self::files($form?->files() ?? []);
            $content = $form ? $form->input()->getContents() : $body->getContents();
            $request = new Request($query, $fields, [], $cookies, $files, $server, $content);
            if (null !== $form) {
                // Keeps the uploads' temporary files for as long as $request lives: FormBody
                // deletes them (__destruct()) once nothing references it any more
                $request->attributes->set(FormBody::class, $form);
            }
        }

        return $request;
    }

    /** The host and port from a Host header (which may carry its own port), with a default port. */
    private static function hostPort(string $host, int $defaultPort): array
    {
        if (\preg_match('/^(.*):(\d+)$/', $host, $m)) {
            return [$m[1], (int) $m[2]];
        }

        return [$host, $defaultPort];
    }

    /** $_FILES as Symfony has it, from PSR-7 uploaded files (Swerve\Psr\FormBody::files()): no file is null. */
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

    /** Send the Symfony response to the client: the head, the body (as it is produced for a stream), and the end. */
    private function send(ClientRequest $client, Request $request, Response $response, \Closure $finish): void
    {
        $headers = $response->headers->allPreserveCaseWithoutCookies();
        foreach ($response->headers->getCookies() as $cookie) {
            $headers['Set-Cookie'][] = (string) $cookie;
        }
        $isWebSocket = $response instanceof WebSocketResponse;
        if ($isWebSocket && 101 === $response->getStatusCode()) {
            $client->sendResponseHeaders(101, $headers);
            $client->flush();
            $finish(); // the kernel goes back to the pool before the socket's callback runs
            self::runWebSocket($client, $response);
            $client->end();

            return;
        }
        // A WebSocketResponse whose status a kernel.response listener changed sends no body: the
        // handler, which only `sendContent()` would start, never ran
        $empty   = $isWebSocket || 'HEAD' === $request->getMethod() || $response->isInformational() || $response->isEmpty();
        $stream  = !$empty && ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse);
        $content = $empty || $stream ? '' : (string) $response->getContent();
        if (!$empty && !$stream && !isset($headers['Content-Length']) && !isset($headers['content-length'])) {
            $headers['Content-Length'] = (string) \strlen($content);
        }
        $client->sendResponseHeaders($response->getStatusCode(), $headers);
        if ($stream) {
            $this->stream($client, $response, $finish);

            return;
        }
        if (!$empty) {
            $client->write($content);
        }
        $client->end();
        \phasync::go($finish);
    }

    /**
     * The body of a StreamedResponse or BinaryFileResponse, written to the client as it is
     * produced; $finish runs once it ends, before end()'s next read, so the kernel is held for
     * exactly as long as the body takes.
     */
    private function stream(ClientRequest $client, StreamedResponse|BinaryFileResponse $response, \Closure $finish): void
    {
        try {
            if ($response instanceof BinaryFileResponse) {
                self::sendFile($client, $response);
            } else {
                self::sendStreamed($client, $response);
            }
        } finally {
            $client->end();
            $finish();
        }
    }

    /** A StreamedResponse's callback, echoing into this coroutine's slot of the output buffer. */
    private static function sendStreamed(ClientRequest $client, StreamedResponse $response): void
    {
        $fiber                  = \Fiber::getCurrent();
        self::$outputs[$fiber]  = $client;
        \ob_start(self::capture(...), 1);
        try {
            $response->sendContent();
        } finally {
            \ob_end_clean();
            unset(self::$outputs[$fiber]);
        }
    }

    /**
     * The output handler of a StreamedResponse's callback, which gets every echo at once (chunk
     * size 1): writes it to the client of the coroutine that echoed it. Output buffers are one
     * stack for the whole process, and any of these handlers may be on top, so each only acts on
     * its own coroutine's output and passes any other through. A write that fails (the client
     * left) throws, ending the callback as a script under PHP-FPM ends when its client left.
     */
    private static function capture(string $buffer): string
    {
        $fiber = \Fiber::getCurrent();
        if (null === $fiber || !isset(self::$outputs[$fiber])) {
            return $buffer; // not one of ours: on to the next buffer, or the terminal
        }
        if ('' !== $buffer) {
            self::$outputs[$fiber]->write($buffer);
        }

        return '';
    }

    /** What BinaryFileResponse would stream, sent with ClientRequest::sendFile(): a Range included. */
    private static function sendFile(ClientRequest $client, BinaryFileResponse $response): void
    {
        [$offset, $length, $temporary, $delete] = (fn () => [$this->offset, $this->maxlen, $this->tempFileObject ?? null, $this->deleteFileAfterSend])->call($response);
        $path                                   = $response->getFile()->getPathname();
        try {
            if (!$response->isSuccessful() || 0 === $length) {
                return;
            }
            if (null !== $temporary) {
                $client->sendFile($temporary->getResource(), $offset, $length > 0 ? $length : null);
            } else {
                $stream = \fopen($path, 'rb');
                try {
                    $client->sendFile($stream, $offset, $length > 0 ? $length : null);
                } finally {
                    \fclose($stream);
                }
            }
        } finally {
            if (null === $temporary && $delete && \is_file($path)) {
                \unlink($path);
            }
        }
    }

    /** Run a WebSocketResponse's callback: its connection ({@see WebSocketDuplex}) sends to $client. */
    private static function runWebSocket(ClientRequest $client, WebSocketResponse $response): void
    {
        WebSocketDuplex::bind($client);
        $response->sendContent();
    }
}
