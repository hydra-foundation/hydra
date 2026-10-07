<?php

declare(strict_types=1);

namespace Hydra\Image;

use Hydra\Filesystem\Exceptions\InvalidKey;
use Hydra\Filesystem\Key;
use Hydra\View\Contracts\ImagesInterface;
use Hydra\View\HtmlView;
use Hydra\View\Variant;
use Psr\Log\LoggerInterface;

/**
 * Pictures at a preset's sizes, made with GD the first time a page asks and
 * kept on the public disk under `variants/`, where the web server serves
 * them from then on. A page whose copies exist costs a header read and one
 * file check per size.
 *
 * A source is a file under the document root (`/images/garden.jpg`) or a key
 * on the public disk (`posts/9c1e….jpg`). A copy's name hashes the source
 * (its key, or its path, size and modified time) with the preset, so an
 * edited picture or a changed preset makes new files instead of serving old
 * ones. Old copies stay until `image:variants --prune`.
 */
final class GdImages implements ImagesInterface
{
    /** Where copies live, inside the public disk. */
    public const DIRECTORY = 'variants';

    private readonly GdResizer $resizer;

    private readonly string $diskUrl;

    public function __construct(
        private readonly ImageOptions $options,
        private readonly string $documentRoot,
        private readonly string $diskRoot,
        string $diskUrl = '/storage',
        private readonly ?LoggerInterface $logger = null,
        ?GdResizer $resizer = null,
    ) {
        $this->resizer = $resizer ?? new GdResizer($options);
        $this->diskUrl = rtrim($diskUrl, '/');
    }

    public function img(string $source, string $preset, string $alt, ?string $sizes = null, bool $eager = false): HtmlView
    {
        $file = $this->locate($source);

        try {
            $variants = $this->made($file, $preset);
        } catch (ImageRefused $e) {
            $this->logger?->warning('{source} is shown as it is, without smaller copies: {reason}', [
                'source' => $source,
                'reason' => $e->getMessage(),
            ]);
            $variants = null;
        }

        if ($variants === null || $variants[0]->width === 0) {
            return new HtmlView(self::tag(['src' => $file['url'], 'alt' => $alt], $eager));
        }

        $src = $variants[intdiv(count($variants), 2)];
        $attributes = ['src' => $src->url];

        if (count($variants) > 1) {
            $attributes['srcset'] = implode(', ', array_map(static fn (Variant $v): string => "{$v->url} {$v->width}w", $variants));

            if ($sizes !== null) {
                $attributes['sizes'] = $sizes;
            }
        }

        return new HtmlView(self::tag([
            ...$attributes,
            'width' => (string) $src->width,
            'height' => (string) $src->height,
            'alt' => $alt,
        ], $eager));
    }

    public function variants(string $source, string $preset): array
    {
        return $this->made($this->locate($source), $preset);
    }

    /**
     * The files a source's copies are kept in, relative to the public disk,
     * without making them: what `image:variants --prune` keeps.
     *
     * @return list<string>
     *
     * @throws ImageNotFound|ImageRefused
     */
    public function files(string $source, string $preset): array
    {
        return array_keys($this->plan($this->locate($source), $preset));
    }

    /**
     * @param array{path: string, url: string, identity: string} $file
     * @return list<Variant>
     */
    private function made(array $file, string $preset): array
    {
        // Drawn at any size already: nothing to make.
        if (self::isVector($file['path'])) {
            $this->options->preset($preset);

            return [new Variant($file['url'], 0, 0)];
        }

        $plan = $this->plan($file, $preset);

        foreach (array_keys($plan) as $name) {
            if (!is_file($this->diskRoot . '/' . $name)) {
                $names = array_combine(array_map(static fn (Variant $v): int => $v->width, $plan), array_keys($plan));
                $this->resizer->make($file['path'], $this->options->preset($preset), fn (int $w): string => $this->diskRoot . '/' . $names[$w]);

                break;
            }
        }

        return array_values($plan);
    }

    /**
     * Each copy's file, relative to the public disk, and what it is.
     *
     * @param array{path: string, url: string, identity: string} $file
     * @return array<string, Variant>
     */
    private function plan(array $file, string $preset): array
    {
        $chosen = $this->options->preset($preset);

        if (self::isVector($file['path'])) {
            return [];
        }

        [$width, $height] = $this->resizer->inspect($file['path']);
        $hash = substr(hash('xxh128', json_encode([$file['identity'], $chosen->widths, $chosen->ratio, $this->options->quality], JSON_THROW_ON_ERROR)), 0, 16);
        $plan = [];

        foreach ($chosen->sizes($width, $height) as [$w, $h]) {
            $name = sprintf('%s/%s/%s-%d.webp', self::DIRECTORY, $preset, $hash, $w);
            $plan[$name] = new Variant($this->diskUrl . '/' . $name, $w, $h);
        }

        return $plan;
    }

    private static function isVector(string $path): bool
    {
        return str_ends_with(strtolower($path), '.svg');
    }

    /**
     * The source's file, the URL it is served at, and what identifies this
     * version of it.
     *
     * @return array{path: string, url: string, identity: string}
     *
     * @throws ImageNotFound
     */
    private function locate(string $source): array
    {
        if (str_contains($source, "\0") || strpbrk($source, '?#') !== false || str_starts_with($source, '//')) {
            throw new ImageNotFound(sprintf('"%s" is not a picture\'s path or key.', $source));
        }

        if (str_starts_with($source, '/')) {
            $root = realpath($this->documentRoot);
            $real = in_array('..', explode('/', $source), true) || $root === false ? false : realpath($root . $source);

            if ($real === false || !is_file($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                throw new ImageNotFound(sprintf('%s is not a file in %s.', $source, $this->documentRoot));
            }

            return ['path' => $real, 'url' => $source, 'identity' => $source . '|' . filesize($real) . '|' . filemtime($real)];
        }

        try {
            $key = Key::valid($source);
        } catch (InvalidKey) {
            throw new ImageNotFound(sprintf('"%s" is not a key on the public disk.', $source));
        }

        $path = $this->diskRoot . '/' . $key;

        if (str_starts_with($key, self::DIRECTORY . '/') || !is_file($path)) {
            throw new ImageNotFound(sprintf('%s is not a picture on the public disk.', $key));
        }

        return ['path' => $path, 'url' => $this->diskUrl . '/' . $key, 'identity' => 'disk:' . $key];
    }

    /** @param array<string, string> $attributes */
    private static function tag(array $attributes, bool $eager): string
    {
        $attributes += $eager ? ['fetchpriority' => 'high'] : ['loading' => 'lazy'];
        $attributes['decoding'] = 'async';
        $html = '<img';

        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return $html . '>';
    }
}
