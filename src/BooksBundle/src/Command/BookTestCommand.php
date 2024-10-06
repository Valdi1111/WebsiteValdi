<?php

namespace App\BooksBundle\Command;

use App\BooksBundle\Repository\BookRepository;
use App\BooksBundle\Repository\LibraryRepository;
use Kiwilan\Ebook\Ebook;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'books:test', description: 'Book test')]
class BookTestCommand extends Command
{
    private string $baseFolder;

    public function __construct(
        private readonly LibraryRepository $libraryRepo,
        private readonly BookRepository $bookRepo,
        string $name = null)
    {
        $library = $this->libraryRepo->findOneBy(['id' => 1]);
        $this->baseFolder = $library->getBasePath();
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $books = $this->bookRepo
            ->createQueryBuilder('b')
            ->setFirstResult(500)
            //->setMaxResults(100)
            ->getQuery()
            ->getResult();
        foreach ($books as $book) {
            $filepath = $this->baseFolder . $book->getUrl();
            if (!file_exists($filepath)) {
                continue;
            }
            $ebook = Ebook::read($filepath);

            // https://www.w3.org/publishing/epub32/epub-packages.html#sec-package-nav-def

            //$list = $ebook->getParser()->getEpub()->getOpf()->getManifest()['item'];
            //$list = array_column($list, '@attributes');
            //$list = array_filter($list, function($item) {
            //    return isset($item['properties']) && $item['properties'] === 'nav';
            //});

            if(empty($ebook->getParser()->getEpub()->getNcx())) {
                $output->writeln($filepath);
            }
            //$output->writeln("  - " . (empty($list) ? "NON C'E'" : "ok"));
            //$output->writeln("  - " . (empty($ebook->getParser()->getEpub()->getNcx()->getNavPoints()) ? "##### NON C'E'" : "ok"));

            //dump($ebook->getArchive()->extractAll("test/")); // estrae tutti i files
            //dump($ebook->getParser()->getEpub()->getHtml()); // prende gli html col contenuto diviso in head e body
            //dump($ebook->getParser()->getEpub()->getOpf());
            //dump($ebook->getParser()->getEpub()->getCoverPath()); // path cover
            //dump($ebook->getParser()->getEpub()->getFiles());
            //dump($ebook->getParser()->getEpub()->getContainer()->getOpfPath());
            //dump($ebook->getParser()->getEpub()->getNcx());
            //dump($ebook->getParser()->getEpub()->getChapters());

        }
        return Command::SUCCESS;
    }

}