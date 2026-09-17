<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\Controllers;

use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Attributes\Route;
use Hydra\Http\Htmx;
use Hydra\Http\ParsedBody;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\Kernel\Controller;
use Hydra\Tests\Fixture\Http\Middleware\LoginThrottleMiddleware;
use Hydra\Tests\Fixture\Http\Middleware\RedirectAuthenticatedMiddleware;
use Hydra\Tests\Fixture\ViewModels\LoginViewModel;
use Hydra\Validation\Rules\MaxLength;
use Hydra\Validation\Rules\Required;
use Hydra\Validation\Validator;
use Hydra\View\Contracts\ViewInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sign in and out, with the two things that make the route worth having in a
 * fixture: route middleware (a budget of its own, and a bounce for anyone
 * already signed in) and two render depths off one action.
 */
final class AuthController extends Controller
{
    private const MAX_USERNAME = 255;
    private const MAX_PASSWORD = 4096;

    public function __construct(
        Responder $respond,
        ViewInterface $view,
        private readonly GuardInterface $guard,
        private readonly Validator $validator,
    ) {
        parent::__construct($respond, $view);
    }

    #[Route('/login', middleware: [RedirectAuthenticatedMiddleware::class])]
    public function showLogin(): Response
    {
        return $this->render('auth/login/index', ['vm' => new LoginViewModel]);
    }

    #[Route('/login', methods: ['POST'], middleware: [LoginThrottleMiddleware::class, RedirectAuthenticatedMiddleware::class])]
    public function login(Request $request): Response
    {
        $input = ParsedBody::fromRequest($request);
        $username = trim($input->string('username'));
        $password = $input->string('password');

        $result = $this->validator->validate(
            ['username' => $username, 'password' => $password],
            [
                'username' => [
                    new Required('Enter your username.'),
                    new MaxLength(self::MAX_USERNAME, 'Enter your username.'),
                ],
                'password' => [
                    new Required('Enter your password.'),
                    new MaxLength(self::MAX_PASSWORD, 'Enter your password.'),
                ],
            ],
        );

        if ($result->passes() && $this->guard->attempt($username, $password)) {
            return $this->respond->redirect('/admin');
        }

        // Deliberately vague, and deliberately not attached to a field: naming
        // the half that was wrong is what tells a guesser which half to keep.
        $errors = $result->passes()
            ? ['credentials' => "Those credentials don't match our records."]
            : $result->errors();

        return $this->render(
            Htmx::fromRequest($request)->isHtmx() ? 'auth/login/form' : 'auth/login/index',
            ['vm' => new LoginViewModel($username, $errors)],
            Status::UnprocessableEntity,
        );
    }

    #[Route('/logout', methods: ['POST'])]
    public function logout(): Response
    {
        $this->guard->logout();

        return $this->respond->redirect('/login');
    }
}
