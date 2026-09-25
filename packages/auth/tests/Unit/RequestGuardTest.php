<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use DateTimeImmutable;
use Hydra\Auth\ApiToken;
use Hydra\Auth\AuthenticatedToken;
use Hydra\Auth\RequestGuard;
use Hydra\Auth\Testing\FakeGuard;
use Hydra\Auth\Testing\FakeUser;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestGuard::class)]
final class RequestGuardTest extends TestCase
{
    private static function bearer(int|string $userId): AuthenticatedToken
    {
        return new AuthenticatedToken(
            new ApiToken(7, $userId, 'CLI', str_repeat('a', 64), new DateTimeImmutable('2026-09-25')),
            new FakeUser($userId),
        );
    }

    public function test_a_bearer_token_answers_for_the_request(): void
    {
        $guard = new RequestGuard(new FakeGuard);

        $guard->useToken(self::bearer('grace'));

        $this->assertTrue($guard->check());
        $this->assertSame('grace', $guard->user()?->getAuthIdentifier());
        $this->assertSame('grace', $guard->id());
        $this->assertSame(7, $guard->token()?->id);
    }

    public function test_a_bearer_request_never_falls_back_to_the_signed_in_session(): void
    {
        $session = new FakeGuard(new FakeUser('ada'));
        $guard = new RequestGuard($session);

        $guard->useToken(self::bearer('grace'));

        $this->assertSame('grace', $guard->id());
        $this->assertSame('grace', $guard->user()?->getAuthIdentifier());
    }

    public function test_without_a_token_it_answers_from_the_session(): void
    {
        $guard = new RequestGuard(new FakeGuard(new FakeUser('ada')));

        $this->assertSame('ada', $guard->id());
        $this->assertNull($guard->token());
    }

    /** @return iterable<string, array{callable(RequestGuard): mixed}> */
    public static function sessionVerbs(): iterable
    {
        yield 'attempt' => [fn (RequestGuard $g) => $g->attempt('ada', 'secret')];
        yield 'login' => [fn (RequestGuard $g) => $g->login(new FakeUser('ada'))];
        yield 'refresh' => [fn (RequestGuard $g) => $g->refresh(new FakeUser('grace'))];
        yield 'logout' => [fn (RequestGuard $g) => $g->logout()];
    }

    #[DataProvider('sessionVerbs')]
    public function test_a_bearer_request_cannot_sign_in_or_out(callable $verb): void
    {
        $session = new FakeGuard(new FakeUser('ada'));
        $guard = new RequestGuard($session);
        $guard->useToken(self::bearer('grace'));

        try {
            $verb($guard);
            $this->fail('A bearer-authenticated request reached the session.');
        } catch (LogicException) {
        }

        $this->assertSame('ada', $session->id(), 'the session was changed');
    }
}
