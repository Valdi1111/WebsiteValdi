<?php

namespace App\AnimeBundle\Service;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class AnimeStorage extends Filesystem
{

    public function __construct(
        #[Autowire(param: 'anime.base_folder')]
        private readonly string $baseFolder
    )
    {
        $adapter = new LocalFilesystemAdapter($this->baseFolder, new PortableVisibilityConverter(filePublic: 0664, directoryPublic: 02775));
        parent::__construct($adapter);
    }

}
