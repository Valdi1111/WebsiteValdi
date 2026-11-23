<?php

namespace App\Tests\CoreBundle;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Entity\UserAvatar;
use App\CoreBundle\Repository\UserAvatarRepository;

/**
 * UserAvatarRepository for the tests, which run without a database. It keeps copies of the saved rows, like a
 * database: changing an entity without saving it changes nothing here, and a save can be made to fail.
 */
class InMemoryUserAvatarRepository extends UserAvatarRepository
{
    /** @var array<int, UserAvatar> saved rows by user id, the primary key */
    private array $rows = [];
    private ?\Throwable $nextSaveFailure = null;
    /** @var list<string> "save <name>" / "delete <name>", in order */
    public array $log = [];

    /** @noinspection PhpMissingParentConstructorInspection the parent needs a ManagerRegistry */
    public function __construct()
    {
    }

    public function failNextSave(\Throwable $failure): void
    {
        $this->nextSaveFailure = $failure;
    }

    /**
     * @return list<UserAvatar> copies of the saved rows
     */
    public function rows(): array
    {
        return array_values(array_map(static fn (UserAvatar $row): UserAvatar => clone $row, $this->rows));
    }

    public function findOneByUser(User $user): ?UserAvatar
    {
        $row = $this->rows[$user->getId()] ?? null;

        return $row === null ? null : clone $row;
    }

    public function findNameByUser(User $user): ?string
    {
        return $this->findOneByUser($user)?->getName();
    }

    public function findContentByName(string $name): ?string
    {
        foreach ($this->rows as $row) {
            if ($row->getName() === $name) {
                return $row->getContent();
            }
        }

        return null;
    }

    public function save(UserAvatar $avatar): void
    {
        if ($this->nextSaveFailure !== null) {
            $failure = $this->nextSaveFailure;
            $this->nextSaveFailure = null;
            throw $failure;
        }

        $id = (int) $avatar->getUser()->getId();
        foreach ($this->rows as $rowId => $row) {
            if ($rowId !== $id && $row->getName() === $avatar->getName()) {
                throw new \LogicException('Unique constraint violated.');
            }
        }
        $this->rows[$id] = clone $avatar;
        $this->log[] = 'save '.$avatar->getName();
    }

    public function delete(UserAvatar $avatar): void
    {
        unset($this->rows[(int) $avatar->getUser()->getId()]);
        $this->log[] = 'delete '.$avatar->getName();
    }
}
