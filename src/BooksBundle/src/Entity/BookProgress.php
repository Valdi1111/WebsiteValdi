<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\BookProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'book_progress')]
#[ORM\Entity(repositoryClass: BookProgressRepository::class)]
class BookProgress
{
    #[ORM\Id]
    #[ORM\Column]
    private ?int $book_id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $position = null;

    #[ORM\Column]
    private int $page = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $last_read;

    public function __construct()
    {
        $this->last_read = new \DateTime();
    }

    public function getBookId(): ?int
    {
        return $this->book_id;
    }

    public function setBookId(int $book_id): static
    {
        $this->book_id = $book_id;

        return $this;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function setPosition(?string $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function setPage(int $page): static
    {
        $this->page = $page;

        return $this;
    }

    public function getLastRead(): \DateTimeInterface
    {
        return $this->last_read;
    }

    public function setLastRead(\DateTimeInterface $last_read): static
    {
        $this->last_read = $last_read;

        return $this;
    }

    public function updateLastRead(): static
    {
        $this->last_read = new \DateTime();

        return $this;
    }
}
