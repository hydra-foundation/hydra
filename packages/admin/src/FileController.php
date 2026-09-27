<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Filesystem\Exceptions\FileNotFound;
use Hydra\Filesystem\Exceptions\InvalidKey;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\Query;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves a file from the private disk to whoever is signed in to the admin.
 * The route carries the admin's middleware, and nothing else: "private" means
 * never on the open web, not "only the person who uploaded it". A key is 128
 * random bits, so knowing one is the same as having been shown it.
 *
 * The key is a query parameter rather than a path. It holds slashes, which a
 * route parameter does not match, and it ends in ".png", which a web server's
 * static-file rules answer with a 404 before PHP is ever asked.
 */
final class FileController
{
    /**
     * The only types a browser is asked to render. Everything else is a
     * download, an SVG above all: it is an image to finfo and a document that
     * runs script to a browser.
     */
    private const INLINE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly ?Uploads $uploads = null,
    ) {}

    public function show(Request $request): Response
    {
        $qualified = Query::fromRequest($request)->string('key');
        $disks = $this->uploads?->disks() ?? throw new NotFoundException;

        try {
            // A public file is the web server's to serve, at its own URL.
            if ($disks->isPublic($qualified)) {
                throw new NotFoundException;
            }

            [$disk, $key] = $disks->locate($qualified);
            $stream = $disk->read($key);
            $type = $disk->mimeType($key);
            $size = $disk->size($key);
        } catch (InvalidKey | FileNotFound) {
            throw new NotFoundException;
        }

        return $this->responses->createResponse(200)
            ->withBody($stream)
            ->withHeader('Content-Type', $type)
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('Content-Disposition', in_array($type, self::INLINE, true) ? 'inline' : 'attachment')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Should a browser render it anyway, as a page it can do nothing.
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            // A key names one set of bytes forever: a replaced file gets a
            // new key, so nothing cached under the old one can go stale.
            ->withHeader('Cache-Control', 'private, max-age=31536000, immutable');
    }
}
