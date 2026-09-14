<?php

declare(strict_types=1);

namespace Hydra\Http\Testing;

use Hydra\Http\Contracts\ServerRequestProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The behaviour every request provider owes the kernel, published so a PSR-7
 * implementation the framework does not ship can be put behind the seam.
 *
 * This is the only place the superglobals are read, and everything downstream
 * is written against the request that comes out, so a provider that drops a
 * header or leaves the query string glued to the path does not fail here — it
 * fails in routing, or in CSRF, or in whatever middleware was relying on the
 * part that went missing.
 *
 * The superglobals are saved and put back around each test, because reading
 * them is the one thing this seam does and a case that left them rewritten
 * would be the cross-test leak the suite randomises its order to catch.
 */
abstract class ServerRequestProviderContractTestCase extends TestCase
{
    abstract protected function provider(): ServerRequestProviderInterface;

    /** @var array<string, array<string, mixed>> */
    private array $globals = [];

    protected function setUp(): void
    {
        $this->globals = ['server' => $_SERVER, 'get' => $_GET, 'post' => $_POST, 'cookie' => $_COOKIE];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->globals['server'];
        $_GET = $this->globals['get'];
        $_POST = $this->globals['post'];
        $_COOKIE = $this->globals['cookie'];
    }

    /**
     * Put a request into the environment and build it back out.
     *
     * @param array<string, mixed> $server
     */
    protected function fromGlobals(array $server = []): ServerRequestInterface
    {
        $_SERVER = $server + [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'hydra.test',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ];

        return $this->provider()->fromGlobals();
    }

    public function test_it_builds_a_psr_server_request(): void
    {
        $this->assertInstanceOf(ServerRequestInterface::class, $this->fromGlobals());
    }

    public function test_it_reads_the_method(): void
    {
        $this->assertSame('POST', $this->fromGlobals(['REQUEST_METHOD' => 'POST'])->getMethod());
    }

    public function test_it_reads_the_host(): void
    {
        $request = $this->fromGlobals(['HTTP_HOST' => 'example.test']);

        $this->assertSame('example.test', $request->getUri()->getHost());
    }

    public function test_the_path_does_not_carry_the_query_string(): void
    {
        // Routing matches on the path. A provider that leaves '?page=2' attached
        // turns every filtered listing into a 404.
        $request = $this->fromGlobals(['REQUEST_URI' => '/widgets?page=2&sort=name']);

        $this->assertSame('/widgets', $request->getUri()->getPath());
    }

    public function test_the_query_string_is_parsed_into_query_params(): void
    {
        $_GET = ['page' => '2', 'sort' => 'name'];
        $request = $this->fromGlobals(['REQUEST_URI' => '/widgets?page=2&sort=name', 'QUERY_STRING' => 'page=2&sort=name']);

        $this->assertSame(['page' => '2', 'sort' => 'name'], $request->getQueryParams());
    }

    public function test_request_headers_arrive_as_headers(): void
    {
        // Everything that negotiates — content type, Accept, htmx's own headers —
        // reaches the framework only through this translation.
        $request = $this->fromGlobals([
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('XMLHttpRequest', $request->getHeaderLine('X-Requested-With'));
    }

    public function test_header_names_are_case_insensitive(): void
    {
        $request = $this->fromGlobals(['HTTP_ACCEPT' => 'text/html']);

        $this->assertSame('text/html', $request->getHeaderLine('accept'));
        $this->assertSame('text/html', $request->getHeaderLine('ACCEPT'));
    }

    public function test_cookies_arrive_as_cookie_params(): void
    {
        // The session id lives here, so a provider that drops them logs
        // everybody out on every request.
        $_COOKIE = ['hydra_session' => 'abc123'];

        $this->assertSame(['hydra_session' => 'abc123'], $this->fromGlobals()->getCookieParams());
    }

    public function test_a_form_post_arrives_as_the_parsed_body(): void
    {
        // Where a CSRF token and every form field come from.
        $_POST = ['username' => 'will', '_token' => 'abc'];
        $request = $this->fromGlobals([
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ]);

        $this->assertSame(['username' => 'will', '_token' => 'abc'], $request->getParsedBody());
    }

    public function test_the_server_params_are_available(): void
    {
        // The client address is read from here, and it is what rate limiting and
        // the activity log key on.
        $request = $this->fromGlobals(['REMOTE_ADDR' => '203.0.113.7']);

        $this->assertSame('203.0.113.7', $request->getServerParams()['REMOTE_ADDR'] ?? null);
    }

    public function test_a_request_over_tls_is_reported_as_https(): void
    {
        $request = $this->fromGlobals(['HTTPS' => 'on', 'SERVER_PORT' => '443']);

        $this->assertSame('https', $request->getUri()->getScheme());
    }

    public function test_a_plain_request_is_not_reported_as_https(): void
    {
        // The direction that matters: a provider that reported https for a plain
        // request would let a secure-only cookie be set over one.
        $this->assertSame('http', $this->fromGlobals()->getUri()->getScheme());
    }
}
