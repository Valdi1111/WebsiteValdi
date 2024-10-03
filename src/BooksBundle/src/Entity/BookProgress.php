<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\BookProgressRepository;
use App\CoreBundle\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Table(name: 'book_progress')]
#[ORM\Entity(repositoryClass: BookProgressRepository::class)]
class BookProgress
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Book::class, inversedBy: 'bookProgresses')]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: false)]
    private ?Book $book = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $position = null;

    #[ORM\Column]
    private int $page = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $lastRead;

    public function __construct()
    {
        $this->lastRead = new \DateTime();
    }

    #[Ignore]
    public function getBook(): ?Book
    {
        return $this->book;
    }

    public function setBook(?Book $book): self
    {
        $this->book = $book;

        return $this;
    }

    public function getBookId(): ?int
    {
        return $this->getBook()?->getId();
    }

    #[Ignore]
    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->getUser()?->getId();
    }

    #[Groups(['book:list'])]
    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function setPosition(?string $position): static
    {
        $this->position = $position;

        return $this;
    }

    #[Groups(['book:list'])]
    public function getPage(): int
    {
        return $this->page;
    }

    public function setPage(int $page): static
    {
        $this->page = $page;

        return $this;
    }

    #[Groups(['book:list'])]
    public function getLastRead(): \DateTimeInterface
    {
        return $this->lastRead;
    }

    public function setLastRead(\DateTimeInterface $lastRead): static
    {
        $this->lastRead = $lastRead;

        return $this;
    }

    public function updateLastRead(): static
    {
        $this->lastRead = new \DateTime();

        return $this;
    }

}
