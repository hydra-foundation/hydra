<?php

declare(strict_types=1);

namespace Hydra\Admin\ViewModels;

use Hydra\Admin\Blueprint;
use Hydra\Admin\Input;
use Hydra\Admin\Screens\FormScreen;

/**
 * Everything a form reads: its controls, the values to put in them, and where to
 * post. Values come from one array whichever way the screen was reached (the
 * stored row on a GET, the rejected submission on a failed POST), so a form that
 * comes back with errors comes back with what the user typed.
 */
final readonly class FormViewModel
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    public function __construct(
        public Blueprint $blueprint,
        public FormScreen $screen,
        public ?string $id,
        public string $prefix,
        private array $values = [],
        private array $errors = [],
        /**
         * The view of the list this form was opened from, as a query string.
         * Cancel leads there, and the action carries it so that a submission
         * without htmx to report the page it came from can find it too.
         */
        private string $listQuery = '',
    ) {}

    /** @return list<Input> */
    public function controls(): array
    {
        return $this->screen->controls();
    }

    public function action(): string
    {
        return $this->root()
            . '/' . str_replace('{id}', rawurlencode($this->id ?? ''), trim($this->screen->path(), '/'))
            . $this->listQuery;
    }

    /**
     * Apply saves and stays. A create form has nowhere to stay: the row it wrote
     * has an id and lives at another URL, so it offers Save alone.
     */
    public function canApply(): bool
    {
        return $this->id !== null;
    }

    /** Where Cancel leads: the list as the visitor had it when they opened this form. */
    public function cancelUrl(): string
    {
        return $this->root() . $this->listQuery;
    }

    /** The module's own URL, which every screen of it hangs off. */
    private function root(): string
    {
        return rtrim($this->prefix, '/') . '/' . $this->blueprint->slug;
    }

    public function value(Input $input): string
    {
        return $input->valueFrom($this->values);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function hasError(string $name): bool
    {
        return isset($this->errors[$name]);
    }

    public function error(string $name): ?string
    {
        return $this->errors[$name] ?? null;
    }

    /**
     * Errors with no control to hang off, a rejected write being the usual one.
     *
     * @return list<string>
     */
    public function formErrors(): array
    {
        $named = array_map(static fn (Input $input): string => $input->name(), $this->controls());

        return array_values(array_diff_key($this->errors, array_flip($named)));
    }
}
