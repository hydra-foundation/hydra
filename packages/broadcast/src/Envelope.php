<?php

declare(strict_types=1);

namespace Hydra\Broadcast;

use InvalidArgumentException;
use JsonException;

/**
 * One broadcast as it travels: the channel format, written by the publisher
 * and read by the hub. This class is both ends, so the format has one owner.
 *
 * Reading is forgiving and writing is not. Anything can land on a Redis
 * channel, so fromJson() turns a malformed message into null for the hub to
 * drop. A publisher's mistake throws, where it was made.
 */
final readonly class Envelope
{
    /** The largest encoded envelope, in bytes. A broadcast is a nudge. */
    public const MAX_BYTES = 65536;

    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param array<string, mixed> $data
     * @param int $at when it was published, in unix milliseconds
     * @throws InvalidArgumentException for a bad topic or event name
     */
    public function __construct(
        public string $topic,
        public string $event,
        public array $data,
        public int $at,
    ) {
        Topic::assertValid($topic);
        Topic::assertValidEvent($event);
    }

    /** @throws InvalidArgumentException when the data cannot be encoded, or the result is over MAX_BYTES */
    public function toJson(): string
    {
        try {
            $json = json_encode(
                ['topic' => $this->topic, 'event' => $this->event, 'data' => $this->data, 'at' => $this->at],
                self::FLAGS | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                "The {$this->event} broadcast on {$this->topic} could not be encoded as JSON: {$e->getMessage()}.",
                previous: $e,
            );
        }

        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'The %s broadcast on %s is %d bytes; the limit is %d. Broadcast what changed, not the records.',
                $this->event,
                $this->topic,
                strlen($json),
                self::MAX_BYTES,
            ));
        }

        return $json;
    }

    /** The envelope in $json, or null when it is not one. */
    public static function fromJson(string $json): ?self
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (
            !is_array($decoded)
            || !is_string($decoded['topic'] ?? null)
            || !is_string($decoded['event'] ?? null)
            || !is_array($decoded['data'] ?? null)
            || !is_int($decoded['at'] ?? null)
            || !Topic::isValid($decoded['topic'])
            || !Topic::isValidEvent($decoded['event'])
        ) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = $decoded['data'];

        return new self($decoded['topic'], $decoded['event'], $data, $decoded['at']);
    }
}
