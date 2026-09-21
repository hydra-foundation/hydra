<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Emitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The CLI SAPI records no headers, so the header lines are read through the
 * constructor's stand-in for header() and only the body through real output.
 */
#[CoversClass(Emitter::class)]
final class EmitterTest extends TestCase
{
    /**
     * Runs in its own process so emit()'s header() calls and the headers_sent()
     * guard are isolated from the rest of the suite. PHPUnit keeps an output
     * buffer open, so headers_sent() reports false and the guard lets the emit
     * proceed; a nested buffer captures the body the Emitter echoes.
     */
    #[RunInSeparateProcess]
    public function test_emits_body_to_output(): void
    {
        $psr17 = new Psr17Factory;
        $response = $psr17->createResponse(200)
            ->withBody($psr17->createStream('hello world'));

        ob_start();
        (new Emitter)->emit($response);
        $output = ob_get_clean();

        $this->assertSame('hello world', $output);
    }

    #[RunInSeparateProcess]
    public function test_a_name_replaces_on_its_first_value_and_appends_after_it(): void
    {
        $calls = [];
        $response = (new Psr17Factory)->createResponse(304)
            ->withHeader('Cache-Control', 'public, max-age=0')
            ->withHeader('Set-Cookie', ['a=1', 'b=2']);

        ob_start();
        (new Emitter(function (string $line, bool $replace, int $code = 0) use (&$calls): void {
            $calls[] = [$line, $replace];
        }))->emit($response);
        ob_end_clean();

        $this->assertSame([
            ['HTTP/1.1 304 Not Modified', true],
            ['Cache-Control: public, max-age=0', true],
            ['Set-Cookie: a=1', true],
            ['Set-Cookie: b=2', false],
        ], $calls);
    }
}
