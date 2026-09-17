<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\Providers;

use Hydra\Admin\AdminServiceProvider;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Csrf\CsrfGuard;
use Hydra\Csrf\VerifyCsrfTokenMiddleware;
use Hydra\Auth\Events\Attempting;
use Hydra\Auth\Events\LoggedIn;
use Hydra\Auth\Events\LoggedOut;
use Hydra\Auth\Events\LoginFailed;
use Hydra\Auth\LogAuthEventsListener;
use Hydra\Event\ListenerProvider;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Http\ClientIpResolver;
use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\Csp;
use Hydra\Http\CspMiddleware;
use Hydra\Http\CspNonce;
use Hydra\Http\ErrorHandlerMiddleware;
use Hydra\Http\ForceHttpsMiddleware;
use Hydra\Http\HtmxRedirectMiddleware;
use Hydra\Http\ParseBodyMiddleware;
use Hydra\Http\PlainTextErrorRenderer;
use Hydra\Http\RequestLoggingMiddleware;
use Hydra\Http\Responder;
use Hydra\Http\SecurityHeadersMiddleware;
use Hydra\Http\TrustedProxies;
use Hydra\Session\StartSessionMiddleware;
use Hydra\Tests\Fixture\Admin\Modules\DashboardModule;
use Hydra\Tests\Fixture\Admin\Modules\UsersModule;
use Hydra\Tests\Fixture\Config\CspConfig;
use Hydra\Tests\Fixture\Controllers\AdminController;
use Hydra\Tests\Fixture\Controllers\AuthController;
use Hydra\Tests\Fixture\Controllers\HomeController;
use Hydra\Tests\Fixture\Http\Middleware\RedirectUnauthenticatedMiddleware;
use Hydra\Tests\Fixture\Http\NegotiatingErrorRenderer;
use Hydra\Tests\Fixture\Repositories\UserRepository;
use Hydra\Throttle\RateLimitMiddleware;
use Hydra\View\Contracts\ViewInterface;
use Hydra\View\PhpView;
use Psr\Log\LoggerInterface;

/**
 * Where the fixture application is assembled.
 *
 * It is the application half of every seam the framework leaves open — the user
 * provider, the error renderer, the view and its templates — so that an
 * integration flow drives a real composition root rather than a container with
 * the interesting bindings stubbed out.
 */
final class FixtureServiceProvider extends ServiceProvider
{
    /** @var list<class-string> */
    public const CONTROLLERS = [
        HomeController::class,
        AuthController::class,
        AdminController::class,
    ];

    /** @var list<class-string<ModuleInterface>> */
    public const MODULES = [
        DashboardModule::class,
        UsersModule::class,
    ];

    /**
     * The global stack, outermost first. The order is the thing under test as
     * much as the middleware are: the limiter sits inside the error handler so
     * a refusal has a response to be, and ahead of the session, the body parser
     * and the database so a refused request costs nothing.
     *
     * @var list<class-string>
     */
    public const MIDDLEWARE = [
        RequestLoggingMiddleware::class,
        SecurityHeadersMiddleware::class,
        CspMiddleware::class,
        ForceHttpsMiddleware::class,
        ErrorHandlerMiddleware::class,
        RateLimitMiddleware::class,
        HtmxRedirectMiddleware::class,
        ParseBodyMiddleware::class,
        StartSessionMiddleware::class,
        RedirectUnauthenticatedMiddleware::class,
        VerifyCsrfTokenMiddleware::class,
    ];

    public function register(ContainerInterface $container): void
    {
        $container->singleton(CspConfig::class, fn (): CspConfig => new CspConfig);

        $container->singleton(UserProviderInterface::class, function () use ($container) {
            return new UserRepository($container->get(ConnectionInterface::class));
        });

        $container->singleton(ViewInterface::class, function () use ($container) {
            return new PhpView(
                dirname(__DIR__, 2) . '/views',
                $container->get(CspNonce::class),
                $container->get(CsrfGuard::class),
                fallbacks: [AdminServiceProvider::views()],
                shared: ['csp' => $container->get(CspConfig::class)],
            );
        });

        $container->singleton(SecurityHeadersMiddleware::class, function () use ($container) {
            return new SecurityHeadersMiddleware(
                $container->get(CspConfig::class)->enabled
                    ? SecurityHeadersMiddleware::WITH_CSP
                    : SecurityHeadersMiddleware::DEFAULTS,
            );
        });

        $container->singleton(CspMiddleware::class, function () use ($container) {
            $config = $container->get(CspConfig::class);

            $policy = Csp::default()
                ->with('img-src', "'self'", 'data:')
                ->with('style-src', "'self'", 'https://fonts.googleapis.com')
                ->with('font-src', "'self'", 'https://fonts.gstatic.com');

            if ($config->reportUri !== '') {
                $policy = $policy->with('report-uri', $config->reportUri);
            }

            return new CspMiddleware(
                $policy,
                $container->get(CspNonce::class),
                $config->enabled,
                $config->reportOnly,
            );
        });

        $container->singleton(TrustedProxies::class, fn (): TrustedProxies => new TrustedProxies([]));

        $container->singleton(ClientIpResolver::class, function () use ($container) {
            return new ClientIpResolver($container->get(TrustedProxies::class));
        });

        $container->singleton(ForceHttpsMiddleware::class, function () use ($container) {
            return new ForceHttpsMiddleware(
                false,
                $container->get(Responder::class),
                false,
                $container->get(ClientIpResolver::class),
            );
        });

        $container->singleton(ErrorRendererInterface::class, function () use ($container) {
            return new NegotiatingErrorRenderer(
                $container->get(Responder::class),
                new PlainTextErrorRenderer($container->get(Responder::class)),
            );
        });

        $container->singleton(ErrorHandlerMiddleware::class, function () use ($container) {
            return new ErrorHandlerMiddleware(
                $container->get(ErrorRendererInterface::class),
                false,
                $container->get(LoggerInterface::class),
            );
        });
    }

    /**
     * The listener registrations, which is the half a unit test cannot reach:
     * an event the guard announces only becomes a log line because four
     * packages were connected here, in process, at boot.
     */
    public function boot(ContainerInterface $container): void
    {
        $listeners = $container->get(ListenerProvider::class);
        $audit = new LogAuthEventsListener($container->get(LoggerInterface::class));

        $listeners->listen(Attempting::class, [$audit, 'onAttempting']);
        $listeners->listen(LoginFailed::class, [$audit, 'onFailed']);
        $listeners->listen(LoggedIn::class, [$audit, 'onLoggedIn']);
        $listeners->listen(LoggedOut::class, [$audit, 'onLoggedOut']);
    }
}
