<?php

namespace App\VideosBundle\Controller;

use App\CoreBundle\Controller\OldFileManagerTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_VIDEOS', null, 'Access Denied.')]
#[Route('/api/files', name: 'api_files_', format: 'json')]
class ApiFilesController extends AbstractController
{
    use OldFileManagerTrait;

    public function __construct(
        #[Autowire('%videos.base_folder%')]
        private readonly string $baseFolder)
    {
    }

}
