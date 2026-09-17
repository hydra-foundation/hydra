<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture\Admin\Sources;

use Hydra\Admin\Contracts\CreateSourceInterface;
use Hydra\Admin\Contracts\DeleteSourceInterface;
use Hydra\Admin\Contracts\UpdateSourceInterface;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Admin\RowId;
use Hydra\Admin\Sources\TableSource;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Tests\Fixture\Entities\Role;

/**
 * A writable source over the fixture's one table.
 *
 * The three write contracts are all implemented because each carries a refusal
 * the admin has to render rather than raise: a name another row holds, a role
 * the select never offered, and the account doing the deleting.
 *
 * password_hash is absent from the columns on purpose: it is written here and
 * never read back into a screen.
 */
final class UserSource extends TableSource implements UpdateSourceInterface, CreateSourceInterface, DeleteSourceInterface
{
    public function __construct(
        ConnectionInterface $db,
        private readonly GuardInterface $guard,
        private readonly HasherInterface $hasher,
    ) {
        parent::__construct(
            $db,
            table: 'users',
            columns: ['id', 'username', 'role', 'created_at'],
            sortable: ['id', 'username', 'role', 'created_at'],
            searchable: ['username'],
            filterable: ['role'],
        );
    }

    public function create(array $data): string
    {
        $username = trim((string) ($data['username'] ?? ''));

        if ($this->isTaken($username)) {
            throw WriteRejected::on('username', 'That username is already taken.');
        }

        $this->db->execute(
            sprintf('INSERT INTO %s (username, role, password_hash) VALUES (?, ?, ?)', $this->table),
            [$username, $this->role($data), $this->hasher->hash((string) ($data['password'] ?? ''))],
        );

        return (string) $this->db->lastInsertId();
    }

    public function update(string $id, array $data): void
    {
        $key = RowId::int($id) ?? throw WriteRejected::on('id', 'No user has that id.');
        $username = trim((string) ($data['username'] ?? ''));

        if ($this->isTaken($username, $key)) {
            throw WriteRejected::on('username', 'That username is already taken.');
        }

        $columns = ['username = ?', 'role = ?'];
        $params = [$username, $this->role($data)];

        if (($data['password'] ?? '') !== '') {
            $columns[] = 'password_hash = ?';
            $params[] = $this->hasher->hash((string) $data['password']);
        }

        $params[] = $key;

        $this->db->execute(
            sprintf('UPDATE %s SET %s WHERE id = ?', $this->table, implode(', ', $columns)),
            $params,
        );
    }

    public function delete(string $id): void
    {
        $key = RowId::int($id) ?? throw WriteRejected::on('id', 'No user has that id.');

        if ((string) $this->guard->id() === $id) {
            throw WriteRejected::on('id', 'You cannot delete the account you are signed in as.');
        }

        $this->db->execute(sprintf('DELETE FROM %s WHERE id = ?', $this->table), [$key]);
    }

    /** @param array<string, mixed> $data */
    private function role(array $data): string
    {
        $role = trim((string) ($data['role'] ?? ''));

        if ($role === '') {
            return Role::DEFAULT->value;
        }

        return (Role::tryFrom($role) ?? throw WriteRejected::on('role', 'Choose a role from the list.'))->value;
    }

    private function isTaken(string $username, ?int $except = null): bool
    {
        $sql = $except === null
            ? sprintf('SELECT id FROM %s WHERE username = ?', $this->table)
            : sprintf('SELECT id FROM %s WHERE username = ? AND id <> ?', $this->table);

        return $this->db->selectOne($sql, $except === null ? [$username] : [$username, $except]) !== null;
    }
}
