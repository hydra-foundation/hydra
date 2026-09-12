<?php

declare(strict_types=1);

namespace Hydra\Session;

use Hydra\Core\Environment;
use InvalidArgumentException;

/**
 * Session config
 */
final readonly class SessionConfig
{
    /** The only values PHP's session cookie params accept for SameSite. */
    private const SAME_SITE = ['Lax', 'Strict', 'None'];

    public function __construct(
        public string $name = 'hydra_session',
        public int $lifetime = 0,
        public string $path = '/',
        public string $domain = '',
        public bool $secure = false,
        public bool $httpOnly = true,
        public string $sameSite = 'Lax',
    ) {
        // A bad SameSite would otherwise flow silently into PHP and be ignored —
        // a security setting failing without a sound. Fail loud at construction,
        // the same discipline as the validation package's Pattern rule.
        if (!in_array($sameSite, self::SAME_SITE, true)) {
            throw new InvalidArgumentException(sprintf(
                'Session sameSite must be one of %s; got "%s".',
                implode(', ', self::SAME_SITE),
                $sameSite,
            ));
        }

        // A negative lifetime would flow into session_set_cookie_params() as an
        // already-expired cookie — the session would silently never persist.
        // Same fail-loud discipline as the SameSite guard above.
        if ($lifetime < 0) {
            throw new InvalidArgumentException(sprintf(
                'Session lifetime must be >= 0 (0 = session cookie); got %d.',
                $lifetime,
            ));
        }

        // Browsers reject a SameSite=None cookie that isn't also Secure, so the
        // pairing is invalid on its face — better to refuse it than ship a
        // cookie the client will drop.
        if ($sameSite === 'None' && !$secure) {
            throw new InvalidArgumentException(
                'Session sameSite=None requires secure=true (browsers reject an insecure None cookie).'
            );
        }
    }

    public static function fromEnvironment(Environment $env): self
    {
        return new self(
            name: $env->string('SESSION_NAME', 'hydra_session'),
            lifetime: $env->int('SESSION_LIFETIME', 0),
            path: $env->string('SESSION_PATH', '/'),
            domain: $env->string('SESSION_DOMAIN', ''),
            secure: $env->bool('SESSION_SECURE', false),
            httpOnly: $env->bool('SESSION_HTTP_ONLY', true),
            sameSite: $env->string('SESSION_SAME_SITE', 'Lax'),
        );
    }

    /**
     * The settings shaped for session_set_cookie_params().
     *
     * @return array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string}
     */
    public function cookieParams(): array
    {
        return [
            'lifetime' => $this->lifetime,
            'path' => $this->path,
            'domain' => $this->domain,
            'secure' => $this->secure,
            'httponly' => $this->httpOnly,
            'samesite' => $this->sameSite,
        ];
    }
}
