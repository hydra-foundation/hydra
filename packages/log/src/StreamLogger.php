<?php

declare(strict_types=1);

namespace Hydra\Log;

use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

/**
 * Stream logger
 *
 * A minimal PSR-3 logger that writes one plain-text line per record to a
 * writable stream
 */
final class StreamLogger extends AbstractLogger
{
    public function __construct(private $stream)
    {}

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $line = sprintf(
            "[%s] %s: %s%s\n",
            date(DATE_ATOM),
            strtoupper((string) $level),
            $this->interpolate((string) $message, $context),
            $this->renderContext($context),
        );

        if (is_resource($this->stream)) {
            @fwrite($this->stream, $line);
        }
    }

    /**
     * Replace {placeholders} with their context values per PSR-3
     */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            $placeholder = '{' . $key . '}';

            if (!str_contains($message, $placeholder)) {
                continue;
            }

            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements[$placeholder] = is_bool($value)
                    ? ($value ? 'true' : 'false')
                    : (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    /**
     * Render leftover context as a trailing fragment
     */
    private function renderContext(array $context): string
    {
        $fragment = '';

        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $fragment .= ' ' . $this->renderException($context['exception']);
            unset($context['exception']);
        }

        if ($context !== []) {
            $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            if ($json !== false) {
                $fragment .= ' ' . $json;
            }
        }

        return $fragment;
    }

    private function renderException(Throwable $e): string
    {
        return sprintf(
            '%s: %s in %s:%d%s%s',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            PHP_EOL,
            $e->getTraceAsString(),
        );
    }
}
