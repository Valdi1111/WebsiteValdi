<?php

namespace App\AnimeBundle\Controller;

use App\CoreBundle\Controller\FileManagerTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_ANIME', null, 'Access Denied.')]
#[Route('/api/files', name: 'api_files_', format: 'json')]
class ApiFilesController extends AbstractController
{
    use FileManagerTrait;

    public function __construct(
        #[Autowire('%anime.base_folder%')]
        private readonly string $baseFolder)
    {
    }

    public function getBaseFolder(): string
    {
        return $this->baseFolder;
    }

}
