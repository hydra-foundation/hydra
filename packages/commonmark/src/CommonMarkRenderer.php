<?php

declare(strict_types=1);

namespace Hydra\CommonMark;

use Hydra\View\Contracts\MarkdownInterface;
use Hydra\View\Document;
use Hydra\View\HtmlView;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkProcessor;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkProcessor;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown through league/commonmark, with the defaults a public page needs.
 * Two converters, each built the first time it is asked for: one escapes raw
 * HTML, one passes it, so trust is chosen per call and never a setting
 * someone forgets to turn back off. Unsafe links are refused by both.
 *
 * Nothing outside this package names League\CommonMark: an app types
 * against MarkdownInterface, and could swap this for another library.
 */
final class CommonMarkRenderer implements MarkdownInterface
{
    /** @var array{0?: MarkdownConverter, 1?: MarkdownConverter} untrusted, trusted */
    private array $converters = [];

    public function __construct(private readonly MarkdownOptions $options = new MarkdownOptions) {}

    public function toHtml(string $markdown, bool $trusted = false): HtmlView
    {
        return new HtmlView($this->converter($trusted)->convert($markdown)->getContent());
    }

    public function parse(string $source, bool $trusted = false): Document
    {
        $result = $this->converter($trusted)->convert($source);
        $meta = $result instanceof RenderedContentWithFrontMatter ? $result->getFrontMatter() : [];

        /** @var array<string, mixed> $meta YamlFrontMatter only ever returns a mapping */
        return new Document($meta ?? [], new HtmlView($result->getContent()));
    }

    private function converter(bool $trusted): MarkdownConverter
    {
        return $this->converters[(int) $trusted] ??= new MarkdownConverter($this->environment($trusted));
    }

    private function environment(bool $trusted): Environment
    {
        $environment = new Environment([
            // GFM's disallowed-raw-HTML filter still runs when trusted, so
            // even the owner's raw HTML can't carry <script> or <iframe>.
            'html_input' => $trusted ? 'allow' : 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => $this->options->maxNesting,
            'heading_permalink' => [
                'apply_id_to_heading' => true,
                'insert' => HeadingPermalinkProcessor::INSERT_NONE,
                'id_prefix' => '',
                'fragment_prefix' => '',
            ],
            'external_link' => [
                'internal_hosts' => $this->options->internalHosts,
                'open_in_new_window' => false,
                'noopener' => ExternalLinkProcessor::APPLY_EXTERNAL,
                'noreferrer' => ExternalLinkProcessor::APPLY_EXTERNAL,
                'nofollow' => ExternalLinkProcessor::APPLY_NONE,
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new ExternalLinkExtension);
        $environment->addExtension(new FrontMatterExtension(new YamlFrontMatter));

        if ($this->options->headingIds) {
            $environment->addExtension(new HeadingPermalinkExtension);
        }

        return $environment;
    }
}
