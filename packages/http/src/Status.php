<?php

declare(strict_types=1);

namespace Hydra\Http;

/**
 * HTTP status codes as a named, int-backed vocabulary
 */
enum Status: int
{
    case Ok = 200;
    case Created = 201;
    case NoContent = 204;
    case MovedPermanently = 301;
    case Found = 302;
    case BadRequest = 400;
    case Unauthorized = 401;
    case Forbidden = 403;
    case NotFound = 404;
    case MethodNotAllowed = 405;
    case UnprocessableEntity = 422;
    case InternalServerError = 500;
    case ServiceUnavailable = 503;

    /** The RFC reason phrase for this status. */
    public function reason(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Created => 'Created',
            self::NoContent => 'No Content',
            self::MovedPermanently => 'Moved Permanently',
            self::Found => 'Found',
            self::BadRequest => 'Bad Request',
            self::Unauthorized => 'Unauthorized',
            self::Forbidden => 'Forbidden',
            self::NotFound => 'Not Found',
            self::MethodNotAllowed => 'Method Not Allowed',
            self::UnprocessableEntity => 'Unprocessable Entity',
            self::InternalServerError => 'Internal Server Error',
            self::ServiceUnavailable => 'Service Unavailable',
        };
    }

    /**
     * The reason phrase for a raw status code, or null if it isn't one of the enumerated codes.
     */
    public static function reasonFor(int $code): ?string
    {
        return self::tryFrom($code)?->reason();
    }

    public static function toInt(int|self $status): int
    {
        return $status instanceof self ? $status->value : $status;
    }
}
