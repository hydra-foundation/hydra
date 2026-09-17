<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\Repositories;

use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Tests\Fixture\Entities\User;

/**
 * The fixture's answer to the one seam auth ships and never fills.
 */
final class UserRepository implements UserProviderInterface
{
    private const COLUMNS = 'id, username, password_hash, role, created_at';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function byIdentifier(int|string $id): ?User
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?',
            [(int) $id],
        );

        return $row === null ? null : User::fromRow($row);
    }

    public function byUsername(string $username): ?User
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE username = ?',
            [$username],
        );

        return $row === null ? null : User::fromRow($row);
    }
}
