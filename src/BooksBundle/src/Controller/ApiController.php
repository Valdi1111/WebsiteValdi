<?php

namespace App\BooksBundle\Controller;

use App\BooksBundle\Entity\Book;
use App\BooksBundle\Entity\BookCache;
use App\BooksBundle\Entity\BookMetadata;
use App\BooksBundle\Entity\BookProgress;
use App\BooksBundle\Entity\Shelf;
use App\BooksBundle\Normalizer\BookCacheNormalizer;
use App\BooksBundle\Repository\BookRepository;
use App\BooksBundle\Repository\ShelfRepository;
use App\CoreBundle\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_USER_BOOKS', null, 'Access Denied.')]
#[Route('/api', name: 'api_', format: 'json')]
class ApiController extends AbstractController
{

    const string CHANNEL_LIBRARY_ALL = 'https://books.valdi.ovh/library/all';
    const string CHANNEL_LIBRARY_SHELVES = 'https://books.valdi.ovh/library/shelves';
    const string CHANNEL_LIBRARY_SHELVES_ID = 'https://books.valdi.ovh/library/shelves/%d';
    const string CHANNEL_LIBRARY_NOT_IN_SHELVES = 'https://books.valdi.ovh/library/not-in-shelves';

    public function __construct(
        #[Autowire('%books.base_folder%')]
        private readonly string                 $baseFolder,
        #[Autowire('%books.covers_folder%')]
        private readonly string                 $coversFolder,
        private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('/epub/{id}', name: 'epub_id_get', requirements: ['id' => '\d+'], methods: ['GET'], priority: 10)]
    public function apiEpubId(Request $req, #[MapEntity(message: "Book not found.")] Book $book): Response
    {
        return $this->getEpubFile($book->getUrl());
    }

    // TODO Remove epub paths
    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/epub/{path}', name: 'epub', requirements: ['path' => '.*'], methods: ['GET'])]
    public function apiEpubPath(Request $req, string $path): Response
    {
        return $this->getEpubFile($path);
    }

    protected function getEpubFile(string $path): Response
    {
        $filepath = $this->baseFolder . '/' . $path;
        if (!file_exists($filepath)) {
            throw new BadRequestHttpException("Epub file not found.");
        }
        return $this->file(new File($filepath), 'book.epub');
    }

    #[Route('/books/all', name: 'books_all', methods: ['GET'])]
    public function apiBooksAll(Request $req, #[CurrentUser] ?User $user, BookRepository $bookRepo, Authorization $authorization): Response
    {
        if (!$req->query->has('limit')) {
            throw new BadRequestHttpException("Parameter 'limit' not found.");
        }
        if (!$req->query->has('offset')) {
            throw new BadRequestHttpException("Parameter 'offset' not found.");
        }
        $limit = $req->query->getInt('limit');
        $offset = $req->query->getInt('offset');
        $authorization->setCookie($req, [self::CHANNEL_LIBRARY_ALL]);
        return $this->json($bookRepo->getAll($user, $limit, $offset), 200, [], [BookCacheNormalizer::COVER_FILTER => 'books_thumb', 'groups' => ['book:list']]);
    }

    #[Route('/books/not-in-shelves', name: 'books_not_in_shelves', methods: ['GET'])]
    public function apiBooksNotInShelves(Request $req, #[CurrentUser] ?User $user, BookRepository $bookRepo, Authorization $authorization): Response
    {
        if (!$req->query->has('limit')) {
            throw new BadRequestHttpException("Parameter 'limit' not found.");
        }
        if (!$req->query->has('offset')) {
            throw new BadRequestHttpException("Parameter 'offset' not found.");
        }
        $limit = $req->query->getInt('limit');
        $offset = $req->query->getInt('offset');
        $authorization->setCookie($req, [self::CHANNEL_LIBRARY_NOT_IN_SHELVES]);
        return $this->json($bookRepo->getNotInShelves($user, $limit, $offset), 200, [], [BookCacheNormalizer::COVER_FILTER => 'books_thumb', 'groups' => ['book:list']]);
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

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/books/find-new', name: 'books_find_new', methods: ['GET'])]
    public function apiBooksFindNew(Request $req, BookRepository $bookRepo): Response
    {
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

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/books', name: 'books_add', methods: ['POST'])]
    public function apiBooksAdd(Request $req, #[CurrentUser] ?User $user, ShelfRepository $shelfRepo, NormalizerInterface $normalizer, HubInterface $hub): Response
    {
        if (!$req->getPayload()->has('url')) {
            throw new BadRequestHttpException("Parameter 'url' not found.");
        }
        if (!$req->getPayload()->has('book_cache')) {
            throw new BadRequestHttpException("Parameter 'book_cache' not found.");
        }
        if (!$req->getPayload()->has('book_metadata')) {
            throw new BadRequestHttpException("Parameter 'book_metadata' not found.");
        }
        $book = (new Book())
            ->setUrl($req->getPayload()->getString('url'));
        $splits = explode('/', $book->getUrl());
        if (count($splits) > 2) {
            $shelf = $shelfRepo->findOneBy(['path' => $splits[1]]);
            $book->setShelf($shelf);
        }

        $serializer = new Serializer([$normalizer]);
        // Book cache
        $cache = $serializer->denormalize($req->getPayload()->all('book_cache'), BookCache::class);
        $book->setBookCache($cache);
        // Book metadata
        $metadata = $serializer->denormalize($req->getPayload()->all('book_metadata'), BookMetadata::class);
        $book->setBookMetadata($metadata);
        // Book progress
        $progress = (new BookProgress())->setUser($user);
        $book->addBookProgress($progress);

        $this->entityManager->persist($book);
        $this->entityManager->flush();

        $hub->publish(new Update(
            [
                $book->getShelfId() ? sprintf(self::CHANNEL_LIBRARY_SHELVES_ID, $book->getShelfId()) : self::CHANNEL_LIBRARY_NOT_IN_SHELVES,
                self::CHANNEL_LIBRARY_ALL,
            ],
            json_encode([
                'action' => 'book:add',
                'book' => $normalizer->normalize($book, null, [BookCacheNormalizer::COVER_FILTER => 'books_thumb', 'groups' => ['book:list']]),
            ]),
            true
        ));
        return $this->json(['id' => $book->getId(), 'shelf_id' => $book->getShelfId()]);
    }

    #[Route('/books/{id}', name: 'books_id_get', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiBooksIdGet(Request $req, #[MapEntity(message: "Book not found.")] Book $book): Response
    {
        return $this->json($book, 200, [], [BookCacheNormalizer::COVER_FILTER => 'books_cover']);
    }

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/books/{id}', name: 'books_id_edit', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function apiBooksIdEdit(Request $req, #[MapEntity(message: "Book not found.")] Book $book, NormalizerInterface $normalizer): Response
    {
        if (!$req->getPayload()->has('book_cache')) {
            throw new BadRequestHttpException("Parameter 'book_cache' not found.");
        }
        if (!$req->getPayload()->has('book_metadata')) {
            throw new BadRequestHttpException("Parameter 'book_metadata' not found.");
        }

        $serializer = new Serializer([$normalizer]);
        // Book cache
        $serializer->denormalize($req->getPayload()->all('book_cache'), BookCache::class, null, [AbstractNormalizer::OBJECT_TO_POPULATE => $book->getBookCache()]);
        // Book metadata
        $serializer->denormalize($req->getPayload()->all('book_metadata'), BookMetadata::class, null, [AbstractNormalizer::OBJECT_TO_POPULATE => $book->getBookMetadata()]);

        $this->entityManager->flush();
        return $this->json(['id' => $book->getId(), 'shelf_id' => $book->getShelfId()]);
    }

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/books/{id}', name: 'books_id_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function apiBooksIdDelete(Request $req, #[MapEntity(message: "Book not found.")] Book $book, int $id, HubInterface $hub, CacheManager $cacheManager): Response
    {
        $this->removeCoverFile($cacheManager, $book->getBookCache());

        $this->entityManager->remove($book);
        $this->entityManager->flush();

        $hub->publish(new Update(
            [
                $book->getShelfId() ? sprintf(self::CHANNEL_LIBRARY_SHELVES_ID, $book->getShelfId()) : self::CHANNEL_LIBRARY_NOT_IN_SHELVES,
                self::CHANNEL_LIBRARY_ALL,
            ],
            json_encode([
                'action' => 'book:remove',
                'book' => ['id' => $id],
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
        return $this->file(
            new File($this->coversFolder . '/' . $book->getCover()),
            $book->getCover(),
            ResponseHeaderBag::DISPOSITION_INLINE
        );
    }

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/books/{id}/cover', name: 'books_id_cover_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function apiBooksIdCoverAdd(Request $req, #[MapEntity(message: "Book not found.")] BookCache $book, CacheManager $cacheManager): Response
    {
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

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/books/{id}/cover', name: 'books_id_cover_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function apiBooksIdCoverDelete(Request $req, #[MapEntity(message: "Book not found.")] BookCache $book, CacheManager $cacheManager): Response
    {
        $this->removeCoverFile($cacheManager, $book);
        $this->entityManager->flush();
        return $this->json([]);
    }

    #[Route('/books/{id}/mark-{type}', name: 'books_id_mark_read', requirements: ['id' => '\d+', 'type' => 'read|unread'], methods: ['PUT'])]
    public function apiBooksIdMarkRead(Request $req, #[CurrentUser] ?User $user, #[MapEntity(message: "Book not found.")] Book $book, string $type): Response
    {
        $progress = $book->getBookProgress($user);
        if (!$progress) {
            $progress = (new BookProgress())->setUser($user);
            $book->addBookProgress($progress);
        }
        $progress->setPosition(null);
        if ($type === 'read') {
            $progress->setPage(-1);
        }
        if ($type === 'unread') {
            $progress->setPage(0);
        }
        $this->entityManager->flush();
        return $this->json([]);
    }

    #[Route('/books/{id}/metadata', name: 'books_id_metadata', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiBooksIdMetadata(Request $req, #[MapEntity(message: "Book not found.")] Book $book): Response
    {
        return $this->json($book, 200, [], [BookCacheNormalizer::COVER_FILTER => 'books_cover', 'groups' => ['book:metadata']]);
    }

    #[Route('/books/{id}/position', name: 'books_id_position', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function apiBooksIdPosition(Request $req, #[CurrentUser] ?User $user, #[MapEntity(message: "Book not found.")] Book $book): Response
    {
        $progress = $book->getBookProgress($user);
        if (!$progress) {
            $progress = (new BookProgress())->setUser($user);
            $book->addBookProgress($progress);
        }
        if (!$req->getPayload()->has('position')) {
            throw new BadRequestHttpException("Parameter 'offset' not found.");
        }
        if (!$req->getPayload()->has('page')) {
            throw new BadRequestHttpException("Parameter 'page' not found.");
        }
        $progress->setPosition($req->getPayload()->getString('position'))
            ->setPage($req->getPayload()->getInt('page'));
        if ($req->getPayload()->getBoolean('update', true)) {
            $progress->updateLastRead();
        }
        $this->entityManager->flush();
        return $this->json([]);
    }

    #[Route('/shelves', name: 'shelves', methods: ['GET'])]
    public function apiShelves(Request $req, #[MapEntity(class: Shelf::class, expr: 'repository.findBy({}, {"name": "ASC"})')] iterable $shelves): Response
    {
        return $this->json($shelves);
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
            ->setName($req->getPayload()->getString('name'));
        foreach ($bookRepo->getWithPath($shelf->getPath()) as $book) {
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
    public function apiShelvesDelete(Request $req, #[MapEntity(message: "Shelf not found.")] Shelf $shelf): Response
    {
        $this->entityManager->remove($shelf);
        $this->entityManager->flush();
        return $this->json([]);
    }

    #[Route('/shelves/{id}/books', name: 'shelves_id_books', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiShelvesIdBooks(Request $req, #[MapEntity(message: "Shelf not found.")] Shelf $shelf, Authorization $authorization): Response
    {
        $authorization->setCookie($req, [sprintf(self::CHANNEL_LIBRARY_SHELVES_ID, $shelf->getId())]);
        return $this->json($shelf->getBooks(), 200, [], [BookCacheNormalizer::COVER_FILTER => 'books_thumb', 'groups' => ['book:list']]);
    }

}
