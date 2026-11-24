<?php

namespace App\Tests\CoreBundle;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Repository\UserRepository;
use Doctrine\DBAL\LockMode;

/**
 * UserRepository for the tests, which run without a database: the users in memory, with ids given on save. The
 * emails are compared without case, as the collation of the column does. It also serves the firewall: find() to
 * reload the user of a session, findOneBy() to load the one of a login form or an API token.
 */
class InMemoryUserRepository extends UserRepository
{
    /** @var array<int, User> saved users by id */
    public array $rows = [];
    private int $nextId = 1000;

    /** @noinspection PhpMissingParentConstructorInspection the parent needs a ManagerRegistry */
    public function __construct()
    {
    }

    /**
     * Keeps a user that already has an id (e.g. from WithoutDatabaseClientTrait::createUser()).
     */
    public function add(User $user): User
    {
        $this->rows[(int) $user->getId()] = $user;

        return $user;
    }

    /**
     * @param int|array{id: int} $id the firewall gives the identifier values, ['id' => ...]
     */
    public function find(mixed $id, LockMode|int|null $lockMode = LockMode::NONE, ?int $lockVersion = null): ?User
    {
        return $this->rows[(int) (is_array($id) ? $id['id'] : $id)] ?? null;
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?User
    {
        if (array_keys($criteria) !== ['email']) {
            throw new \LogicException('Only findOneBy(["email" => ...]) is supported.');
        }

        foreach ($this->rows as $user) {
            if (strcasecmp((string) $user->getEmail(), (string) $criteria['email']) === 0) {
                return $user;
            }
        }

        return null;
    }

    public function findAllWithRoles(): array
    {
        $users = array_values($this->rows);
        usort($users, static fn (User $a, User $b): int => strcmp((string) $a->getEmail(), (string) $b->getEmail()));

        return $users;
    }

    public function emailExists(string $email): bool
    {
        return $this->findOneBy(['email' => $email]) !== null;
    }

    public function save(User $user): void
    {
        if ($user->getId() === null) {
            (new \ReflectionProperty(User::class, 'id'))->setValue($user, $this->nextId++);
        }
        $this->rows[(int) $user->getId()] = $user;
    }
}
