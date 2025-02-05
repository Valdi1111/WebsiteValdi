<?php

namespace App\BooksBundle\Controller;

use App\BooksBundle\Entity\Book;
use App\BooksBundle\Entity\BookCache;
use App\BooksBundle\Entity\BookMetadata;
use App\BooksBundle\Entity\BookProgress;
use App\BooksBundle\Entity\Channel;
use App\BooksBundle\Entity\Library;
use App\BooksBundle\Normalizer\BookCacheNormalizer;
use App\BooksBundle\Repository\BookRepository;
use App\BooksBundle\Repository\LibraryRepository;
use App\BooksBundle\Repository\ShelfRepository;
use App\BooksBundle\Service\BookCoverLoader;
use App\BooksBundle\Service\EbookLoader;
use App\CoreBundle\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemReader;
use League\Flysystem\StorageAttributes;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
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

#[IsGranted('ROLE_USER_BOOKS', null, 'Access Denied.')]
#[Route('/api/libraries/{library}', name: 'api_libraries_id_', requirements: ['library' => '\d+'], format: 'json')]
class ApiBooksController extends AbstractController
{

    private Library $library;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LibraryRepository      $libraryRepo,
        private readonly RequestStack           $requestStack)
    {
        $req = $this->requestStack->getCurrentRequest();
        $library = $this->libraryRepo->findOneBy(['id' => $req->attributes->getInt('library')]);
        if (!$library) {
            throw $this->createNotFoundException("Library not found.");
        }
        $this->library = $library;
    }

    protected function getLibrary(): Library
    {
        return $this->library;
    }

    protected function getFilesystem(): Filesystem
    {
        return $this->getLibrary()->getFilesystem();
    }

    #[Route('/books/{book}/epub', name: 'books_id_epub', requirements: ['id' => '\d+'], methods: ['GET'], priority: 10)]
    public function apiEpubId(#[MapEntity(message: "Book not found.")] Book $book): Response
    {
        return $this->apiEpubPath($book->getUrl());
    }

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/epub/{path}', name: 'epub_path', requirements: ['path' => '.*'], methods: ['GET'])]
    public function apiEpubPath(string $path): Response
    {
        if (!$this->getFilesystem()->fileExists($path)) {
            throw new BadRequestHttpException("Epub file not found.");
        }
        $filename = basename($path);
        return new Response($this->getFilesystem()->read($path), 200, [
            'Content-Type' => "application/epub+zip",
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }

    #[Route('/books/all', name: 'books_all', methods: ['GET'])]
    public function apiBooksAll(
        Request $req,
        #[CurrentUser] ?User $user,
        #[MapQueryParameter(filter: \FILTER_VALIDATE_INT, options: ['min_range' => 0])] int $limit,
        #[MapQueryParameter(filter: \FILTER_VALIDATE_INT, options: ['min_range' => 0])] int $offset,
        BookRepository $bookRepo,
        Authorization $authorization
    ): Response
    {
        $authorization->setCookie($req, [Channel::LIBRARY_ALL]);
        return $this->json($bookRepo->getAll($this->getLibrary(), $user, $limit, $offset), 200, [], [
            BookCacheNormalizer::FILTER_TYPE => BookCacheNormalizer::FILTER_THUMB,
            'groups' => ['book:list']
        ]);
    }

    #[Route('/books/not-in-shelves', name: 'books_not_in_shelves', methods: ['GET'])]
    public function apiBooksNotInShelves(
        Request $req,
        #[CurrentUser] ?User $user,
        #[MapQueryParameter(filter: \FILTER_VALIDATE_INT, options: ['min_range' => 0])] int $limit,
        #[MapQueryParameter(filter: \FILTER_VALIDATE_INT, options: ['min_range' => 0])] int $offset,
        BookRepository $bookRepo,
        Authorization $authorization
    ): Response
    {
        $authorization->setCookie($req, [Channel::LIBRARY_NOT_IN_SHELVES]);
        return $this->json($bookRepo->getNotInShelves($this->getLibrary(), $user, $limit, $offset), 200, [], [
            BookCacheNormalizer::FILTER_TYPE => BookCacheNormalizer::FILTER_THUMB,
            'groups' => ['book:list']
        ]);
    }

    protected function searchFiles(): array
    {
        /** @var FileAttributes[] $items */
        $items = $this->getFilesystem()
            ->listContents("/", FilesystemReader::LIST_DEEP)
            ->filter(function (StorageAttributes $item) {
                return $item->isFile() && pathinfo($item->path(), PATHINFO_EXTENSION) === 'epub';
            })
            ->toArray();
        $files = [];
        foreach ($items as $item) {
            $files[] = "/{$item->path()}";
        }
        return $files;
    }

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/books/find-new', name: 'books_find_new', methods: ['GET'])]
    public function apiBooksFindNew(BookRepository $bookRepo): Response
    {
        $items = array_diff($this->searchFiles(), $bookRepo->getRegisteredPaths($this->getLibrary()));
        sort($items);
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
    public function apiBooksAdd(Request $req, #[CurrentUser] ?User $user, ShelfRepository $shelfRepo, EbookLoader $ebookLoader, NormalizerInterface $normalizer, HubInterface $hub): Response
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
            ->setUrl($req->getPayload()->getString('url'))
            ->setLibrary($this->getLibrary());
        $splits = explode('/', $book->getUrl());
        if (count($splits) > 2) {
            $shelf = $shelfRepo->findOneBy(['path' => $splits[1]]);
            $book->setShelf($shelf);
        }

        $ebookLoader->load($book);
        $serializer = new Serializer([$normalizer]);
        // Book cache
        $cache = $serializer->denormalize($req->getPayload()->all('book_cache'), BookCache::class);
        $cache->setCover($ebookLoader->hasCover());
        $book->setBookCache($cache);
        // Book metadata
        $metadata = $serializer->denormalize($req->getPayload()->all('book_metadata'), BookMetadata::class);
        $book->setBookMetadata($metadata);
        // Book progress
        $progress = (new BookProgress())->setUser($user);
        $book->addBookProgress($progress);

        $this->entityManager->persist($book);
        $this->entityManager->flush();

        // TODO funzione per publish, publish anche su Channel::LIBRARY_NOT_IN_SHELVES se necessario
        $hub->publish(new Update(
            [
                $book->getShelfId() ? sprintf(Channel::LIBRARY_SHELVES_ID, $book->getShelfId()) : Channel::LIBRARY_NOT_IN_SHELVES,
                Channel::LIBRARY_ALL,
            ],
            json_encode([
                'action' => 'book:add',
                'book' => $normalizer->normalize($book, null, [
                    BookCacheNormalizer::FILTER_TYPE => BookCacheNormalizer::FILTER_THUMB,
                    'groups' => ['book:list']
                ]),
            ]),
            true
        ));
        return $this->json(['id' => $book->getId(), 'shelf_id' => $book->getShelfId()]);
    }

    #[Route('/books/{book}', name: 'books_id_get', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiBooksIdGet(#[MapEntity(message: "Book not found.")] Book $book): Response
    {
        return $this->json($book, 200, [], [
            BookCacheNormalizer::FILTER_TYPE => BookCacheNormalizer::FILTER_COVER
        ]);
    }

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/books/{book}', name: 'books_id_edit', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function apiBooksIdEdit(Request $req, #[MapEntity(message: "Book not found.")] Book $book, EbookLoader $ebookLoader, NormalizerInterface $normalizer, HubInterface $hub, CacheManager $cacheManager): Response
    {
        if (!$req->getPayload()->has('book_cache')) {
            throw new BadRequestHttpException("Parameter 'book_cache' not found.");
        }
        if (!$req->getPayload()->has('book_metadata')) {
            throw new BadRequestHttpException("Parameter 'book_metadata' not found.");
        }

        if ($book->getBookCache()->hasCover()) {
            $cacheManager->remove($book->getId());
        }

        $ebookLoader->load($book);
        $serializer = new Serializer([$normalizer]);
        // Book cache
        $serializer->denormalize($req->getPayload()->all('book_cache'), BookCache::class, null, [AbstractNormalizer::OBJECT_TO_POPULATE => $book->getBookCache()]);
        $book->getBookCache()->setCover($ebookLoader->hasCover());
        // Book metadata
        $serializer->denormalize($req->getPayload()->all('book_metadata'), BookMetadata::class, null, [AbstractNormalizer::OBJECT_TO_POPULATE => $book->getBookMetadata()]);

        $this->entityManager->flush();

        // TODO funzione per publish, publish anche su Channel::LIBRARY_NOT_IN_SHELVES se necessario
        $hub->publish(new Update(
            [
                $book->getShelfId() ? sprintf(Channel::LIBRARY_SHELVES_ID, $book->getShelfId()) : Channel::LIBRARY_NOT_IN_SHELVES,
                Channel::LIBRARY_ALL,
            ],
            json_encode([
                'action' => 'book:recreate',
                'book' => $normalizer->normalize($book, null, [
                    BookCacheNormalizer::FILTER_TYPE => BookCacheNormalizer::FILTER_THUMB,
                    'groups' => ['book:list']
                ]),
            ]),
            true
        ));
        return $this->json(['id' => $book->getId(), 'shelf_id' => $book->getShelfId()]);
    }

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/books/{book}', name: 'books_id_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function apiBooksIdDelete(#[MapEntity(message: "Book not found.")] Book $book, HubInterface $hub, CacheManager $cacheManager): Response
    {
        $id = $book->getId();
        if ($book->getBookCache()->hasCover()) {
            $cacheManager->remove($book->getId());
        }

        $this->entityManager->remove($book);
        $this->entityManager->flush();

        // TODO funzione per publish, publish anche su Channel::LIBRARY_NOT_IN_SHELVES se necessario
        $hub->publish(new Update(
            [
                $book->getShelfId() ? sprintf(Channel::LIBRARY_SHELVES_ID, $book->getShelfId()) : Channel::LIBRARY_NOT_IN_SHELVES,
                Channel::LIBRARY_ALL,
            ],
            json_encode([
                'action' => 'book:remove',
                'book' => ['id' => $id],
            ]),
            true
        ));
        return $this->json(['id' => $id]);
    }

    #[Route('/books/{book}/cover', name: 'books_id_cover_get', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiBooksIdCoverGet(#[MapEntity(message: "Book not found.")] Book $book, BookCoverLoader $bookCoverLoader): Response
    {
        try {
            $binary = $bookCoverLoader->find($book->getId());
            return new Response($binary->getContent(), 200, [
                'Content-Type' => $binary->getMimeType(),
                'Content-Disposition' => "inline; filename=\"cover_{$book->getId()}.{$binary->getFormat()}\"",
            ]);
        } catch (\Exception) {
            throw new BadRequestHttpException("Cover file not found.");
        }
    }

    #[Route('/books/{book}/mark-{type}', name: 'books_id_mark', requirements: ['id' => '\d+', 'type' => 'read|unread'], methods: ['PUT'])]
    public function apiBooksIdMarkRead(#[CurrentUser] ?User $user, #[MapEntity(message: "Book not found.")] Book $book, string $type): Response
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
        return $this->json(['id' => $book->getId()]);
    }

    #[Route('/books/{book}/metadata', name: 'books_id_metadata', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiBooksIdMetadata(#[MapEntity(message: "Book not found.")] Book $book): Response
    {
        return $this->json($book, 200, [], [
            BookCacheNormalizer::FILTER_TYPE => BookCacheNormalizer::FILTER_COVER,
            'groups' => ['book:metadata']
        ]);
    }

    #[Route('/books/{book}/position', name: 'books_id_position', requirements: ['id' => '\d+'], methods: ['PUT'])]
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
        return $this->json(['id' => $book->getId()]);
    }

}
