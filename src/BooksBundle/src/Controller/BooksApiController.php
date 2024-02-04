<?php

namespace App\BooksBundle\Controller;

use App\BooksBundle\Entity\Book;
use App\BooksBundle\Entity\BookCache;
use App\BooksBundle\Entity\BookMetadata;
use App\BooksBundle\Entity\BookProgress;
use App\BooksBundle\Entity\Shelf;
use Doctrine\ORM\EntityManagerInterface;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api', name: 'api_')]
class BooksApiController extends AbstractController
{

    public function __construct(private readonly EntityManagerInterface $booksEntityManager)
    {
    }

    #[Route('/epub/{path}', name: 'epub', requirements: ['path' => '.*'], methods: ['GET'])]
    public function apiEpub(Request $req, string $path): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $filepath = $this->getParameter('books.base_folder') . '/' . $path;
        if (!file_exists($filepath)) {
            return $this->json(['error' => true, 'message' => "Epub file not found."], 400);
        }
        return $this->file(new File($filepath), 'book.epub');
    }

    #[Route('/books/all', name: 'books_all', methods: ['GET'])]
    public function apiBooksAll(Request $req, CacheManager $imagineCacheManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if (!$req->query->has('limit')) {
            return $this->json(['error' => true, 'message' => "Parameter limit not found."], 400);
        }
        if (!$req->query->has('offset')) {
            return $this->json(['error' => true, 'message' => "Parameter offset not found."], 400);
        }
        $limit = $req->query->getInt('limit');
        $offset = $req->query->getInt('offset');
        $books = $this->booksEntityManager->getRepository(Book::class)->getAll($limit, $offset);
        return $this->jsonBooks($imagineCacheManager, $books, false);
    }

    #[Route('/books/not-in-shelves', name: 'books_not_in_shelves', methods: ['GET'])]
    public function apiBooksNotInShelves(Request $req, CacheManager $imagineCacheManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if (!$req->query->has('limit')) {
            return $this->json(['error' => true, 'message' => "Parameter limit not found."], 400);
        }
        if (!$req->query->has('offset')) {
            return $this->json(['error' => true, 'message' => "Parameter offset not found."], 400);
        }
        $limit = $req->query->getInt('limit');
        $offset = $req->query->getInt('offset');
        $books = $this->booksEntityManager->getRepository(Book::class)->getNotInShelves($limit, $offset);
        return $this->jsonBooks($imagineCacheManager, $books);
    }

    private function searchFiles(array &$all, string $baseFolder, string $path = ""): void
    {
        $file = $baseFolder . '/' . $path;
        if (is_dir($file)) {
            foreach (scandir($file) as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $this->searchFiles($all, $baseFolder, $path . '/' . $item);
            }
        } else {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'epub') {
                $all[] = $path;
            }
        }
    }

    #[Route('/books/find-new', name: 'books_find_new', methods: ['GET'])]
    public function apiBooksFindNew(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $files = [];
        $this->searchFiles($files, $this->getParameter('books.base_folder'));
        $items = array_diff($files, $this->booksEntityManager->getRepository(Book::class)->getRegisteredPaths());
        $res = [];
        foreach ($items as $item) {
            $list = explode('/', $item, 3);
            $folder = '/';
            $file = $list[1];
            if (count($list) === 3) {
                $folder = $list[1];
                $file = $list[2];
            }
            $res[$folder][] = ['path' => $item, 'file' => $file];
        }
        return $this->json($res);
    }

    #[Route('/books', name: 'books_add', methods: ['POST'])]
    public function apiBooksAdd(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $body = $req->toArray();
        if (!array_key_exists('url', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter url not found."], 400);
        }
        if (!array_key_exists('locations', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter locations not found."], 400);
        }
        if (!array_key_exists('navigation', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter navigation not found."], 400);
        }
        if (!array_key_exists('metadata', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter metadata not found."], 400);
        }
        $book = (new Book())->setUrl($body['url']);
        $splits = explode('/', $book->getUrl());
        if (count($splits) > 2) {
            $shelf = $this->booksEntityManager->getRepository(Shelf::class)->findOneBy(['path' => $splits[1]]);
            if ($shelf) {
                $book->setShelfId($shelf->getId());
            }
        }
        $this->booksEntityManager->persist($book);
        $this->booksEntityManager->flush();
        $bookCache = (new BookCache())->setBookId($book->getId())->setLocations($body['locations'])->setNavigation($body['navigation']);
        $bookMetadata = (new BookMetadata())->setBookId($book->getId())->fromJson($body['metadata']);
        $bookProgress = (new BookProgress())->setBookId($book->getId());
        $this->booksEntityManager->persist($bookCache);
        $this->booksEntityManager->persist($bookMetadata);
        $this->booksEntityManager->persist($bookProgress);
        $this->booksEntityManager->flush();
        return $this->json(['id' => $book->getId(), 'shelf_id' => $book->getShelfId()]);
    }

    #[Route('/books/{bookId}', name: 'books_id_get', requirements: ['bookId' => '\d+'], methods: ['GET'])]
    public function apiBooksIdGet(Request $req, int $bookId, CacheManager $imagineCacheManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $book = $this->booksEntityManager->getRepository(Book::class)->findOneBy(['id' => $bookId]);
        if (!$book) {
            return $this->json(['error' => true, 'message' => "Book not found."], 400);
        }
        return $this->json([
            'id' => $book->getId(),
            'url' => $book->getUrl(),
            'book_cache' => [
                'cover' => $this->generateCoverThumbnail($imagineCacheManager, $book, 'books_cover'),
                'navigation' => $book->getBookCache()->getNavigation(),
                'locations' => $book->getBookCache()->getLocations(),
            ],
            'book_metadata' => [
                'title' => $book->getBookMetadata()->getTitle(),
            ],
            'book_progress' => [
                'position' => $book->getBookProgress()->getPosition(),
                'page' => $book->getBookProgress()->getPage(),
            ],
        ]);
    }

    #[Route('/books/{bookId}', name: 'books_id_edit', requirements: ['bookId' => '\d+'], methods: ['PUT'])]
    public function apiBooksIdEdit(Request $req, int $bookId): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $body = $req->toArray();
        if (!array_key_exists('locations', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter locations not found."], 400);
        }
        if (!array_key_exists('navigation', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter navigation not found."], 400);
        }
        if (!array_key_exists('metadata', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter metadata not found."], 400);
        }
        $book = $this->booksEntityManager->getRepository(Book::class)->findOneBy(['id' => $bookId]);
        if (!$book) {
            return $this->json(['error' => true, 'message' => "Book not found."], 400);
        }
        $book->getBookCache()->setLocations($body['locations'])->setNavigation($body['navigation']);
        $book->getBookMetadata()->fromJson($body['metadata']);
        $this->booksEntityManager->flush();
        return $this->json(['id' => $book->getId(), 'shelf_id' => $book->getShelfId()]);
    }

    #[Route('/books/{bookId}', name: 'books_id_delete', requirements: ['bookId' => '\d+'], methods: ['DELETE'])]
    public function apiBooksIdDelete(Request $req, int $bookId): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $book = $this->booksEntityManager->getRepository(Book::class)->findOneBy(['id' => $bookId]);
        if (!$book) {
            return $this->json(['error' => true, 'message' => "Book not found."], 400);
        }
        $cache = $book->getBookCache();
        $this->removeCoverFile($cache);
        $this->booksEntityManager->remove($cache);
        $this->booksEntityManager->remove($book->getBookMetadata());
        $this->booksEntityManager->remove($book->getBookProgress());
        $this->booksEntityManager->flush();
        $this->booksEntityManager->remove($book);
        $this->booksEntityManager->flush();
        return $this->json([]);
    }

    public function removeCoverFile(BookCache $book): void
    {
        if (!$book->getCover()) {
            return;
        }
        $filepath = $this->getParameter('books.covers_folder') . '/' . $book->getCover();
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        $book->setCover(null);
        $this->booksEntityManager->flush();
    }

    #[Route('/books/{bookId}/cover', name: 'books_id_cover_get', requirements: ['bookId' => '\d+'], methods: ['GET'])]
    public function apiBooksIdCoverGet(Request $req, int $bookId, CacheManager $imagineCacheManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $book = $this->booksEntityManager->getRepository(BookCache::class)->findOneBy(['book_id' => $bookId]);
        if (!$book) {
            return $this->json(['error' => true, 'message' => "Book not found."], 400);
        }
        $filepath = $this->getParameter('books.covers_folder') . '/' . $book->getCover();
        return $this->file(new File($filepath), $book->getCover(), ResponseHeaderBag::DISPOSITION_INLINE);
    }

    #[Route('/books/{bookId}/cover', name: 'books_id_cover_add', requirements: ['bookId' => '\d+'], methods: ['POST'])]
    public function apiBooksIdCoverAdd(Request $req, int $bookId): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $book = $this->booksEntityManager->getRepository(BookCache::class)->findOneBy(['book_id' => $bookId]);
        if (!$book) {
            return $this->json(['error' => true, 'message' => "Book not found."], 400);
        }
        $this->removeCoverFile($book);
        if ($req->files->has('cover')) {
            $uuid = Uuid::v7()->toRfc4122();
            $cover = $req->files->get('cover');
            $cover->move($this->getParameter('books.covers_folder'), $uuid);
            $book->setCover($uuid);
            $this->booksEntityManager->flush();
        }
        return $this->json([]);
    }

    #[Route('/books/{bookId}/cover', name: 'books_id_cover_delete', requirements: ['bookId' => '\d+'], methods: ['DELETE'])]
    public function apiBooksIdCoverDelete(Request $req, int $bookId): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $book = $this->booksEntityManager->getRepository(BookCache::class)->findOneBy(['book_id' => $bookId]);
        if (!$book) {
            return $this->json(['error' => true, 'message' => "Book not found."], 400);
        }
        $this->removeCoverFile($book);
        $this->booksEntityManager->flush();
        return $this->json([]);
    }

    #[Route('/books/{bookId}/mark-read', name: 'books_id_mark_read', requirements: ['bookId' => '\d+'], methods: ['PUT'])]
    public function apiBooksIdMarkRead(Request $req, int $bookId): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $book = $this->booksEntityManager->getRepository(BookProgress::class)->findOneBy(['book_id' => $bookId]);
        if (!$book) {
            return $this->json(['error' => true, 'message' => "Book not found."], 400);
        }
        $book->setPosition(null)->setPage(-1);
        $this->booksEntityManager->flush();
        return $this->json([]);
    }

    #[Route('/books/{bookId}/mark-unread', name: 'books_id_mark_unread', requirements: ['bookId' => '\d+'], methods: ['PUT'])]
    public function apiBooksIdMarkUnread(Request $req, int $bookId): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $book = $this->booksEntityManager->getRepository(BookProgress::class)->findOneBy(['book_id' => $bookId]);
        if (!$book) {
            return $this->json(['error' => true, 'message' => "Book not found."], 400);
        }
        $book->setPosition(null)->setPage(0);
        $this->booksEntityManager->flush();
        return $this->json([]);
    }

    #[Route('/books/{bookId}/metadata', name: 'books_id_metadata', requirements: ['bookId' => '\d+'], methods: ['GET'])]
    public function apiBooksIdMetadata(Request $req, int $bookId, CacheManager $imagineCacheManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $book = $this->booksEntityManager->getRepository(Book::class)->findOneBy(['id' => $bookId]);
        if (!$book) {
            return $this->json(['error' => true, 'message' => "Book not found."], 400);
        }
        $json = $book->getBookMetadata()->jsonSerialize();
        $json['cover'] = $this->generateCoverThumbnail($imagineCacheManager, $book, 'books_cover');
        return $this->json($json);
    }

    #[Route('/books/{bookId}/position', name: 'books_id_position', requirements: ['bookId' => '\d+'], methods: ['PUT'])]
    public function apiBooksIdPosition(Request $req, int $bookId): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $body = $req->toArray();
        if (!array_key_exists('position', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter offset not found."], 400);
        }
        if (!array_key_exists('page', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter page not found."], 400);
        }
        $book = $this->booksEntityManager->getRepository(BookProgress::class)->findOneBy(['book_id' => $bookId]);
        if (!$book) {
            return $this->json(['error' => true, 'message' => "Book not found."], 400);
        }
        $book->setPosition($body['position'])->setPage($body['page']);
        if (array_key_exists('update', $body) && $body['update']) {
            $book->updateLastRead();
        }
        $this->booksEntityManager->flush();
        return $this->json([]);
    }

    #[Route('/shelves', name: 'shelves', methods: ['GET'])]
    public function apiShelves(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $shelves = $this->booksEntityManager->getRepository(Shelf::class)->getShelves();
        return $this->json($shelves);
    }

    #[Route('/shelves', name: 'shelves_add', methods: ['POST'])]
    public function apiShelvesAdd(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $body = $req->toArray();
        if (!array_key_exists('path', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter path not found."], 400);
        }
        if (!array_key_exists('name', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter name not found."], 400);
        }
        $shelf = (new Shelf())->setPath($body['path'])->setName($body['name']);
        $this->booksEntityManager->persist($shelf);
        $this->booksEntityManager->flush();
        $books = $this->booksEntityManager->getRepository(Book::class)->getWithPath($shelf->getPath());
        foreach ($books as $book) {
            $book->setShelfId($shelf->getId());
        }
        $this->booksEntityManager->flush();
        $shelf->setCount(count($books));
        return $this->json($shelf);
    }

    #[Route('/shelves/{shelfId}', name: 'shelves_id_edit', requirements: ['shelfId' => '\d+'], methods: ['PUT'])]
    public function apiShelvesEdit(Request $req, int $shelfId): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $body = $req->toArray();
        if (!array_key_exists('name', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter name not found."], 400);
        }
        $shelf = $this->booksEntityManager->getRepository(Shelf::class)->findOneBy(['id' => $shelfId]);
        if (!$shelf) {
            return $this->json(['error' => true, 'message' => "Shelf not found."], 400);
        }
        $shelf->setName($body['name']);
        $this->booksEntityManager->flush();
        return $this->json($shelf);
    }

    #[Route('/shelves/{shelfId}', name: 'shelves_id_delete', requirements: ['shelfId' => '\d+'], methods: ['DELETE'])]
    public function apiShelvesDelete(Request $req, int $shelfId): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $shelf = $this->booksEntityManager->getRepository(Shelf::class)->findOneBy(['id' => $shelfId]);
        if (!$shelf) {
            return $this->json(['error' => true, 'message' => "Shelf not found."], 400);
        }
        $books = $this->booksEntityManager->getRepository(Book::class)->findBy(['shelf_id' => $shelf->getId()]);
        foreach ($books as $book) {
            $book->setShelfId(null);
        }
        $this->booksEntityManager->flush();
        $this->booksEntityManager->remove($shelf);
        $this->booksEntityManager->flush();
        return $this->json([]);
    }

    #[Route('/shelves/{shelfId}/books', name: 'shelves_id_books', requirements: ['shelfId' => '\d+'], methods: ['GET'])]
    public function apiShelvesIdBooks(Request $req, int $shelfId, CacheManager $imagineCacheManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $shelf = $this->booksEntityManager->getRepository(Shelf::class)->findOneBy(['id' => $shelfId]);
        if (!$shelf) {
            return $this->json(['error' => true, 'message' => "Shelf not found."], 400);
        }
        $books = $this->booksEntityManager->getRepository(Book::class)->getShelfBooks($shelfId);
        return $this->jsonBooks($imagineCacheManager, $books);
    }

    /**
     * @param CacheManager $imagineCacheManager
     * @param Book[] $books
     * @param bool $hideShelf show shelf?
     * @return Response
     */
    private function jsonBooks(CacheManager $imagineCacheManager, array $books, bool $hideShelf = true): Response
    {
        $json = [];
        foreach ($books as $book) {
            $json[] = [
                'id' => $book->getId(),
                'url' => $book->getUrl(),
                'shelf_id' => $book->getShelfId(),
                'hide_shelf' => $hideShelf,
                'book_cache' => [
                    'cover' => $this->generateCoverThumbnail($imagineCacheManager, $book, 'books_thumb'),
                ],
                'book_metadata' => [
                    'title' => $book->getBookMetadata()->getTitle(),
                    'creator' => $book->getBookMetadata()->getCreator(),
                ],
                'book_progress' => [
                    'page' => $book->getBookProgress()->getPage(),
                    'total' => count($book->getBookCache()->getLocations()),
                ],
            ];
        }
        return $this->json($json);
    }

    /**
     * @param CacheManager $imagineCacheManager
     * @param Book $book
     * @param string $filter
     * @return string|null
     */
    private function generateCoverThumbnail(CacheManager $imagineCacheManager, Book $book, string $filter): ?string
    {
        if ($book->getBookCache()->getCover()) {
            return $imagineCacheManager->getBrowserPath("/" . $book->getBookCache()->getCover(), $filter);
        }
        return null;
    }

}
