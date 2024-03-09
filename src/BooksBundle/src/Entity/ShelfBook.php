<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\ShelfBookRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'book')]
#[ORM\Entity(repositoryClass: ShelfBookRepository::class)]
class ShelfBook extends AbstractBook
{
    #[ORM\ManyToOne(targetEntity: Shelf::class, inversedBy: 'books')]
    #[ORM\JoinColumn(name: 'shelf_id', referencedColumnName: 'id')]
    private ?Shelf $shelf = null;

    public function getShelf(): ?Shelf
    {
        return $this->shelf;
    }

}
