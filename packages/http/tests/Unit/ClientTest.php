<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\ParseBodyMiddleware;
use Hydra\Http\Responder;
use Hydra\Http\Testing\Client;
use Hydra\Http\Testing\RequestPreparer;
use Hydra\Http\Testing\TestResponse;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Http\Tests\Support\RecordingHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(Client::class)]
final class ClientTest extends TestCase
{
    private RecordingHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new RecordingHandler;
    }

    public function test_a_get_reaches_the_handler_from_the_default_peer(): void
    {
        $response = $this->client()->get('/posts');

        $this->assertInstanceOf(TestResponse::class, $response);
        $this->assertSame('GET', $this->last()->getMethod());
        $this->assertSame('/posts', $this->last()->getUri()->getPath());
        $this->assertSame(Client::PEER, $this->last()->getServerParams()['REMOTE_ADDR']);
        $this->assertNull($this->last()->getParsedBody());
    }

    public function test_the_query_string_arrives_as_query_params(): void
    {
        $this->client()->get('/posts?page=2&tag[]=php');

        $this->assertSame('/posts', $this->last()->getUri()->getPath());
        $this->assertSame(['page' => '2', 'tag' => ['php']], $this->last()->getQueryParams());
    }

    public function test_a_post_arrives_parsed_and_raw(): void
    {
        $this->client()->post('/login', ['username' => 'will']);

        $this->assertSame(['username' => 'will'], $this->last()->getParsedBody());
        $this->assertSame('username=will', (string) $this->last()->getBody());
        $this->assertSame('application/x-www-form-urlencoded', $this->last()->getHeaderLine('Content-Type'));
    }

    public function test_other_methods_leave_parsing_to_the_application(): void
    {
        // PHP fills $_POST for POST alone, so a PUT the application forgot to
        // parse has to fail here the way it would in production.
        foreach (['put', 'patch', 'delete'] as $method) {
            $this->client()->{$method}('/posts/1', ['title' => 'new']);

            $this->assertSame(strtoupper($method), $this->last()->getMethod());
            $this->assertNull($this->last()->getParsedBody());
            $this->assertSame('title=new', (string) $this->last()->getBody());
        }
    }

    public function test_a_put_is_parsed_by_the_body_middleware(): void
    {
        $factory = new Psr17Factory;
        $parse = new ParseBodyMiddleware;
        $handler = $this->handler;
        $client = new Client(new class ($parse, $handler) implements RequestHandlerInterface {
            public function __construct(
                private readonly ParseBodyMiddleware $parse,
                private readonly RequestHandlerInterface $next,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->parse->process($request, $this->next);
            }
        }, $factory, $factory);

        $client->put('/posts/1', ['title' => 'new']);

        $this->assertSame(['title' => 'new'], $this->last()->getParsedBody());
    }

    public function test_headers_from_the_client_and_the_call_are_both_sent(): void
    {
        $this->client()->withHeader('Accept', 'text/html')->get('/', ['X-Trace' => '1']);

        $this->assertSame('text/html', $this->last()->getHeaderLine('Accept'));
        $this->assertSame('1', $this->last()->getHeaderLine('X-Trace'));
    }

    public function test_a_header_on_the_call_wins_over_the_client(): void
    {
        $this->client()->withHeader('Accept', 'text/html')->get('/', ['Accept' => 'application/json']);

        $this->assertSame('application/json', $this->last()->getHeaderLine('Accept'));
    }

    public function test_htmx_marks_the_request_and_names_the_target(): void
    {
        $this->client()->htmx()->get('/');
        $this->assertSame('true', $this->last()->getHeaderLine('HX-Request'));
        $this->assertFalse($this->last()->hasHeader('HX-Target'));

        $this->client()->htmx('tbody#rows')->get('/');
        $this->assertSame('tbody#rows', $this->last()->getHeaderLine('HX-Target'));
    }

    public function test_from_changes_the_peer(): void
    {
        $this->client()->from('203.0.113.9')->get('/');

        $this->assertSame('203.0.113.9', $this->last()->getServerParams()['REMOTE_ADDR']);
    }

    public function test_modifiers_leave_the_original_client_untouched(): void
    {
        $client = $this->client();
        $client->htmx();
        $client->from('203.0.113.9');
        $client->withHeader('Accept', 'text/html');

        $client->get('/');

        $this->assertFalse($this->last()->hasHeader('HX-Request'));
        $this->assertFalse($this->last()->hasHeader('Accept'));
        $this->assertSame(Client::PEER, $this->last()->getServerParams()['REMOTE_ADDR']);
    }

    public function test_preparers_run_in_order_and_can_be_skipped(): void
    {
        $client = $this->client([$this->stamp('first'), $this->stamp('second')]);

        $client->post('/posts');
        $this->assertSame(['first', 'second'], $this->last()->getHeader('X-Stamp'));

        $client->unprepared()->post('/posts');
        $this->assertFalse($this->last()->hasHeader('X-Stamp'));
    }

    public function test_send_dispatches_the_request_exactly_as_given(): void
    {
        $client = $this->client([$this->stamp('prepared')])->withHeader('Accept', 'text/html')->followingRedirects();
        $this->handler->routes['/old'] = new Response(302, ['Location' => '/new']);

        $response = $client->send($client->request('GET', '/old'));

        $response->assertRedirect('/new');
        $this->assertCount(1, $this->handler->seen);
        $this->assertFalse($this->last()->hasHeader('X-Stamp'));
        $this->assertFalse($this->last()->hasHeader('Accept'));
    }

    public function test_redirects_are_not_followed_unless_asked(): void
    {
        $this->handler->routes['/old'] = new Response(302, ['Location' => '/new']);

        $this->client()->get('/old')->assertRedirect('/new');
        $this->assertCount(1, $this->handler->seen);
    }

    public function test_a_redirect_after_a_post_is_followed_as_a_get_without_the_body(): void
    {
        $this->handler->routes['/login'] = new Response(302, ['Location' => '/admin']);

        $this->client()->followingRedirects()->post('/login', ['username' => 'will'])->assertOk();

        $this->assertSame(['POST /login', 'GET /admin'], $this->trail());
        $this->assertNull($this->last()->getParsedBody());
        $this->assertSame('', (string) $this->last()->getBody());
    }

    public function test_a_307_repeats_the_method_and_the_body(): void
    {
        $this->handler->routes['/old'] = new Response(307, ['Location' => '/new']);

        $this->client()->followingRedirects()->post('/old', ['title' => 'kept']);

        $this->assertSame(['POST /old', 'POST /new'], $this->trail());
        $this->assertSame(['title' => 'kept'], $this->last()->getParsedBody());
    }

    public function test_the_followed_request_keeps_the_client_headers_and_preparers(): void
    {
        $this->handler->routes['/old'] = new Response(302, ['Location' => '/new']);

        $this->client([$this->stamp('prepared')])->withHeader('Accept', 'text/html')->followingRedirects()->get('/old');

        $this->assertSame('text/html', $this->last()->getHeaderLine('Accept'));
        $this->assertSame('prepared', $this->last()->getHeaderLine('X-Stamp'));
    }

    public function test_an_htmx_redirect_is_followed_as_a_full_page(): void
    {
        $this->handler->routes['/login'] = (new Responder(new Psr17Factory, new Psr17Factory))
            ->htmx()
            ->redirect('/admin')
            ->applyTo(new Response(200));

        $this->client()->htmx('form#login')->followingRedirects()->post('/login', ['username' => 'will']);

        $this->assertSame(['POST /login', 'GET /admin'], $this->trail());
        $this->assertFalse($this->last()->hasHeader('HX-Request'));
        $this->assertFalse($this->last()->hasHeader('HX-Target'));
    }

    public function test_a_redirect_loop_fails_with_the_trail(): void
    {
        $this->handler->routes['/a'] = new Response(302, ['Location' => '/b']);
        $this->handler->routes['/b'] = new Response(302, ['Location' => '/a']);

        try {
            $this->client()->followingRedirects(3)->get('/a');
        } catch (AssertionFailedError $e) {
            $this->assertSame('More than 3 redirects: /a → /b → /a → /b → /a', $e->getMessage());
            $this->assertCount(4, $this->handler->seen);

            return;
        }

        $this->fail('A redirect loop was followed without failing.');
    }

    public function test_the_limit_is_the_number_of_redirects_followed(): void
    {
        $this->handler->routes['/a'] = new Response(302, ['Location' => '/b']);
        $this->handler->routes['/b'] = new Response(302, ['Location' => '/c']);

        $this->client()->followingRedirects(2)->get('/a')->assertOk();

        $this->assertSame(['GET /a', 'GET /b', 'GET /c'], $this->trail());
    }

    public function test_for_resolves_the_pipeline_from_the_container(): void
    {
        $container = new FakeContainer;
        $factory = new Psr17Factory;
        $container->instance(RequestHandlerInterface::class, $this->handler);
        $container->instance(ServerRequestFactoryInterface::class, $factory);
        $container->instance(StreamFactoryInterface::class, $factory);

        Client::for($container, [$this->stamp('prepared')])->post('/posts');

        $this->assertSame('prepared', $this->last()->getHeaderLine('X-Stamp'));
    }

    public function test_for_sees_a_handler_bound_after_the_client_was_made(): void
    {
        $container = new FakeContainer;
        $factory = new Psr17Factory;
        $container->instance(RequestHandlerInterface::class, new RecordingHandler);
        $container->instance(ServerRequestFactoryInterface::class, $factory);
        $container->instance(StreamFactoryInterface::class, $factory);

        $client = Client::for($container);
        $container->instance(RequestHandlerInterface::class, $this->handler);
        $client->get('/after');

        $this->assertSame('/after', $this->last()->getUri()->getPath());
    }

    /** @param list<RequestPreparer> $preparers */
    private function client(array $preparers = []): Client
    {
        $factory = new Psr17Factory;

        return new Client($this->handler, $factory, $factory, $preparers);
    }

    private function stamp(string $value): RequestPreparer
    {
        return new class ($value) implements RequestPreparer {
            public function __construct(private readonly string $value) {}

            public function prepare(ServerRequestInterface $request): ServerRequestInterface
            {
                return $request->withAddedHeader('X-Stamp', $this->value);
            }
        };
    }

    private function last(): ServerRequestInterface
    {
        $this->assertNotSame([], $this->handler->seen, 'Nothing reached the handler.');

        return $this->handler->seen[array_key_last($this->handler->seen)];
    }

    /** @return list<string> */
    private function trail(): array
    {
        return array_map(
            static fn (ServerRequestInterface $r): string => $r->getMethod() . ' ' . $r->getUri()->getPath(),
            $this->handler->seen,
        );
    }
}
