<?php

declare(strict_types=1);

namespace Hydra\Admin\Screens;

use Hydra\Admin\AdminController;
use Hydra\Admin\Contracts\ScreenInterface;
use Hydra\Admin\Contracts\SubmittableInterface;

/**
 * A screen that is just a template: a dashboard, a report, a settings page.
 * Left alone it renders through the admin's own controller; handledBy() points it
 * at one of your controller actions instead, which keeps the layout, breadcrumbs
 * and ability while the action is ordinary Hydra code. A page that saves adds
 * submittedTo(): the same URL then answers a POST too, which is what a settings
 * screen needs and a dashboard does not.
 */
final class PageScreen implements ScreenInterface, SubmittableInterface
{
    private string $path = '';
    private ?string $title = null;
    private ?string $ability = null;
    private ?string $presenter = null;

    /** @var array{0: class-string, 1: string}|null */
    private ?array $handler = null;

    /** @var array{0: class-string, 1: string}|null */
    private ?array $submit = null;

    private function __construct(
        private readonly string $name,
        private readonly string $template,
    ) {}

    public static function make(string $name, string $template): self
    {
        return new self($name, $template);
    }

    /** Path relative to the module root; '' is the module root itself. */
    public function at(string $path): self
    {
        $clone = clone $this;
        $clone->path = $path;

        return $clone;
    }

    public function title(string $title): self
    {
        $clone = clone $this;
        $clone->title = $title;

        return $clone;
    }

    /** @param class-string|null $ability */
    public function requires(?string $ability): self
    {
        $clone = clone $this;
        $clone->ability = $ability;

        return $clone;
    }

    /** @param class-string $presenter a PresenterInterface service id */
    public function presentedBy(string $presenter): self
    {
        $clone = clone $this;
        $clone->presenter = $presenter;

        return $clone;
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function handledBy(array $handler): self
    {
        $clone = clone $this;
        $clone->handler = $handler;

        return $clone;
    }

    /**
     * Where a submission of this page goes. Declaring one is what puts a POST
     * route at the page's own URL; without it the page only reads.
     *
     * @param array{0: class-string, 1: string} $handler
     */
    public function submittedTo(array $handler): self
    {
        $clone = clone $this;
        $clone->submit = $handler;

        return $clone;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function method(): string
    {
        return 'GET';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function handler(): array
    {
        return $this->handler ?? [AdminController::class, 'page'];
    }

    public function submitHandler(): ?array
    {
        return $this->submit;
    }

    public function ability(): ?string
    {
        return $this->ability;
    }

    public function template(): string
    {
        return $this->template;
    }

    public function heading(): ?string
    {
        return $this->title;
    }

    /** @return class-string|null */
    public function presenter(): ?string
    {
        return $this->presenter;
    }
}
