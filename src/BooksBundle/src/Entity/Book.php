<?php

namespace App\BooksBundle\Entity;

use App\BooksBundle\Repository\BookRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'book')]
#[ORM\Entity(repositoryClass: BookRepository::class)]
class Book extends AbstractBook
{

}
