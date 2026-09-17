<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\Controllers;

use Hydra\Admin\Navigation;
use Hydra\Auth\AuthenticateMiddleware;
use Hydra\Http\Attributes\Route;
use Hydra\Http\Attributes\RouteGroup;
use Hydra\Http\Responder;
use Hydra\Kernel\Controller;
use Hydra\View\Contracts\ViewInterface;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Owns /admin itself. The screens below it belong to the modules, so all this
 * holds is the group that puts the whole area behind authentication, and a root
 * that names the landing module rather than a path.
 *
 * Asking Navigation for it is the point: the landing module is the first one
 * *this visitor* may reach, so a root that hard-coded a slug would send a
 * plain user to a screen their own sidebar does not offer.
 */
#[RouteGroup('/admin', middleware: [AuthenticateMiddleware::class])]
final class AdminController extends Controller
{
    public function __construct(
        Responder $respond,
        ViewInterface $view,
        private readonly Navigation $navigation,
    ) {
        parent::__construct($respond, $view);
    }

    #[Route('/')]
    public function index(): Response
    {
        return $this->respond->redirect($this->navigation->home());
    }
}
