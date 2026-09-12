<?php

declare(strict_types=1);

namespace Hydra\Kernel;

use Hydra\Http\Exceptions\HttpException;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\View\Contracts\ViewInterface;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Response and view helpers every HTML controller wants.
 */
abstract class Controller
{
    public function __construct(
        protected readonly Responder $respond,
        protected readonly ViewInterface $view,
    ) {}

    /** @param array<string, mixed> $data */
    protected function render(string $template, array $data = [], int|Status $status = Status::Ok, bool $layout = true): Response
    {
        return $this->respond->html($this->view->render($template, $data, $layout), $status);
    }

    /** Lets a controller bail without having to build a response to bail with. */
    protected function abort(int|Status $status, string $message = ''): never
    {
        throw new HttpException(Status::toInt($status), $message);
    }
}
