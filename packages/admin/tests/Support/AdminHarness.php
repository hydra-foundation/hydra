<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Core\Testing\FakeContainer;
use Hydra\Admin\AdminController;
use Hydra\Admin\AdminServiceProvider;
use Hydra\Admin\Chrome;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Contracts\TimezoneInterface;
use Hydra\Admin\FixedTimezone;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Navigation;
use Hydra\Admin\Renderer;
use Hydra\Core\Security\Signer;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Csrf\CsrfGuard;
use Hydra\Http\CspNonce;
use Hydra\Http\Responder;
use Hydra\Session\Stores\ArraySessionStore;
use Hydra\Validation\Validator;
use Hydra\View\Contracts\ViewInterface;
use Hydra\View\PhpView;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The admin wired the way an application wires it, with the package's own
 * templates and a stand-in for the two layouts an application supplies. Real
 * collaborators throughout: a request goes in and rendered HTML comes out, so
 * a test can say what a visitor would see rather than what a mock was told.
 */
final class AdminHarness
{
    public readonly ModuleRegistry $registry;
    public readonly Navigation $navigation;
    public readonly Chrome $chrome;
    public readonly Renderer $renderer;
    public readonly Responder $responder;
    public readonly ViewInterface $view;
    public readonly RecordingDispatcher $events;
    public readonly AdminController $controller;
    public readonly FrozenClock $clock;

    /**
     * @param array<string, object> $services sources and modules, by service id
     * @param list<class-string<ModuleInterface>> $modules
     */
    public function __construct(
        array $services,
        array $modules,
        bool $allowed = true,
        string $prefix = '/admin',
        /** The zone the visitor reads in. UTC unless a test is about zones. */
        private readonly TimezoneInterface $timezone = new FixedTimezone,
    ) {
        $psr17 = new Psr17Factory;
        $session = new ArraySessionStore;
        $session->start();

        $this->registry = new ModuleRegistry(new FakeContainer($services), $modules, $prefix);
        $this->navigation = new Navigation($this->registry, new AdminsOnlyGate($allowed));
        $this->chrome = new Chrome($this->registry, $this->navigation);
        // One nonce for both, as the container binds it: the page stamps it on
        // what it vouches for and the responder stamps it on the directive
        // elements it appends, and a test holding two of them would pass while
        // the browser refused half the markup.
        $nonce = new CspNonce;

        $this->responder = new Responder($psr17, $psr17, $nonce);
        $this->view = new PhpView(
            dirname(__DIR__) . '/views',
            $nonce,
            new CsrfGuard($session, Signer::fromHex(str_repeat('ab', 32))),
            fallbacks: [AdminServiceProvider::views()],
        );
        $this->renderer = new Renderer($this->responder, $this->view);
        $this->events = new RecordingDispatcher;
        $this->clock = new FrozenClock;
        $this->controller = new AdminController(
            $this->registry,
            $this->chrome,
            $this->renderer,
            new AdminsOnlyGate($allowed),
            $this->responder,
            new Validator,
            $this->events,
            $this->clock,
            $this->timezone,
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $body
     */
    public function request(string $method, string $path, array $headers = [], array $body = []): ServerRequestInterface
    {
        $request = (new Psr17Factory)->createServerRequest($method, $path);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        return $this->withRouteAttributes($request->withQueryParams($query)->withParsedBody($body));
    }

    /**
     * What the router contributes: a screen declares "{id}" in its path and the
     * matched value arrives as a request attribute, which is where the
     * controller reads it from. A test names the row in the URL, as a browser
     * does, rather than restating it beside one.
     */
    private function withRouteAttributes(ServerRequestInterface $request): ServerRequestInterface
    {
        $path = $request->getUri()->getPath();
        $blueprint = $this->registry->fromPath($path);
        $screen = $blueprint === null
            ? null
            : $this->registry->screenAt($blueprint, $path, $request->getMethod());

        if ($blueprint === null || $screen === null) {
            return $request;
        }

        $given = explode('/', trim(substr($path, strlen($this->registry->root($blueprint))), '/'));

        foreach (explode('/', trim($screen->path(), '/')) as $position => $segment) {
            if (str_starts_with($segment, '{') && isset($given[$position])) {
                $request = $request->withAttribute(trim($segment, '{}'), $given[$position]);
            }
        }

        return $request;
    }

    /**
     * The frame htmx swaps: a screen without the surrounding page.
     *
     * @return array<string, string>
     */
    public function frame(): array
    {
        return ['HX-Request' => 'true', 'HX-Target' => 'div#' . Renderer::FRAME];
    }

    /**
     * The body a filter or a page link swaps: the table alone.
     *
     * @return array<string, string>
     */
    public function body(): array
    {
        return ['HX-Request' => 'true', 'HX-Target' => 'div#' . Renderer::BODY];
    }
}
