<?php

declare(strict_types=1);

namespace Hydra\Csrf\Tests\Unit;

use Hydra\Core\Security\Signer;
use Hydra\Csrf\CsrfGuard;
use Hydra\Csrf\Testing\CarriesCsrfToken;
use Hydra\Session\Stores\ArraySessionStore;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CarriesCsrfToken::class)]
final class CarriesCsrfTokenTest extends TestCase
{
    private ArraySessionStore $session;

    private CsrfGuard $guard;

    protected function setUp(): void
    {
        $this->session = new ArraySessionStore;
        $this->guard = new CsrfGuard($this->session, Signer::fromHex(str_repeat('ab', 32)));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafe(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    #[DataProvider('unsafe')]
    public function test_an_unsafe_request_carries_a_token_the_guard_accepts(string $method): void
    {
        $request = $this->preparer()->prepare(new ServerRequest($method, '/posts'));

        $this->assertTrue($this->guard->validate($request->getHeaderLine(CsrfGuard::HEADER)));
    }

    public function test_the_session_is_started_before_the_token_is_minted(): void
    {
        // A token minted into a session that was never started is written
        // nowhere, and the request carrying it fails the check it was meant to pass.
        $this->preparer()->prepare(new ServerRequest('POST', '/posts'));

        $this->assertTrue($this->guard->issued());
    }

    public function test_a_safe_request_is_left_alone(): void
    {
        $request = new ServerRequest('GET', '/posts');

        $this->assertSame($request, $this->preparer()->prepare($request));

        $this->session->start();
        $this->assertFalse($this->guard->issued());
    }

    public function test_a_token_the_test_chose_is_kept(): void
    {
        $request = (new ServerRequest('POST', '/posts'))->withHeader(CsrfGuard::HEADER, 'from-an-expired-session');

        $this->assertSame('from-an-expired-session', $this->preparer()->prepare($request)->getHeaderLine(CsrfGuard::HEADER));
    }

    private function preparer(): CarriesCsrfToken
    {
        return new CarriesCsrfToken($this->guard, $this->session);
    }
}
