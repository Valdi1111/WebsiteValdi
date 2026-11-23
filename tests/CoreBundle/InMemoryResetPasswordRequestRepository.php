<?php

namespace App\Tests\CoreBundle;

use App\CoreBundle\Entity\ResetPasswordRequest;
use App\CoreBundle\Repository\ResetPasswordRequestRepository;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;

/**
 * ResetPasswordRequestRepository for the tests, which run without a database: the reset password links the
 * bundle stores, in memory, with the same queries as ResetPasswordRequestRepositoryTrait.
 */
class InMemoryResetPasswordRequestRepository extends ResetPasswordRequestRepository
{
    /** @var list<ResetPasswordRequest> */
    public array $rows = [];

    /** @noinspection PhpMissingParentConstructorInspection the parent needs a ManagerRegistry */
    public function __construct()
    {
    }

    public function getUserIdentifier(object $user): string
    {
        return (string) $user->getId();
    }

    public function persistResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        $this->rows[] = $resetPasswordRequest;
    }

    public function findResetPasswordRequest(string $selector): ?ResetPasswordRequestInterface
    {
        foreach ($this->rows as $row) {
            if ($row->getSelector() === $selector) {
                return $row;
            }
        }

        return null;
    }

    public function getMostRecentNonExpiredRequestDate(object $user): ?\DateTimeInterface
    {
        $rows = $this->rowsOf($user);
        usort($rows, static fn (ResetPasswordRequest $a, ResetPasswordRequest $b): int => $b->getRequestedAt() <=> $a->getRequestedAt());
        $latest = $rows[0] ?? null;

        return $latest !== null && !$latest->isExpired() ? $latest->getRequestedAt() : null;
    }

    public function removeResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        $this->removeRequests($resetPasswordRequest->getUser());
    }

    public function removeExpiredResetPasswordRequests(): int
    {
        $limit = new \DateTimeImmutable('-1 week');
        $count = count($this->rows);
        $this->rows = array_values(array_filter($this->rows, static fn (ResetPasswordRequest $row): bool => $row->getExpiresAt() > $limit));

        return $count - count($this->rows);
    }

    public function removeRequests(object $user): void
    {
        $this->rows = array_values(array_filter($this->rows, static fn (ResetPasswordRequest $row): bool => $row->getUser() !== $user));
    }

    /**
     * @return list<ResetPasswordRequest>
     */
    public function rowsOf(object $user): array
    {
        return array_values(array_filter($this->rows, static fn (ResetPasswordRequest $row): bool => $row->getUser() === $user));
    }
}
