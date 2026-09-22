<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Testing\FakeHandler;
use LogicException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeHandler::class)]
final class FakeHandlerTest extends TestCase
{
    public function test_it_answers_with_the_response_it_was_given_and_keeps_the_request(): void
    {
        $response = new Response(201);
        $request = new ServerRequest('GET', '/a');
        $handler = FakeHandler::respondingWith($response);

        $this->assertSame($response, $handler->handle($request));
        $this->assertSame([$request], $handler->requests());
        $this->assertSame($request, $handler->lastRequest());
        $handler->assertHandled();
    }

    public function test_a_path_can_be_answered_differently(): void
    {
        $moved = new Response(302, ['Location' => '/new']);
        $handler = FakeHandler::respondingWith(new Response(200))->respondTo('/old', $moved);

        $this->assertSame($moved, $handler->handle(new ServerRequest('GET', '/old')));
        $this->assertSame(200, $handler->handle(new ServerRequest('GET', '/other'))->getStatusCode());
    }

    public function test_a_throwing_handler_keeps_the_request_before_it_throws(): void
    {
        $handler = FakeHandler::throwing(new LogicException('boom'));

        try {
            $handler->handle(new ServerRequest('POST', '/x'));
            $this->fail('The handler did not throw.');
        } catch (LogicException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $handler->assertHandled();
    }

    public function test_the_last_request_is_the_most_recent(): void
    {
        $handler = FakeHandler::respondingWith(new Response(200));
        $handler->handle(new ServerRequest('GET', '/first'));
        $second = new ServerRequest('GET', '/second');
        $handler->handle($second);

        $this->assertSame($second, $handler->lastRequest());
        $handler->assertHandled(2);
    }

    public function test_last_request_fails_when_nothing_arrived(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No request reached the handler.');

        FakeHandler::respondingWith(new Response(200))->lastRequest();
    }

    public function test_assert_handled_fails_with_both_counts(): void
    {
        $handler = FakeHandler::respondingWith(new Response(200));
        $handler->handle(new ServerRequest('GET', '/'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Expected 2 requests to reach the handler; 1 did.');
        $handler->assertHandled(2);
    }

    public function test_assert_not_handled_fails_once_a_request_arrived(): void
    {
        $handler = FakeHandler::respondingWith(new Response(200));
        $handler->handle(new ServerRequest('GET', '/'));

        $this->expectException(AssertionFailedError::class);
        $handler->assertNotHandled();
    }
}
