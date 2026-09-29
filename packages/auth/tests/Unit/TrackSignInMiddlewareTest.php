<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Closure;
use DateTimeImmutable;
use Hydra\Auth\ApiToken;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\SessionGuard;
use Hydra\Auth\SignIn;
use Hydra\Auth\Testing\ArraySignInStore;
use Hydra\Auth\Testing\ArrayUserProvider;
use Hydra\Auth\Testing\FakeHasher;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Auth\TrackSignInMiddleware;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Session\Stores\ArraySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * When and from where a sign-in was last seen, written at most once a minute
 * from the same place, and never at the cost of the request.
 */
#[CoversClass(TrackSignInMiddleware::class)]
#[CoversClass(SessionGuard::class)]
final class TrackSignInMiddlewareTest extends TestCase
{
    private ArrayUserProvider $provider;
    private ArraySignInStore $signIns;
    private FrozenClock $clock;
    private ArraySessionStore $session;

    /** @var AbstractLogger&object{lines: list<string>} */
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->provider = (new ArrayUserProvider)->add('ada', new FakeUser(1, 'hashed:ada'));
        $this->signIns = new ArraySignInStore;
        $this->clock = new FrozenClock('2026-09-29 10:00:00');
        $this->session = new ArraySessionStore;
        $this->session->start();
        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };
    }

    public function test_the_request_that_signs_in_is_recorded_with_its_address_and_agent(): void
    {
        $guard = $this->guard();

        $this->middleware($guard)->process($this->request(), $this->handler(fn () => $guard->login($this->ada())));

        $signIn = $this->only();
        $this->assertSame('203.0.113.7', $signIn->ip);
        $this->assertSame('Firefox', $signIn->userAgent);
    }

    public function test_a_second_request_within_a_minute_from_the_same_place_writes_nothing(): void
    {
        $this->signInFrom('203.0.113.7', 'Firefox');
        $this->clock->advance('+59 seconds');

        $this->process($this->request());

        $this->assertEquals(new DateTimeImmutable('2026-09-29 10:00:00'), $this->only()->lastSeenAt);
    }

    public function test_a_request_a_minute_later_is_recorded(): void
    {
        $this->signInFrom('203.0.113.7', 'Firefox');
        $this->clock->advance('+60 seconds');

        $this->process($this->request());

        $this->assertEquals(new DateTimeImmutable('2026-09-29 10:01:00'), $this->only()->lastSeenAt);
    }

    public function test_a_new_address_is_recorded_at_once(): void
    {
        $this->signInFrom('203.0.113.7', 'Firefox');
        $this->clock->advance('+5 seconds');

        $this->process($this->request(ip: '198.51.100.2'));

        $this->assertSame('198.51.100.2', $this->only()->ip);
        $this->assertEquals(new DateTimeImmutable('2026-09-29 10:00:05'), $this->only()->lastSeenAt);
    }

    public function test_a_new_agent_is_recorded_at_once(): void
    {
        $this->signInFrom('203.0.113.7', 'Firefox');

        $this->process($this->request(agent: 'Safari'));

        $this->assertSame('Safari', $this->only()->userAgent);
    }

    public function test_a_long_agent_is_cut_to_what_a_store_is_promised_to_fit(): void
    {
        $this->signInFrom('203.0.113.7', 'Firefox');

        $this->process($this->request(agent: str_repeat('é', 300)));

        $this->assertSame(str_repeat('é', 255), $this->only()->userAgent);
    }

    public function test_an_anonymous_request_records_nothing(): void
    {
        $this->process($this->request());

        $this->assertSame([], $this->signIns->forUser($this->ada()));
    }

    public function test_a_bearer_request_is_its_tokens_and_not_a_sign_ins(): void
    {
        $this->signInFrom('203.0.113.7', 'Firefox');
        $this->clock->advance('+2 minutes');
        $token = new ApiToken(1, 1, 'CLI', str_repeat('a', 64), $this->clock->now());

        $this->process($this->request(ip: '198.51.100.2')->withAttribute(ApiToken::class, $token));

        $this->assertSame('203.0.113.7', $this->only()->ip);
    }

    public function test_a_store_that_fails_to_write_is_logged_and_the_response_still_returned(): void
    {
        $this->signInFrom('203.0.113.7', 'Firefox');
        $broken = new class ($this->signIns) implements SignInStoreInterface {
            public function __construct(private readonly ArraySignInStore $inner) {}

            public function create(string $id, AuthenticatableInterface $user, DateTimeImmutable $at): SignIn
            {
                return $this->inner->create($id, $user, $at);
            }

            public function find(string $id): ?SignIn
            {
                return $this->inner->find($id);
            }

            public function forUser(AuthenticatableInterface $user): array
            {
                return [];
            }

            public function touch(string $id, DateTimeImmutable $at, ?string $ip, ?string $userAgent): void
            {
                throw new RuntimeException('disk full');
            }

            public function revoke(string $id): bool
            {
                return false;
            }

            public function revokeAll(AuthenticatableInterface $user, ?string $except = null): int
            {
                return 0;
            }

            public function prune(DateTimeImmutable $before): int
            {
                return 0;
            }
        };
        $guard = new SessionGuard($this->session, $this->provider, new FakeHasher, null, $broken, $this->clock);

        $response = $this->middleware($guard)->process($this->request(ip: '198.51.100.2'), $this->handler());

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(['Could not record where a sign-in was seen: disk full'], $this->logger->lines);
    }

    public function test_with_no_store_bound_nothing_is_tracked_and_nothing_fails(): void
    {
        $guard = new SessionGuard($this->session, $this->provider, new FakeHasher);
        $guard->login($this->ada());

        $response = $this->middleware($guard)->process($this->request(), $this->handler());

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame([], $this->logger->lines);
    }

    private function signInFrom(string $ip, string $agent): void
    {
        $guard = $this->guard();
        $this->middleware($guard)->process($this->request($ip, $agent), $this->handler(fn () => $guard->login($this->ada())));
    }

    /** One more request over the same session, as the next page load is. */
    private function process(ServerRequestInterface $request): void
    {
        $this->middleware($this->guard())->process($request, $this->handler());
    }

    private function only(): SignIn
    {
        $signIns = $this->signIns->forUser($this->ada());
        $this->assertCount(1, $signIns);

        return $signIns[0];
    }

    private function guard(): SessionGuard
    {
        return new SessionGuard($this->session, $this->provider, new FakeHasher, null, $this->signIns, $this->clock);
    }

    private function middleware(SessionGuard $guard): TrackSignInMiddleware
    {
        return new TrackSignInMiddleware($guard, $this->logger);
    }

    private function request(string $ip = '203.0.113.7', string $agent = 'Firefox'): ServerRequestInterface
    {
        return (new Psr17Factory)
            ->createServerRequest('GET', '/admin', ['REMOTE_ADDR' => $ip])
            ->withHeader('User-Agent', $agent);
    }

    private function handler(?Closure $during = null): RequestHandlerInterface
    {
        return new class ($during) implements RequestHandlerInterface {
            public function __construct(private readonly ?Closure $during) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->during)?->__invoke();

                return (new Psr17Factory)->createResponse(204);
            }
        };
    }

    private function ada(): AuthenticatableInterface
    {
        return $this->provider->byUsername('ada') ?? self::fail('ada is not seeded');
    }
}
