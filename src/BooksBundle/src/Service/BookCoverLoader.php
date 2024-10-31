<?php

namespace App\BooksBundle\Service;

use App\BooksBundle\Repository\BookRepository;
use Liip\ImagineBundle\Binary\BinaryInterface;
use Liip\ImagineBundle\Binary\Loader\LoaderInterface;
use Liip\ImagineBundle\Exception\Binary\Loader\NotLoadableException;
use Liip\ImagineBundle\Model\Binary;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('liip_imagine.binary.loader', attributes: ['loader' => 'books.book_cover_loader'])]
class BookCoverLoader implements LoaderInterface
{
    private array $mimeTypes = [
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public function __construct(
        private readonly BookRepository $bookRepository,
        private readonly EbookLoader    $ebookLoader
    )
    {
    }

    public function find($path): ?BinaryInterface
    {
        $book = $this->bookRepository->find(['id' => $path]);
        if (!$book) {
            throw new NotLoadableException(sprintf('Cover image not resolvable, book with id "%s" not found', $path));
        }
        $library = $book->getLibrary();
        if (!$library) {
            throw new NotLoadableException(sprintf('Cover image not resolvable, book with id "%s" is not in a valid library', $path));
        }

        $filepath = $library->getBasePath() . $book->getUrl();
        if (!file_exists($filepath)) {
            throw new NotLoadableException(sprintf('Cover image not resolvable, book with id "%s" has an invalid path "%s"', $path, $filepath));
        }
        $this->ebookLoader->loadFromPath($filepath);
        $coverItem = $this->ebookLoader->getCoverItem();
        if (!$coverItem) {
            throw new NotLoadableException(sprintf('Cover image not resolvable, book with id "%s" has an invalid cover', $path));
        }
        return new Binary(
            $this->ebookLoader->getEbook()->getArchive()->getContents($coverItem),
            $this->mimeTypes[$coverItem->getExtension()]??"image/jpeg",
            $coverItem->getExtension()
        );
    }

}