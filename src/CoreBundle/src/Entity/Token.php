<?php

namespace App\CoreBundle\Entity;

use App\CoreBundle\Doctrine\EncryptedTextType;
use App\CoreBundle\Repository\TokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * API access token of a user (AccessTokenHandler, "Authorization: Bearer <token>"), managed from the profile page.
 *
 * The value is stored twice:
 * - accessToken, encrypted at rest when DATABASE_ENCRYPTION_KEY is set (EncryptedTextType), so that the user can see
 *   it again; it is decrypted only to show it;
 * - tokenHash, its SHA-256, which finds the token: an encrypted value changes at every write, so it cannot be looked
 *   up. A random value of 256 bits needs no slow hash.
 *
 * The owner is the user class and identifier (email), as in the remember-me table.
 */
#[ORM\Index(name: 'IDX_class_username', columns: ['class', 'username'])]
#[ORM\UniqueConstraint(name: 'IDX_token_hash', columns: ['token_hash'])]
#[ORM\Table(name: 'token')]
#[ORM\Entity(repositoryClass: TokenRepository::class)]
class Token
{
    public const int DESCRIPTION_MAX_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $class = null;

    #[ORM\Column(length: 200)]
    private ?string $username = null;

    #[ORM\Column(type: EncryptedTextType::NAME)]
    private ?string $accessToken = null;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private ?string $tokenHash = null;

    /** What the token is used for; null for the tokens made before descriptions existed */
    #[ORM\Column(length: self::DESCRIPTION_MAX_LENGTH, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(options: ["default" => "1"])]
    private ?bool $valid = true;

    /**
     * A new active token of the user, with a random value of 256 bits (64 hexadecimal characters).
     */
    public static function createFor(UserInterface $user, string $description): self
    {
        return (new self())
            ->setClass($user::class)
            ->setUsername($user->getUserIdentifier())
            ->setDescription($description)
            ->setAccessToken(bin2hex(random_bytes(32)))
            ->setValid(true);
    }

    /**
     * The value of tokenHash for a token value.
     */
    public static function hashAccessToken(#[\SensitiveParameter] string $accessToken): string
    {
        return hash('sha256', $accessToken);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClass(): ?string
    {
        return $this->class;
    }

    public function setClass(string $class): static
    {
        $this->class = $class;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    /**
     * Also sets the hash that finds the token.
     */
    public function setAccessToken(#[\SensitiveParameter] string $accessToken): static
    {
        $this->accessToken = $accessToken;
        $this->tokenHash = self::hashAccessToken($accessToken);

        return $this;
    }

    public function getTokenHash(): ?string
    {
        return $this->tokenHash;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function isValid(): ?bool
    {
        return $this->valid;
    }

    public function setValid(bool $valid): static
    {
        $this->valid = $valid;

        return $this;
    }
}
