<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\BookBookmarkRepository;
use App\CoreBundle\Entity\User;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Index(name: 'FK_book_bookmark_book', columns: ['book_id'])]
#[ORM\Index(name: 'FK_book_bookmark_user', columns: ['user_id'])]
#[ORM\Table(name: 'book_bookmark')]
#[ORM\Entity(repositoryClass: BookBookmarkRepository::class)]
class BookBookmark
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class, inversedBy: 'bookBookmarks')]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Book $book = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $cfi = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $chapter = null;

    #[ORM\Column(options: ['default' => 0])]
    private float $fraction = 0.0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, insertable: false, updatable: false, options: ["default" => new CurrentTimestamp()])]
    private ?\DateTimeInterface $created = null;

    #[Groups(['book:bookmark'])]
    public function getId(): ?int
    {
        return $this->id;
    }

    #[Ignore]
    public function getBook(): ?Book
    {
        return $this->book;
    }

    public function setBook(?Book $book): static
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

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->getUser()?->getId();
    }

    #[Groups(['book:bookmark'])]
    public function getCfi(): ?string
    {
        return $this->cfi;
    }

    public function setCfi(string $cfi): static
    {
        $this->cfi = $cfi;

        return $this;
    }

    #[Groups(['book:bookmark'])]
    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    #[Groups(['book:bookmark'])]
    public function getChapter(): ?string
    {
        return $this->chapter;
    }

    public function setChapter(?string $chapter): static
    {
        $this->chapter = $chapter;

        return $this;
    }

    #[Groups(['book:bookmark'])]
    public function getFraction(): float
    {
        return $this->fraction;
    }

    public function setFraction(float $fraction): static
    {
        $this->fraction = $fraction;

        return $this;
    }

    #[Groups(['book:bookmark'])]
    public function getCreated(): ?\DateTimeInterface
    {
        return $this->created;
    }
}
