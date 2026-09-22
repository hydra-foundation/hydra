<?php

declare(strict_types=1);

namespace Hydra\View\Testing;

use Closure;
use Hydra\View\Contracts\ViewInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

/**
 * A view whose templates are defined in the test and which records every
 * render, for asserting on.
 *
 * A controller test usually cares which template was rendered, with what, and
 * whether the layout came with it, not what the markup looks like. The shipped
 * {@see \Hydra\View\PhpView} needs a directory of real templates to answer
 * that. This needs each name defined once, and renders it as a closure over
 * the data or as a fixed string.
 *
 * A name that was not defined throws rather than rendering empty, so a typo in
 * a controller's template name fails the test that should have caught it.
 */
final class FakeView implements ViewInterface
{
    /** @var array<string, array{body: Closure(array<string, mixed>): string, layout: (Closure(string): string)|null}> */
    private array $templates = [];

    /** @var list<array{template: string, data: array<string, mixed>, layout: bool}> */
    private array $renders = [];

    /**
     * @param string|(Closure(array<string, mixed>): string) $body
     * @param (Closure(string): string)|null $layout wraps the body unless a render declines it
     */
    public function define(string $template, string|Closure $body = '', ?Closure $layout = null): self
    {
        $this->templates[$template] = [
            'body' => is_string($body) ? static fn (): string => $body : $body,
            'layout' => $layout,
        ];

        return $this;
    }

    public function render(string $template, array $data = [], bool $layout = true): string
    {
        $defined = $this->templates[$template]
            ?? throw new InvalidArgumentException("The template {$template} was not defined on this FakeView.");

        $this->renders[] = ['template' => $template, 'data' => $data, 'layout' => $layout];

        $html = ($defined['body'])($data);

        return $layout && $defined['layout'] !== null ? ($defined['layout'])($html) : $html;
    }

    public function has(string $template): bool
    {
        return isset($this->templates[$template]);
    }

    /**
     * Every render made, oldest first.
     *
     * @return list<array{template: string, data: array<string, mixed>, layout: bool}>
     */
    public function renders(): array
    {
        return $this->renders;
    }

    /**
     * The template was rendered at least once, and with data matching $data
     * when given: every key in it present with an identical value. Other keys
     * in the render are ignored.
     *
     * @param array<string, mixed>|null $data
     */
    public function assertRendered(string $template, ?array $data = null): void
    {
        $matching = array_filter(
            $this->renders,
            static fn (array $render): bool => $render['template'] === $template
                && ($data === null || array_intersect_key($render['data'], $data) === $data),
        );

        $message = $data === null
            ? "The template {$template} was never rendered."
            : "The template {$template} was never rendered with that data.";

        Assert::assertNotEmpty($matching, $message);
    }

    /** The template was rendered without its layout, as an htmx fragment response wants. */
    public function assertRenderedFragment(string $template): void
    {
        $matching = array_filter(
            $this->renders,
            static fn (array $render): bool => $render['template'] === $template && !$render['layout'],
        );

        Assert::assertNotEmpty($matching, "The template {$template} was never rendered without its layout.");
    }

    public function assertNotRendered(string $template): void
    {
        $count = count(array_filter(
            $this->renders,
            static fn (array $render): bool => $render['template'] === $template,
        ));

        Assert::assertSame(0, $count, "The template {$template} was rendered {$count} times.");
    }

    public function assertNothingRendered(): void
    {
        Assert::assertSame([], $this->renders, count($this->renders) . ' templates were rendered.');
    }
}
