<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the stylesheet and script the package's own templates depend on.
 *
 * They are served rather than copied into an application's public directory
 * because a copy is a thing that has to be re-made on every upgrade, and one
 * nobody re-made looks exactly like one nobody needed to: the admin renders,
 * and a field type added by the new version is the only thing unstyled.
 */
final class AssetController
{
    /**
     * The assets, by the name the route asks for, as [file, media type]. A
     * name that is not a key here is not an asset, which is what keeps this
     * route from being a way to read anything else off disk: nothing from the
     * request ever reaches the path.
     *
     * The names carry no extension on purpose. A server's static-file rules
     * are written against extensions, and a ".css" under a path with no file
     * behind it is a 404 from the web server before PHP is ever reached --
     * nginx's own `location ~* \.(css|js)$` does exactly that. An extensionless
     * URL falls through to the front controller everywhere, which is the point
     * of serving these at all: no per-application server configuration.
     */
    private const ASSETS = [
        'stylesheet' => ['admin.css', 'text/css; charset=utf-8'],
        'script' => ['admin.js', 'text/javascript; charset=utf-8'],
    ];

    public function __construct(private readonly Responder $responder) {}

    public function show(Request $request): Response
    {
        $asset = self::ASSETS[(string) $request->getAttribute('asset')] ?? null;

        if ($asset === null) {
            throw new NotFoundException;
        }

        [$file, $type] = $asset;
        $path = AdminServiceProvider::assets() . '/' . $file;

        if (!is_file($path)) {
            throw new NotFoundException;
        }

        // Revalidated rather than cached outright: the URL carries no version,
        // so the round trip is the only thing that can tell a browser its copy
        // went stale. One conditional GET per page load, answered almost
        // always with a 304 and no body.
        $etag = $this->etag($path);
        $fresh = $this->matches($request->getHeaderLine('If-None-Match'), $etag);

        return $this->responder
            ->text($fresh ? '' : (string) file_get_contents($path), $fresh ? Status::NotModified : Status::Ok)
            ->withHeader('Content-Type', $type)
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', 'public, max-age=0, must-revalidate');
    }

    /**
     * The file's identity rather than its content: hashing 40KB on every page
     * load buys nothing a mtime and a size do not already say, and both change
     * whenever composer writes the file.
     */
    private function etag(string $path): string
    {
        return sprintf('"%x-%x"', (int) filemtime($path), (int) filesize($path));
    }

    /** A validator list, which may name several and may be weak. */
    private function matches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            $candidate = ltrim(trim($candidate), 'W/');

            if ($candidate === $etag || $candidate === '*') {
                return true;
            }
        }

        return false;
    }
}
