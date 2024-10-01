<?php

namespace App\BooksBundle\Controller;

use App\BooksBundle\Entity\Book;
use App\BooksBundle\Entity\BookCache;
use App\BooksBundle\Entity\BookMetadata;
use App\BooksBundle\Entity\BookProgress;
use App\BooksBundle\Entity\Shelf;
use App\BooksBundle\Repository\BookRepository;
use App\BooksBundle\Repository\ShelfRepository;
use App\CoreBundle\Controller\FileManagerTrait;
use Doctrine\ORM\EntityManagerInterface;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api', name: 'api_')]
class BooksApiController extends AbstractController
{
    use FileManagerTrait;

    const FILE_MANAGER_PATH = '/fileManager';

    const CHANNEL_LIBRARY_ALL = 'https://books.valdi.ovh/library/all';
    const CHANNEL_LIBRARY_SHELVES = 'https://books.valdi.ovh/library/shelves';
    const CHANNEL_LIBRARY_SHELVES_ID = 'https://books.valdi.ovh/library/shelves/%d';
    const CHANNEL_LIBRARY_NOT_IN_SHELVES = 'https://books.valdi.ovh/library/not-in-shelves';

    public function __construct(
        #[Autowire('%books.base_folder%')] private readonly string   $baseFolder,
        #[Autowire('%books.covers_folder%')] private readonly string $coversFolder,
        private readonly EntityManagerInterface                      $entityManager)
    {
    }

    #[Route('/epub/{id}', name: 'epub_id_get', requirements: ['id' => '\d+'], methods: ['GET'], priority: 10)]
    public function apiEpubId(Request $req, #[MapEntity(message: "Book not found.")] Book $book): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->getEpubFile($book->getUrl());
    }

    // TODO Remove epub paths
    #[Route('/epub/{path}', name: 'epub', requirements: ['path' => '.*'], methods: ['GET'])]
    public function apiEpubPath(Request $req, string $path): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->getEpubFile($path);
    }

    protected function getEpubFile(string $path): Response
    {
        $filepath = $this->baseFolder . '/' . $path;
        if (!file_exists($filepath)) {
            return $this->json(['error' => true, 'message' => "Epub file not found."], 400);
        }
        return $this->file(new File($filepath), 'book.epub');
    }

    #[Route('/books/all', name: 'books_all', methods: ['GET'], format: 'json')]
    public function apiBooksAll(Request $req, BookRepository $bookRepo, CacheManager $cacheManager, Authorization $authorization): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $authorization->setCookie($req, [self::CHANNEL_LIBRARY_ALL]);
        if (!$req->query->has('limit')) {
            return $this->json(['error' => true, 'message' => "Parameter limit not found."], 400);
        }
        if (!$req->query->has('offset')) {
            return $this->json(['error' => true, 'message' => "Parameter offset not found."], 400);
        }
        $limit = $req->query->getInt('limit');
        $offset = $req->query->getInt('offset');
        return $this->json(array_map(fn($b) => $b->toJson($cacheManager), $bookRepo->getAll($limit, $offset)));
    }

    #[Route('/books/not-in-shelves', name: 'books_not_in_shelves', methods: ['GET'], format: 'json')]
    public function apiBooksNotInShelves(Request $req, BookRepository $bookRepo, CacheManager $cacheManager, Authorization $authorization): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $authorization->setCookie($req, [self::CHANNEL_LIBRARY_NOT_IN_SHELVES]);
        if (!$req->query->has('limit')) {
            return $this->json(['error' => true, 'message' => "Parameter limit not found."], 400);
        }
        if (!$req->query->has('offset')) {
            return $this->json(['error' => true, 'message' => "Parameter offset not found."], 400);
        }
        $limit = $req->query->getInt('limit');
        $offset = $req->query->getInt('offset');
        return $this->json(array_map(fn($b) => $b->toJson($cacheManager), $bookRepo->getNotInShelves($limit, $offset)));
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

    #[Route('/books/find-new', name: 'books_find_new', methods: ['GET'], format: 'json')]
    public function apiBooksFindNew(Request $req, BookRepository $bookRepo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $files = [];
        $this->searchFiles($files, $this->baseFolder);
        $items = array_diff($files, $bookRepo->getRegisteredPaths());
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

    #[Route('/books', name: 'books_add', methods: ['POST'], format: 'json')]
    public function apiBooksAdd(Request $req, ShelfRepository $shelfRepo, HubInterface $hub, CacheManager $cacheManager): Response
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
            $shelf = $shelfRepo->findOneBy(['path' => $splits[1]]);
            if ($shelf) {
                $book->setShelfId($shelf->getId());
            }
        }
        $this->entityManager->persist($book);
        $this->entityManager->flush();

        $bookCache = (new BookCache())->setBookId($book->getId())->setLocations($body['locations'])->setNavigation($body['navigation']);
        $this->entityManager->persist($bookCache);
        $book->setBookCache($bookCache);

        $bookMetadata = (new BookMetadata())->setBookId($book->getId())->fromJson($body['metadata']);
        $this->entityManager->persist($bookMetadata);
        $book->setBookMetadata($bookMetadata);

        $bookProgress = (new BookProgress())->setBookId($book->getId());
        $this->entityManager->persist($bookProgress);
        $book->setBookProgress($bookProgress);

        $this->entityManager->flush();

        $hub->publish(new Update(
            [
                $book->getShelfId() ? sprintf(self::CHANNEL_LIBRARY_SHELVES_ID, $book->getShelfId()) : self::CHANNEL_LIBRARY_NOT_IN_SHELVES,
                self::CHANNEL_LIBRARY_ALL,
            ],
            json_encode([
                'action' => 'book:add',
                'book' => $book->toJson($cacheManager),
            ]),
            true
        ));
        return $this->json(['id' => $book->getId(), 'shelf_id' => $book->getShelfId()]);
    }

    #[Route('/books/{id}', name: 'books_id_get', requirements: ['id' => '\d+'], methods: ['GET'], format: 'json')]
    public function apiBooksIdGet(Request $req, #[MapEntity(message: "Book not found.")] Book $book, CacheManager $cacheManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->json($book->toJson($cacheManager, 'cover', true, true));
    }

    #[Route('/books/{id}', name: 'books_id_edit', requirements: ['id' => '\d+'], methods: ['PUT'], format: 'json')]
    public function apiBooksIdEdit(Request $req, #[MapEntity(message: "Book not found.")] Book $book): Response
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
        $book->getBookCache()->setLocations($body['locations'])->setNavigation($body['navigation']);
        $book->getBookMetadata()->fromJson($body['metadata']);
        $this->entityManager->flush();
        return $this->json(['id' => $book->getId(), 'shelf_id' => $book->getShelfId()]);
    }

    #[Route('/books/{id}', name: 'books_id_delete', requirements: ['id' => '\d+'], methods: ['DELETE'], format: 'json')]
    public function apiBooksIdDelete(Request $req, #[MapEntity(message: "Book not found.")] Book $book, HubInterface $hub, CacheManager $cacheManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $this->removeCoverFile($cacheManager, $book->getBookCache());

        // Get id before delete
        $bookId = $book->getId();
        $this->entityManager->remove($book);
        $this->entityManager->flush();

        $hub->publish(new Update(
            [
                $book->getShelfId() ? sprintf(self::CHANNEL_LIBRARY_SHELVES_ID, $book->getShelfId()) : self::CHANNEL_LIBRARY_NOT_IN_SHELVES,
                self::CHANNEL_LIBRARY_ALL,
            ],
            json_encode([
                'action' => 'book:remove',
                'book' => ['id' => $bookId],
            ]),
            true
        ));
        return $this->json([]);
    }

    public function removeCoverFile(CacheManager $cacheManager, BookCache $book): void
    {
        if (!$book->getCover()) {
            return;
        }
        $filepath = '/' . $book->getCover();
        $cacheManager->remove($filepath);
        $fullpath = $this->coversFolder . $filepath;
        if (file_exists($fullpath)) {
            unlink($fullpath);
        }
        $book->setCover(null);
        $this->entityManager->flush();
    }

    #[Route('/books/{id}/cover', name: 'books_id_cover_get', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiBooksIdCoverGet(Request $req, #[MapEntity(message: "Book not found.")] BookCache $book): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->file(
            new File($this->coversFolder . '/' . $book->getCover()),
            $book->getCover(),
            ResponseHeaderBag::DISPOSITION_INLINE
        );
    }

    #[Route('/books/{id}/cover', name: 'books_id_cover_add', requirements: ['id' => '\d+'], methods: ['POST'], format: 'json')]
    public function apiBooksIdCoverAdd(Request $req, #[MapEntity(message: "Book not found.")] BookCache $book, CacheManager $cacheManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $this->removeCoverFile($cacheManager, $book);
        if ($req->files->has('cover')) {
            $uuid = Uuid::v7()->toRfc4122();
            $cover = $req->files->get('cover');
            $cover->move($this->coversFolder, $uuid);
            $book->setCover($uuid);
            $this->entityManager->flush();
        }
        return $this->json([]);
    }

    #[Route('/books/{id}/cover', name: 'books_id_cover_delete', requirements: ['id' => '\d+'], methods: ['DELETE'], format: 'json')]
    public function apiBooksIdCoverDelete(Request $req, #[MapEntity(message: "Book not found.")] BookCache $book, CacheManager $cacheManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $this->removeCoverFile($cacheManager, $book);
        $this->entityManager->flush();
        return $this->json([]);
    }

    #[Route('/books/{id}/mark-read', name: 'books_id_mark_read', requirements: ['id' => '\d+'], methods: ['PUT'], format: 'json')]
    public function apiBooksIdMarkRead(Request $req, #[MapEntity(message: "Book not found.")] BookProgress $book): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $book->setPosition(null)->setPage(-1);
        $this->entityManager->flush();
        return $this->json([]);
    }

    #[Route('/books/{id}/mark-unread', name: 'books_id_mark_unread', requirements: ['id' => '\d+'], methods: ['PUT'], format: 'json')]
    public function apiBooksIdMarkUnread(Request $req, #[MapEntity(message: "Book not found.")] BookProgress $book): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $book->setPosition(null)->setPage(0);
        $this->entityManager->flush();
        return $this->json([]);
    }

    #[Route('/books/{id}/metadata', name: 'books_id_metadata', requirements: ['id' => '\d+'], methods: ['GET'], format: 'json')]
    public function apiBooksIdMetadata(Request $req, #[MapEntity(message: "Book not found.")] Book $book, CacheManager $cacheManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->json($book->toJsonMetadata($cacheManager));
    }

    #[Route('/books/{id}/position', name: 'books_id_position', requirements: ['id' => '\d+'], methods: ['PUT'], format: 'json')]
    public function apiBooksIdPosition(Request $req, #[MapEntity(message: "Book not found.")] BookProgress $book): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $body = $req->toArray();
        if (!array_key_exists('position', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter offset not found."], 400);
        }
        if (!array_key_exists('page', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter page not found."], 400);
        }
        $book->setPosition($body['position'])->setPage($body['page']);
        if (array_key_exists('update', $body) && $body['update']) {
            $book->updateLastRead();
        }
        $this->entityManager->flush();
        return $this->json([]);
    }

    #[Route('/shelves', name: 'shelves', methods: ['GET'], format: 'json')]
    public function apiShelves(Request $req, #[MapEntity(class: Shelf::class, expr: 'repository.findBy({}, {"name": "ASC"})')] iterable $shelves): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->json($shelves);
    }

    #[Route('/shelves', name: 'shelves_add', methods: ['POST'], format: 'json')]
    public function apiShelvesAdd(Request $req, BookRepository $bookRepo): Response
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
        $this->entityManager->persist($shelf);
        $this->entityManager->flush();
        $books = $bookRepo->getWithPath($shelf->getPath());
        foreach ($books as $book) {
            $book->setShelfId($shelf->getId());
        }
        $this->entityManager->flush();
        return $this->json($shelf);
    }

    #[Route('/shelves/{id}', name: 'shelves_id_edit', requirements: ['id' => '\d+'], methods: ['PUT'], format: 'json')]
    public function apiShelvesEdit(Request $req, #[MapEntity(message: "Shelf not found.")] Shelf $shelf): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $body = $req->toArray();
        if (!array_key_exists('name', $body)) {
            return $this->json(['error' => true, 'message' => "Parameter name not found."], 400);
        }
        $shelf->setName($body['name']);
        $this->entityManager->flush();
        return $this->json($shelf);
    }

    #[Route('/shelves/{id}', name: 'shelves_id_delete', requirements: ['id' => '\d+'], methods: ['DELETE'], format: 'json')]
    public function apiShelvesDelete(Request $req, #[MapEntity(message: "Shelf not found.")] Shelf $shelf, #[MapEntity(class: Book::class, expr: 'repository.findBy({"shelf_id": id})')] iterable $books): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        foreach ($books as $book) {
            $book->setShelfId(null);
        }
        $this->entityManager->flush();
        $this->entityManager->remove($shelf);
        $this->entityManager->flush();
        return $this->json([]);
    }

    #[Route('/shelves/{id}/books', name: 'shelves_id_books', requirements: ['id' => '\d+'], methods: ['GET'], format: 'json')]
    public function apiShelvesIdBooks(Request $req, #[MapEntity(message: "Shelf not found.")] Shelf $shelf, CacheManager $cacheManager, Authorization $authorization): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $authorization->setCookie($req, [sprintf(self::CHANNEL_LIBRARY_SHELVES_ID, $shelf->getId())]);
        return $this->json(array_map(fn($b) => $b->toJson($cacheManager), $shelf->getBooks()->toArray()));
    }

}
