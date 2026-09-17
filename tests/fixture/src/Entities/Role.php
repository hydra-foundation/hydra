<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\Entities;

/**
 * The roles an account may hold. Two cases is the minimum that makes an
 * ability worth testing: one that may reach the backend and one that may not.
 */
enum Role: string
{
    case User = 'user';
    case Admin = 'admin';

    public const DEFAULT = self::User;

    public static function coerce(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::DEFAULT : self::DEFAULT;
    }

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Admin => 'Admin',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }
}
