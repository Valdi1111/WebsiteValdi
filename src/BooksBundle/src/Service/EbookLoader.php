<?php

namespace App\BooksBundle\Service;

use App\BooksBundle\Entity\Book;
use Kiwilan\Archive\Models\ArchiveItem;
use Kiwilan\Ebook\Ebook;
use Kiwilan\Ebook\EbookCover;

class EbookLoader
{
    private ?Ebook $ebook;

    public function getEbook(): ?Ebook
    {
        return $this->ebook;
    }

    public function load(Book $book): void
    {
        $this->loadFromPath($book->getLibrary()->getBasePath() . $book->getUrl());
    }

    public function loadFromPath(string $filepath): void
    {
        $this->ebook = Ebook::read($filepath);
    }

    public function hasCover(): bool
    {
        return $this->getCoverItem() != null;
    }

    public function getCover(): ?EbookCover
    {
        $coverItem = $this->getCoverItem();
        if (!$coverItem) {
            return null;
        }
        return EbookCover::make(
            $coverItem->getPath(),
            $this->ebook->getArchive()->getContents($coverItem)
        );
    }

    public function getCoverItem(): ?ArchiveItem
    {
        $coverHref = $this->getCoverHref();
        if (empty($coverHref)) {
            return null;
        }
        $rootPath = $this->getRootPath();
        if (!empty($rootPath)) {
            $coverHref = "$rootPath/$coverHref";
        }
        foreach ($this->ebook->getArchive()->getFileItems() as $fileItem) {
            if ($fileItem->isImage() && str_ends_with($fileItem->getPath(), $coverHref)) {
                return $fileItem;
            }
        }
        return null;
    }

    public function getRootPath(): string
    {
        $opfPath = $this->ebook->getParser()->getEpub()->getContainer()->getOpfPath();
        return substr($opfPath, 0, strrpos($opfPath, "/"));
    }

    public function getCoverHref(): ?string
    {
        $opf = $this->ebook->getParser()->getEpub()->getOpf();
        $cover = $opf->getMetaItem('cover')?->getContents();
        foreach ($opf->getManifest()['item'] as $item) {
            if ((isset($item['@attributes']['properties']) && str_starts_with($item['@attributes']['properties'], 'cover-image')) ||
                $item['@attributes']['id'] === $cover) {
                return $item['@attributes']['href'];
            }
        }
        return null;
    }

}