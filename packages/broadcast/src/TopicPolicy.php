<?php

declare(strict_types=1);

namespace Hydra\Broadcast;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Who may listen to which topic, asked before a listen token is minted.
 *
 * A pattern is a topic whose segments may be `{name}` placeholders, each
 * matching exactly one segment: `module.{slug}`, `user.{id}`. The first
 * pattern that matches decides, and a topic no pattern matches is refused, so
 * forgetting to register one fails closed.
 */
final class TopicPolicy
{
    private const PLACEHOLDER = '/^\{([a-z_][a-z0-9_]*)\}$/D';

    /** @var list<array{pattern: string, regex: string, check: callable(int|string, array<string, string>): bool}> */
    private array $rules = [];

    public function __construct(private readonly LoggerInterface $logger = new NullLogger) {}

    /**
     * @param callable(int|string $userId, array<string, string> $params): bool $check
     * @throws InvalidArgumentException for a pattern that could never match a topic
     */
    public function allow(string $pattern, callable $check): self
    {
        $this->rules[] = ['pattern' => $pattern, 'regex' => self::compile($pattern), 'check' => $check];

        return $this;
    }

    public function permits(int|string $userId, string $topic): bool
    {
        if (!Topic::isValid($topic)) {
            return false;
        }

        foreach ($this->rules as $rule) {
            if (preg_match($rule['regex'], $topic, $matches) !== 1) {
                continue;
            }

            try {
                return ($rule['check'])($userId, array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY)) === true;
            } catch (Throwable $e) {
                $this->logger->warning(
                    "Topic check for {$rule['pattern']} failed on {$topic}: {$e->getMessage()}",
                    ['exception' => $e],
                );

                return false;
            }
        }

        return false;
    }

    private static function compile(string $pattern): string
    {
        $parts = [];
        $names = [];

        foreach (explode('.', $pattern) as $segment) {
            if (preg_match(self::PLACEHOLDER, $segment, $m) === 1) {
                if (in_array($m[1], $names, true)) {
                    throw new InvalidArgumentException("The topic pattern \"{$pattern}\" names {{$m[1]}} twice.");
                }

                $names[] = $m[1];
                $parts[] = "(?<{$m[1]}>[a-z0-9_-]+)";

                continue;
            }

            if (!Topic::isValid($segment) || str_contains($segment, '.')) {
                throw new InvalidArgumentException(
                    "\"{$pattern}\" is not a valid topic pattern: use topic segments and {name} placeholders, each a whole segment.",
                );
            }

            $parts[] = preg_quote($segment, '/');
        }

        return '/^' . implode('\.', $parts) . '$/D';
    }
}
