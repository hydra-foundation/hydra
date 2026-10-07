<?php

declare(strict_types=1);

namespace Hydra\CommonMark\Tests\Unit;

use Hydra\CommonMark\CommonMarkRenderer;
use Hydra\CommonMark\CommonMarkServiceProvider;
use Hydra\CommonMark\MarkdownOptions;
use Hydra\Core\Testing\FakeContainer;
use Hydra\View\Contracts\MarkdownInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The one binding a view needs for $this->markdown(), with the app's options. */
#[CoversClass(CommonMarkServiceProvider::class)]
final class CommonMarkServiceProviderTest extends TestCase
{
    public function test_markdown_resolves_to_one_commonmark_renderer(): void
    {
        $container = new FakeContainer;
        (new CommonMarkServiceProvider)->register($container);

        $markdown = $container->get(MarkdownInterface::class);

        $this->assertInstanceOf(CommonMarkRenderer::class, $markdown);
        $this->assertSame($markdown, $container->get(MarkdownInterface::class));
    }

    public function test_the_apps_options_reach_the_renderer(): void
    {
        $container = new FakeContainer;
        (new CommonMarkServiceProvider(new MarkdownOptions(headingIds: false)))->register($container);

        $html = (string) $container->get(MarkdownInterface::class)->toHtml('## Plain');

        $this->assertStringNotContainsString('id=', $html);
        $this->assertSame(false, $container->get(MarkdownOptions::class)->headingIds);
    }
}
