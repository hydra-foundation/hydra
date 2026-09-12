<?php

declare(strict_types=1);

namespace Hydra\Http;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Http\Contracts\ArgumentResolverInterface;
use Hydra\Http\Exceptions\MethodNotAllowedException;
use Hydra\Http\Exceptions\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The innermost handler of the pipeline: matches a request to a route, then
 * resolves and invokes its target.
 *
 * @phpstan-import-type RouteDefinition from RouteScanner
 */
final class Router implements RequestHandlerInterface
{
    /** @var list<CompiledRoute> */
    private array $routes = [];

    private readonly ArgumentResolverInterface $arguments;

    public function __construct(
        private readonly ContainerInterface $container,
        ?ArgumentResolverInterface $arguments = null,
    ) {
        $this->arguments = $arguments ?? new ArgumentResolver;
    }

    /** @param list<class-string> $middleware */
    public function add(string $method, string $path, mixed $target, array $middleware = []): self
    {
        $this->routes[] = new CompiledRoute(strtoupper($method), $this->normalize($path), $target, $middleware);

        return $this;
    }

    /**
     * Bulk-register routes from a scanned/compiled definition list, e.g. the
     * output of RouteScanner::scan() (or a cached version of it).
     *
     * @param iterable<RouteDefinition> $routes
     */
    public function loadRoutes(iterable $routes): self
    {
        foreach ($routes as $route) {
            $this->add($route['method'], $route['path'], $route['handler'], $route['middleware'] ?? []);
        }

        return $this;
    }

    /** @param list<class-string> $middleware */
    public function get(string $path, mixed $target, array $middleware = []): self
    {
        return $this->add('GET', $path, $target, $middleware);
    }

    /** @param list<class-string> $middleware */
    public function post(string $path, mixed $target, array $middleware = []): self
    {
        return $this->add('POST', $path, $target, $middleware);
    }

    /** @param list<class-string> $middleware */
    public function put(string $path, mixed $target, array $middleware = []): self
    {
        return $this->add('PUT', $path, $target, $middleware);
    }

    /** @param list<class-string> $middleware */
    public function patch(string $path, mixed $target, array $middleware = []): self
    {
        return $this->add('PATCH', $path, $target, $middleware);
    }

    /** @param list<class-string> $middleware */
    public function delete(string $path, mixed $target, array $middleware = []): self
    {
        return $this->add('DELETE', $path, $target, $middleware);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $this->normalize($request->getUri()->getPath());

        // Methods registered for this path, used to answer 405 correctly.
        $allowed = [];

        foreach ($this->routes as $route) {
            $params = $route->matchPath($path);

            if ($params === null) {
                continue;
            }

            if (!$this->methodMatches($route->method, $method)) {
                $allowed[] = $route->method;
                continue;
            }

            // Also expose params as request attributes, so middleware and
            // anything holding the request can read them by name.
            foreach ($params as $name => $value) {
                $request = $request->withAttribute($name, $value);
            }

            return $this->toHandler($route, $params)->handle($request);
        }

        // The path matched but the verb did not: 405, carrying the verbs that
        // would have worked. Rendering (and the Allow header) is the error
        // handler's job — the router only signals the condition.
        if ($allowed !== []) {
            throw new MethodNotAllowedException($allowed);
        }

        throw new NotFoundException;
    }

    /**
     * Does a request method match a registered route's method?
     */
    private function methodMatches(string $routeMethod, string $requestMethod): bool
    {
        return $routeMethod === $requestMethod
            || ($requestMethod === 'HEAD' && $routeMethod === 'GET');
    }

    /**
     * Resolve a matched route into the handler that will answer the request:
     * the target wrapped in its per-route middleware, if any.
     *
     * @param array<string, string> $params
     */
    private function toHandler(CompiledRoute $route, array $params): RequestHandlerInterface
    {
        $handler = $this->resolveTarget($route->target, $params);

        if ($route->middleware === []) {
            return $handler;
        }

        // Wrap the target in a nested pipeline. A Pipeline is itself a handler,
        // so this composes inside the global pipeline without any special case:
        // global middleware → router → per-route middleware → target.
        $middleware = array_map(
            fn (string $class): MiddlewareInterface => $this->container->get($class),
            $route->middleware,
        );

        return new Pipeline($middleware, $handler);
    }

    /**
     * Resolve a route target into a request handler, using the container for classes.
     *
     * @param array<string, string> $params
     */
    private function resolveTarget(mixed $target, array $params): RequestHandlerInterface
    {
        if (is_array($target)) {
            [$class, $method] = $target;

            return new CallableHandler([$this->container->get($class), $method], $this->arguments, $params);
        }

        if (is_string($target)) {
            // Invokable controller class (has __invoke).
            return new CallableHandler($this->container->get($target), $this->arguments, $params);
        }

        // Closure / already-callable target.
        return new CallableHandler($target, $this->arguments, $params);
    }

    /** Collapse surrounding slashes so "/health" and "/health/" match the same route. */
    private function normalize(string $path): string
    {
        return '/' . trim($path, '/');
    }
}
