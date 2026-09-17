<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\Controllers;

use Hydra\Http\Attributes\Route;
use Hydra\Kernel\Controller;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * The one public route: what an anonymous visitor is served, and the page every
 * flow about the pipeline itself drives.
 */
final class HomeController extends Controller
{
    #[Route('/')]
    public function index(): Response
    {
        return $this->render('home');
    }
}
