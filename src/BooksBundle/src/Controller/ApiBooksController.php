<?php

namespace App\BooksBundle\Controller;

use App\BooksBundle\Entity\Book;
use App\BooksBundle\Entity\BookMetadata;
use App\BooksBundle\Entity\BookProgress;
use App\BooksBundle\Entity\Library;
use App\BooksBundle\Model\Channel;
use App\BooksBundle\Model\EbookLoader;
use App\BooksBundle\Normalizer\BookMetadataNormalizer;
use App\BooksBundle\Repository\BookRepository;
use App\BooksBundle\Repository\LibraryRepository;
use App\BooksBundle\Repository\ShelfRepository;
use App\BooksBundle\Service\BookCoverLoader;
use App\CoreBundle\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemReader;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\StorageAttributes;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
        $library = $this->libraryRepo->find($req->attributes->getInt('library'));
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

    #[Route('/books/{book}/book.epub', name: 'books_id_epub_extension', requirements: ['book' => '\d+'], methods: ['GET'], priority: 10)]
    #[Route('/books/{book}/epub', name: 'books_id_epub', requirements: ['book' => '\d+'], methods: ['GET'], priority: 10)]
    public function apiEpubId(#[MapEntity(message: "Book not found.")] Book $book, Request $request): Response
    {
        return $this->serveEpub($book->getUrl(), $request);
    }

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/epub/{path}', name: 'epub_path', requirements: ['path' => '.*'], methods: ['GET'])]
    public function apiEpubPath(string $path, Request $request): Response
    {
        return $this->serveEpub($path, $request);
    }

    /**
     * Unifies delivery for both Book ID and raw path with traversal protection and Range support.
     */
    private function serveEpub(string $rawPath, Request $request): Response
    {
        // 1. Decode and sanitize incoming path
        $path = ltrim(rawurldecode($rawPath), '/\\');

        // 2. Traversal protection: reject explicit unsafe path sequences
        if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, "\0")) {
            throw new BadRequestHttpException("Invalid path: directory traversal is forbidden.");
        }

        // 3. Check file existence via Flysystem
        try {
            if (!$this->getFilesystem()->fileExists($path)) {
                throw new NotFoundHttpException("Epub file not found.");
            }
        } catch (PathTraversalDetected $e) {
            throw new BadRequestHttpException("Path traversal attempt detected: " . $e->getMessage());
        }

        // 4. Resolve and enforce storage boundaries on the local filesystem
        $baseDir = realpath($this->getLibrary()->getBasePath());
        if ($baseDir === false) {
            throw new \RuntimeException("Configured storage directory does not exist.");
        }

        $fullPath = realpath(Path::join($baseDir, $path));

        // Ensure the physical file exists and stays confined within the base directory
        if ($fullPath === false || !str_starts_with($fullPath, $baseDir . DIRECTORY_SEPARATOR)) {
            throw new BadRequestHttpException("Access denied: path points outside allowed storage boundary.");
        }

        $filename = basename($fullPath);

        // 5. BinaryFileResponse automatically handles Range headers (HTTP 206 Partial Content)
        $response = $this->file(
            $fullPath,
            fileName: $filename,
            disposition: ResponseHeaderBag::DISPOSITION_INLINE
        );

        // Required when the client fetches the endpoint via range/CORS requests
        $response->headers->set('Access-Control-Expose-Headers', 'Content-Range, Accept-Ranges, Content-Length');

        return $response;
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
        return $this->json($bookRepo->getAll($this->getLibrary(), $user, $limit, $offset), context: [
            BookMetadataNormalizer::FILTER_TYPE => BookMetadataNormalizer::FILTER_THUMB,
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
        return $this->json($bookRepo->getNotInShelves($this->getLibrary(), $user, $limit, $offset), context: [
            BookMetadataNormalizer::FILTER_TYPE => BookMetadataNormalizer::FILTER_THUMB,
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
    public function apiBooksAdd(Request $req, #[CurrentUser] ?User $user, ShelfRepository $shelfRepo, NormalizerInterface $normalizer, HubInterface $hub): Response
    {
        if (!$req->getPayload()->has('url')) {
            throw new BadRequestHttpException("Parameter 'url' not found.");
        }
        if (!$req->getPayload()->has('book_metadata')) {
            throw new BadRequestHttpException("Parameter 'book_metadata' not found.");
        }
        $book = new Book()
            ->setUrl($req->getPayload()->getString('url'))
            ->setLibrary($this->getLibrary());
        $splits = explode('/', $book->getUrl());
        if (count($splits) > 2) {
            $shelf = $shelfRepo->findOneBy(['path' => '/' . $splits[1]]);
            $book->setShelf($shelf);
        }

        $ebookLoader = new EbookLoader();
        $ebookLoader->load($book);
        $serializer = new Serializer([$normalizer]);
        // Book metadata
        $metadata = $serializer->denormalize($req->getPayload()->all('book_metadata'), BookMetadata::class);
        $metadata->setCover($ebookLoader->hasCover());
        $book->setBookMetadata($metadata);
        // Book progress
        $progress = new BookProgress()->setUser($user);
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
                    BookMetadataNormalizer::FILTER_TYPE => BookMetadataNormalizer::FILTER_THUMB,
                    'groups' => ['book:list']
                ]),
            ]),
            true
        ));
        return $this->json(['id' => $book->getId(), 'shelf_id' => $book->getShelfId()]);
    }

    #[Route('/books/{book}', name: 'books_id_get', requirements: ['book' => '\d+'], methods: ['GET'])]
    public function apiBooksIdGet(#[MapEntity(message: "Book not found.")] Book $book): Response
    {
        return $this->json($book, context: [
            BookMetadataNormalizer::FILTER_TYPE => BookMetadataNormalizer::FILTER_COVER
        ]);
    }

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/books/{book}', name: 'books_id_edit', requirements: ['book' => '\d+'], methods: ['PUT'])]
    public function apiBooksIdEdit(Request $req, #[MapEntity(message: "Book not found.")] Book $book, NormalizerInterface $normalizer, HubInterface $hub, CacheManager $cacheManager): Response
    {
        if (!$req->getPayload()->has('book_metadata')) {
            throw new BadRequestHttpException("Parameter 'book_metadata' not found.");
        }

        if ($book->getBookMetadata()->hasCover()) {
            $cacheManager->remove($book->getId());
        }

        $ebookLoader = new EbookLoader();
        $ebookLoader->load($book);
        $serializer = new Serializer([$normalizer]);
        // Book metadata
        $serializer->denormalize($req->getPayload()->all('book_metadata'), BookMetadata::class, null, [AbstractNormalizer::OBJECT_TO_POPULATE => $book->getBookMetadata()]);
        $book->getBookMetadata()->setCover($ebookLoader->hasCover());

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
                    BookMetadataNormalizer::FILTER_TYPE => BookMetadataNormalizer::FILTER_THUMB,
                    'groups' => ['book:list']
                ]),
            ]),
            true
        ));
        return $this->json(['id' => $book->getId(), 'shelf_id' => $book->getShelfId()]);
    }

    #[IsGranted('ROLE_ADMIN_BOOKS', null, 'Access Denied.')]
    #[Route('/books/{book}', name: 'books_id_delete', requirements: ['book' => '\d+'], methods: ['DELETE'])]
    public function apiBooksIdDelete(#[MapEntity(message: "Book not found.")] Book $book, HubInterface $hub, CacheManager $cacheManager): Response
    {
        $id = $book->getId();
        if ($book->getBookMetadata()->hasCover()) {
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

    #[Route('/books/{book}/cover', name: 'books_id_cover_get', requirements: ['book' => '\d+'], methods: ['GET'])]
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

    #[Route('/books/{book}/mark-{type}', name: 'books_id_mark', requirements: ['book' => '\d+', 'type' => 'read|unread'], methods: ['PUT'])]
    public function apiBooksIdMarkRead(#[CurrentUser] ?User $user, #[MapEntity(message: "Book not found.")] Book $book, string $type): Response
    {
        $progress = $book->getBookProgress($user);
        if (!$progress) {
            $progress = new BookProgress()->setUser($user);
            $book->addBookProgress($progress);
        }
        $progress->setPosition(null);
        if ($type === 'read') {
            $progress->setCurrent(-1)
                ->setFraction(1);
        }
        if ($type === 'unread') {
            $progress->setCurrent(0)
                ->setFraction(0);
        }
        $this->entityManager->flush();
        return $this->json(['id' => $book->getId()]);
    }

    #[Route('/books/{book}/metadata', name: 'books_id_metadata', requirements: ['book' => '\d+'], methods: ['GET'])]
    public function apiBooksIdMetadata(#[MapEntity(message: "Book not found.")] Book $book): Response
    {
        return $this->json($book, context: [
            BookMetadataNormalizer::FILTER_TYPE => BookMetadataNormalizer::FILTER_COVER,
            'groups' => ['book:metadata']
        ]);
    }

    #[Route('/books/{book}/position', name: 'books_id_position', requirements: ['book' => '\d+'], methods: ['PUT'])]
    public function apiBooksIdPosition(Request $req, #[CurrentUser] ?User $user, #[MapEntity(message: "Book not found.")] Book $book): Response
    {
        $progress = $book->getBookProgress($user);
        if (!$progress) {
            $progress = new BookProgress()->setUser($user);
            $book->addBookProgress($progress);
        }
        if (!$req->getPayload()->has('position')) {
            throw new BadRequestHttpException("Parameter 'position' not found.");
        }
        if (!$req->getPayload()->has('current')) {
            throw new BadRequestHttpException("Parameter 'current' not found.");
        }
        if (!$req->getPayload()->has('total')) {
            throw new BadRequestHttpException("Parameter 'total' not found.");
        }
        if (!$req->getPayload()->has('fraction')) {
            throw new BadRequestHttpException("Parameter 'fraction' not found.");
        }
        $progress->setPosition($req->getPayload()->getString('position'))
            ->setCurrent($req->getPayload()->getInt('current'))
            ->setTotal($req->getPayload()->getInt('total'))
            ->setFraction($req->getPayload()->get('fraction'));
        if ($req->getPayload()->getBoolean('update', true)) {
            $progress->updateLastRead();
        }
        $this->entityManager->flush();
        return $this->json(['id' => $book->getId()]);
    }

}
