<?php

declare(strict_types=1);

namespace Hydra\Kernel\Tests\Unit;

use Hydra\Http\Exceptions\HttpException;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Hydra\Kernel\Controller;
use Hydra\View\Testing\FakeView;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Exercises the base controller's two helpers directly. The view is a fake that
 * echoes its arguments, so render() can be asserted without a real template.
 */
#[CoversClass(Controller::class)]
final class ControllerTest extends TestCase
{
    private FakeView $view;

    protected function setUp(): void
    {
        $this->view = (new FakeView)
            ->define('blog/show', 'SHOW', static fn (string $body): string => "[{$body}]")
            ->define('form');
    }

    private function controller(): TestController
    {
        $psr17 = new Psr17Factory;

        return new TestController(new Responder($psr17, $psr17), $this->view);
    }

    public function test_render_returns_an_html_response_from_the_view(): void
    {
        $response = $this->controller()->doRender('blog/show', ['x' => 1], Status::Ok, false);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertSame('SHOW', (string) $response->getBody());
        $this->view->assertRendered('blog/show', ['x' => 1]);
        $this->view->assertRenderedFragment('blog/show');
    }

    public function test_render_honours_a_non_ok_status(): void
    {
        $response = $this->controller()->doRender('form', [], Status::UnprocessableEntity);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_abort_throws_an_http_exception_with_status_and_message(): void
    {
        try {
            $this->controller()->doAbort(403, 'not yours');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status());
            $this->assertSame('not yours', $e->getMessage());
        }
    }
}

/** Surfaces the protected helpers for the test. */
final class TestController extends Controller
{
    /** @param array<string, mixed> $data */
    public function doRender(string $template, array $data = [], int|Status $status = Status::Ok, bool $layout = true): ResponseInterface
    {
        return $this->render($template, $data, $status, $layout);
    }

    public function doAbort(int|Status $status, string $message = ''): never
    {
        $this->abort($status, $message);
    }
}
