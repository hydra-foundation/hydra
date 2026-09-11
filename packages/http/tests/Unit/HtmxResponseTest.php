<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\HtmxResponse;
use Hydra\Http\Responder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Directives reach an htmx 4 client in the body or not at all, so every
 * assertion here is about markup. A test that checked a header would pass
 * against a client that stopped reading headers two major versions ago.
 */
final class HtmxResponseTest extends TestCase
{
    public function testAMarkerCarriesTheDirectiveAndNothingIsLeftInTheHeaders(): void
    {
        $response = $this->responder()->htmx()
            ->redirect('/login')
            ->applyTo($this->html(''));

        $this->assertSame('<div data-hydra-redirect="/login" hidden></div>', $this->body($response));
        $this->assertSame([], array_filter(
            array_keys($response->getHeaders()),
            static fn (string $name): bool => str_starts_with(strtolower($name), 'hx-'),
        ));
    }

    public function testTheMarkerIsAppendedToContentRatherThanReplacingIt(): void
    {
        $response = $this->responder()->htmx()
            ->pushUrl('/users')
            ->applyTo($this->html('<table id="rows"></table>'));

        $this->assertSame(
            '<table id="rows"></table><div data-hydra-push-url="/users" hidden></div>',
            $this->body($response),
        );
    }

    public function testSeveralDirectivesShareOneMarker(): void
    {
        $response = $this->responder()->htmx()
            ->pushUrl('/users')
            ->replaceUrl('/x')
            ->applyTo($this->html(''));

        $this->assertSame(
            '<div data-hydra-push-url="/users" data-hydra-replace-url="/x" hidden></div>',
            $this->body($response),
        );
    }

    public function testAListUrlSurvivesTheAttribute(): void
    {
        $response = $this->responder()->htmx()
            ->pushUrl('/admin/users?q=a&sort=id&dir=asc')
            ->applyTo($this->html(''));

        // Unescaped, the & would end the attribute at the first entity and the
        // pushed URL would lose its criteria.
        $this->assertStringContainsString('"/admin/users?q=a&amp;sort=id&amp;dir=asc"', $this->body($response));
        $this->assertSame('/admin/users?q=a&sort=id&dir=asc', HtmxResponse::directive($response, 'push-url'));
    }

    public function testRetargetWrapsTheBodyOutOfBand(): void
    {
        $response = $this->responder()->htmx()
            ->retarget('#app-error', 'innerHTML')
            ->applyTo($this->html('<p>Nope</p>'));

        // htmx drops an out-of-band element from the fragment after applying it,
        // so a body that is only this leaves the requesting element alone.
        $this->assertSame(
            '<div hx-swap-oob="innerHTML:#app-error"><p>Nope</p></div>',
            $this->body($response),
        );
    }

    public function testRetargetDefaultsToReplacingTheRegion(): void
    {
        $response = $this->responder()->htmx()
            ->retarget('#app-error')
            ->applyTo($this->html('<p>Nope</p>'));

        $this->assertStringContainsString('hx-swap-oob="outerHTML:#app-error"', $this->body($response));
    }

    public function testNothingAskedForChangesNothing(): void
    {
        $response = $this->responder()->htmx()->applyTo($this->html('<p>Fine</p>'));

        $this->assertSame('<p>Fine</p>', $this->body($response));
    }

    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    private function html(string $body): ResponseInterface
    {
        return $this->responder()->html($body);
    }

    private function responder(): Responder
    {
        $factory = new Psr17Factory;

        return new Responder($factory, $factory);
    }
}
