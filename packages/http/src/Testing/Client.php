<?php

declare(strict_types=1);

namespace Hydra\Http\Testing;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Http\HtmxResponse;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Requests through the application's real pipeline, in process.
 *
 * Nothing carries between requests but what the application itself keeps, so
 * a session survives because the test binds a session that lives in the
 * container, not because a cookie made the round trip.
 */
final class Client
{
    /** The client a request comes from unless a test is about telling clients apart. */
    public const PEER = '198.51.100.7';

    /** @var array<string, string> */
    private array $headers = [];

    private string $peer = self::PEER;

    private int $redirects = 0;

    private bool $prepared = true;

    /** @param list<RequestPreparer> $preparers */
    public function __construct(
        private readonly RequestHandlerInterface $handler,
        private readonly ServerRequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly array $preparers = [],
    ) {}

    /**
     * The handler is resolved per request, so a binding a test swaps after
     * making the client still reaches the pipeline.
     *
     * @param list<RequestPreparer> $preparers
     */
    public static function for(ContainerInterface $container, array $preparers = []): self
    {
        $handler = new class ($container) implements RequestHandlerInterface {
            public function __construct(private readonly ContainerInterface $container) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->container->get(RequestHandlerInterface::class)->handle($request);
            }
        };

        return new self(
            $handler,
            $container->get(ServerRequestFactoryInterface::class),
            $container->get(StreamFactoryInterface::class),
            $preparers,
        );
    }

    /** @param array<string, string> $headers */
    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->call('GET', $uri, null, $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function post(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, $body, $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function put(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->call('PUT', $uri, $body, $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function patch(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->call('PATCH', $uri, $body, $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function delete(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->call('DELETE', $uri, $body, $headers);
    }

    /** Exactly as given: no preparers, no default headers, no redirects followed. */
    public function send(ServerRequestInterface $request): TestResponse
    {
        return new TestResponse($this->handler->handle($request));
    }

    /** A bare request from this client's peer, for a test that needs to build one by hand. */
    public function request(string $method, string $uri): ServerRequestInterface
    {
        $request = $this->requests->createServerRequest($method, $uri, ['REMOTE_ADDR' => $this->peer]);
        parse_str($request->getUri()->getQuery(), $query);

        return $request->withQueryParams($query);
    }

    public function htmx(?string $target = null): self
    {
        $client = $this->withHeader('HX-Request', 'true');

        return $target === null ? $client : $client->withHeader('HX-Target', $target);
    }

    public function withHeader(string $name, string $value): self
    {
        $client = clone $this;
        $client->headers[$name] = $value;

        return $client;
    }

    public function from(string $peer): self
    {
        $client = clone $this;
        $client->peer = $peer;

        return $client;
    }

    /**
     * Follow 3xx responses and htmx redirect directives the way a browser
     * would. A directive is a navigation, so it is followed as a full page.
     */
    public function followingRedirects(int $limit = 5): self
    {
        $client = clone $this;
        $client->redirects = $limit;

        return $client;
    }

    /** Skip the preparers, for a test about what they would have added. */
    public function unprepared(): self
    {
        $client = clone $this;
        $client->prepared = false;

        return $client;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function call(string $method, string $uri, ?array $body, array $headers): TestResponse
    {
        $headers = [...$this->headers, ...$headers];
        $response = $this->dispatch($method, $uri, $body, $headers);
        $trail = [$uri];

        while ($this->redirects > 0 && ($next = $this->next($response, $method, $body, $headers)) !== null) {
            [$method, $uri, $body, $headers] = $next;
            $trail[] = $uri;

            if (count($trail) - 1 > $this->redirects) {
                Assert::fail("More than {$this->redirects} redirects: " . implode(' → ', $trail));
            }

            $response = $this->dispatch($method, $uri, $body, $headers);
        }

        return new TestResponse($response);
    }

    /**
     * A 307 or 308 repeats the request; any other redirect becomes a GET.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @return array{string, string, array<string, mixed>|null, array<string, string>}|null
     */
    private function next(ResponseInterface $response, string $method, ?array $body, array $headers): ?array
    {
        $status = $response->getStatusCode();

        if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
            $location = $response->getHeaderLine('Location');

            return in_array($status, [307, 308], true)
                ? [$method, $location, $body, $headers]
                : ['GET', $location, null, $headers];
        }

        $directive = HtmxResponse::directive($response, 'redirect');

        if ($directive === null) {
            return null;
        }

        $page = array_filter(
            $headers,
            static fn (string $name): bool => !in_array(strtolower($name), ['hx-request', 'hx-target'], true),
            ARRAY_FILTER_USE_KEY,
        );

        return ['GET', $directive, null, $page];
    }

    /**
     * POST arrives parsed, as PHP parses it into $_POST. Every other method
     * carries raw urlencoded bytes, so the application's own body parsing runs.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $uri, ?array $body, array $headers): ResponseInterface
    {
        $request = $this->request($method, $uri);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody($this->streams->createStream(http_build_query($body)));

            if ($method === 'POST') {
                $request = $request->withParsedBody($body);
            }
        }

        if ($this->prepared) {
            foreach ($this->preparers as $preparer) {
                $request = $preparer->prepare($request);
            }
        }

        return $this->handler->handle($request);
    }
}
