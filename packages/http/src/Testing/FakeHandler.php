<?php

declare(strict_types=1);

namespace Hydra\Http\Testing;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * The handler at the far end of a middleware under test: it answers with a
 * fixed response, or throws, and keeps every request that reached it.
 *
 * A middleware test asks two things of what sits behind it, whether the
 * request got through and what it looked like when it did, and this answers
 * both. The response is handed in rather than built here, because this package
 * names no PSR-7 implementation.
 */
final class FakeHandler implements RequestHandlerInterface
{
    /** @var list<ServerRequestInterface> */
    private array $requests = [];

    /** @var array<string, ResponseInterface> */
    private array $paths = [];

    private function __construct(private readonly ResponseInterface|Throwable $answer) {}

    public static function respondingWith(ResponseInterface $response): self
    {
        return new self($response);
    }

    /** Throws $failure for every request, after keeping it. */
    public static function throwing(Throwable $failure): self
    {
        return new self($failure);
    }

    /** Answer a request for exactly $path with $response instead. */
    public function respondTo(string $path, ResponseInterface $response): self
    {
        $this->paths[$path] = $response;

        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $answer = $this->paths[$request->getUri()->getPath()] ?? $this->answer;

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }

    /**
     * Every request that reached the handler, oldest first.
     *
     * @return list<ServerRequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /** The most recent request, failing the test when none arrived. */
    public function lastRequest(): ServerRequestInterface
    {
        $request = $this->requests[array_key_last($this->requests) ?? -1] ?? null;

        Assert::assertNotNull($request, 'No request reached the handler.');

        return $request;
    }

    public function assertHandled(int $times = 1): void
    {
        $count = count($this->requests);

        Assert::assertSame($times, $count, "Expected {$times} requests to reach the handler; {$count} did.");
    }

    /** The middleware answered on its own, which is what a refusal looks like from here. */
    public function assertNotHandled(): void
    {
        $this->assertHandled(0);
    }
}
