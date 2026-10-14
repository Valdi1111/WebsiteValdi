<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\BookNoteRepository;
use App\CoreBundle\Entity\User;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Index(name: 'FK_book_note_book', columns: ['book_id'])]
#[ORM\Index(name: 'FK_book_note_user', columns: ['user_id'])]
#[ORM\Table(name: 'book_note')]
#[ORM\Entity(repositoryClass: BookNoteRepository::class)]
class BookNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class, inversedBy: 'bookNotes')]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Book $book = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $cfi = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 32, options: ['default' => '#ffeb3b'])]
    private string $color = '#ffeb3b';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $chapter = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $sectionIndex = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, insertable: false, updatable: false, options: ["default" => new CurrentTimestamp()])]
    private ?\DateTimeInterface $created = null;

    #[Groups(['book:note'])]
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

    #[Groups(['book:note'])]
    public function getCfi(): ?string
    {
        return $this->cfi;
    }

    public function setCfi(string $cfi): static
    {
        $this->cfi = $cfi;

        return $this;
    }

    #[Groups(['book:note'])]
    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): static
    {
        $this->text = $text;

        return $this;
    }

    #[Groups(['book:note'])]
    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    #[Groups(['book:note'])]
    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    #[Groups(['book:note'])]
    public function getChapter(): ?string
    {
        return $this->chapter;
    }

    public function setChapter(?string $chapter): static
    {
        $this->chapter = $chapter;

        return $this;
    }

    #[Groups(['book:note'])]
    public function getSectionIndex(): ?int
    {
        return $this->sectionIndex;
    }

    public function setSectionIndex(?int $sectionIndex): static
    {
        $this->sectionIndex = $sectionIndex;

        return $this;
    }

    #[Groups(['book:note'])]
    public function getCreated(): ?\DateTimeInterface
    {
        return $this->created;
    }
}
