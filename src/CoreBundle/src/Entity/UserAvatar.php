<?php

namespace App\CoreBundle\Entity;

use App\CoreBundle\Repository\UserAvatarRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Profile image of a user (UserAvatarManager): an extension of the user row, whose primary key is the user's id,
 * deleted with the user.
 *
 * Mapped only from this side: an inverse one-to-one on User is never lazy, so it would load the image with every user.
 *
 * "name" is random and changes on every upload: it is the path of the public LiipImagine copy, so the URL cannot be
 * guessed and no browser keeps showing a previous image. The content is the normalized image (rotated, without
 * metadata, webp).
 */
#[ORM\UniqueConstraint(name: 'IDX_name', columns: ['name'])]
#[ORM\Table(name: 'user_avatar')]
#[ORM\Entity(repositoryClass: UserAvatarRepository::class)]
class UserAvatar
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, options: ['fixed' => true])]
    private ?string $name = null;

    /**
     * @var string|resource|null Doctrine hydrates a BLOB as a stream
     */
    #[ORM\Column(type: Types::BLOB, length: 16777215)]
    private mixed $content = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getContent(): ?string
    {
        if (is_resource($this->content)) {
            rewind($this->content);

            return (string) stream_get_contents($this->content);
        }

        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
