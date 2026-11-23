<?php

namespace App\Tests\CoreBundle;

use App\CoreBundle\Entity\Token;
use App\CoreBundle\Model\Profile\ApiTokenView;
use App\CoreBundle\Repository\TokenRepository;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * TokenRepository for the tests, which run without a database: the API tokens in memory, with ids given on save.
 */
class InMemoryTokenRepository extends TokenRepository
{
    /** @var array<int, Token> saved tokens by id */
    public array $rows = [];
    private int $nextId = 1;

    /** @noinspection PhpMissingParentConstructorInspection the parent needs a ManagerRegistry */
    public function __construct()
    {
    }

    public function findCredentialsByAccessToken(#[\SensitiveParameter] string $accessToken): ?array
    {
        foreach ($this->rows as $id => $token) {
            if ($token->getTokenHash() === Token::hashAccessToken($accessToken)) {
                return ['id' => $id, 'valid' => (bool) $token->isValid(), 'username' => (string) $token->getUsername()];
            }
        }

        return null;
    }

    public function findViewsOfUser(UserInterface $user): array
    {
        $views = [];
        foreach ($this->rows as $token) {
            if (self::isOf($token, $user)) {
                $views[] = self::view($token);
            }
        }

        return $views;
    }

    public function findViewOfUser(int $id, UserInterface $user): ?ApiTokenView
    {
        $token = $this->ofUser($id, $user);

        return $token === null ? null : self::view($token);
    }

    public function findValueOfUser(int $id, UserInterface $user): ?string
    {
        return $this->ofUser($id, $user)?->getAccessToken();
    }

    public function updateOfUser(int $id, UserInterface $user, array $changes): bool
    {
        $token = $this->ofUser($id, $user);
        if ($token === null) {
            return false;
        }
        if (array_key_exists('description', $changes)) {
            $token->setDescription($changes['description']);
        }
        if (array_key_exists('valid', $changes)) {
            $token->setValid($changes['valid']);
        }

        return true;
    }

    public function deleteOfUser(int $id, UserInterface $user): bool
    {
        if ($this->ofUser($id, $user) === null) {
            return false;
        }
        unset($this->rows[$id]);

        return true;
    }

    public function save(Token $token): void
    {
        if ($token->getId() === null) {
            (new \ReflectionProperty(Token::class, 'id'))->setValue($token, $this->nextId++);
        }
        foreach ($this->rows as $id => $row) {
            if ($id !== $token->getId() && $row->getTokenHash() === $token->getTokenHash()) {
                throw new \LogicException('Unique constraint violated.');
            }
        }
        $this->rows[$token->getId()] = $token;
    }

    private function ofUser(int $id, UserInterface $user): ?Token
    {
        $token = $this->rows[$id] ?? null;

        return $token !== null && self::isOf($token, $user) ? $token : null;
    }

    private static function isOf(Token $token, UserInterface $user): bool
    {
        return $token->getClass() === $user::class && $token->getUsername() === $user->getUserIdentifier();
    }

    private static function view(Token $token): ApiTokenView
    {
        return new ApiTokenView((int) $token->getId(), $token->getDescription(), (bool) $token->isValid());
    }
}
