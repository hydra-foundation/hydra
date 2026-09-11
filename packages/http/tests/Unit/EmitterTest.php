<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Emitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class EmitterTest extends TestCase
{
    /**
     * Runs in its own process so emit()'s header() calls and the headers_sent()
     * guard are isolated from the rest of the suite. PHPUnit keeps an output
     * buffer open, so headers_sent() reports false and the guard lets the emit
     * proceed; a nested buffer captures the body the Emitter echoes.
     */
    #[RunInSeparateProcess]
    public function testEmitsBodyToOutput(): void
    {
        $psr17 = new Psr17Factory;
        $response = $psr17->createResponse(200)
            ->withBody($psr17->createStream('hello world'));

        ob_start();
        (new Emitter)->emit($response);
        $output = ob_get_clean();

        $this->assertSame('hello world', $output);
    }
}
