<?php

namespace App\BooksBundle\Controller;

use App\BooksBundle\Entity\Channel;
use App\BooksBundle\Entity\Library;
use App\BooksBundle\Entity\Shelf;
use App\BooksBundle\Normalizer\BookCacheNormalizer;
use App\BooksBundle\Repository\BookRepository;
use App\BooksBundle\Repository\LibraryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_BOOKS', null, 'Access Denied.')]
#[Route('/api/libraries/{libraryId}', name: 'api_libraries_id_', requirements: ['libraryId' => '\d+'], format: 'json')]
class ApiShelvesController extends AbstractController
{

    private Library $library;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LibraryRepository      $libraryRepo,
        private readonly RequestStack           $requestStack)
    {
        $req = $this->requestStack->getCurrentRequest();
        $library = $this->libraryRepo->findOneBy(['id' => $req->attributes->getInt('libraryId')]);
        if (!$library) {
            throw new NotFoundHttpException("Library not found.");
        }
        $this->library = $library;
    }

    protected function getLibrary(): Library
    {
        return $this->library;
    }

    #[Route('/shelves', name: 'shelves', methods: ['GET'])]
    public function apiShelves(Request $req, Authorization $authorization): Response
    {
        $authorization->setCookie($req, [Channel::LIBRARY_SHELVES]);
        return $this->json($this->getLibrary()->getShelves());
    }

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/shelves', name: 'shelves_add', methods: ['POST'])]
    public function apiShelvesAdd(Request $req, BookRepository $bookRepo): Response
    {
        if (!$req->getPayload()->has('path')) {
            throw new BadRequestHttpException("Parameter 'path' not found.");
        }
        if (!$req->getPayload()->has('name')) {
            throw new BadRequestHttpException("Parameter 'name' not found.");
        }
        $shelf = (new Shelf())
            ->setPath($req->getPayload()->getString('path'))
            ->setName($req->getPayload()->getString('name'))
            ->setLibrary($this->getLibrary());
        foreach ($bookRepo->getWithPath($this->getLibrary(), $shelf->getPath()) as $book) {
            $shelf->addBook($book);
        }
        $this->entityManager->persist($shelf);
        $this->entityManager->flush();
        return $this->json($shelf);
    }

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/shelves/{id}', name: 'shelves_id_edit', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function apiShelvesEdit(Request $req, #[MapEntity(message: "Shelf not found.")] Shelf $shelf): Response
    {
        if (!$req->getPayload()->has('name')) {
            throw new BadRequestHttpException("Parameter 'name' not found.");
        }
        $shelf->setName($req->getPayload()->getString('name'));
        $this->entityManager->flush();
        return $this->json($shelf);
    }

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/shelves/{id}', name: 'shelves_id_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function apiShelvesDelete(#[MapEntity(message: "Shelf not found.")] Shelf $shelf): Response
    {
        $this->entityManager->remove($shelf);
        $this->entityManager->flush();
        return $this->json([]);
    }

    #[Route('/shelves/{id}/books', name: 'shelves_id_books', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiShelvesIdBooks(Request $req, #[MapEntity(message: "Shelf not found.")] Shelf $shelf, Authorization $authorization): Response
    {
        $authorization->setCookie($req, [sprintf(Channel::LIBRARY_SHELVES_ID, $shelf->getId())]);
        return $this->json($shelf->getBooks(), 200, [], [BookCacheNormalizer::COVER_FILTER => 'books_thumb', 'groups' => ['book:list']]);
    }

}
