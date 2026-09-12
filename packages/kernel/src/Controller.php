<?php

declare(strict_types=1);

namespace Hydra\Kernel;

use Hydra\Http\Exceptions\HttpException;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\View\Contracts\ViewInterface;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Base controller
 *
 * Response and view helpers every HTML controller wants
 */
abstract class Controller
{
    public function __construct(
        protected readonly Responder $respond,
        protected readonly ViewInterface $view,
    ) {}

    /**
     * Render a template to an HTML response
     */
    /** @param array<string, mixed> $data */
    protected function render(string $template, array $data = [], int|Status $status = Status::Ok, bool $layout = true): Response
    {
        return $this->respond->html($this->view->render($template, $data, $layout), $status);
    }

    /**
     * Stop handling and signal an HTTP error condition
     */
    protected function abort(int|Status $status, string $message = ''): never
    {
        throw new HttpException(Status::toInt($status), $message);
    }
}
