<?php

declare(strict_types=1);

namespace Hydra\Seo;

/**
 * Text for an XML element or attribute. Characters XML 1.0 has no place for
 * (most control characters, unpaired surrogates) are dropped rather than
 * escaped, because there is no escape for them: one left in makes the whole
 * document unreadable, and a feed reader shows nothing at all.
 *
 * @internal
 */
final class Xml
{
    public static function text(string $value): string
    {
        $value = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub($value, 'UTF-8'));

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
