<?php

namespace App\VideosBundle\Controller;

use App\CoreBundle\Controller\FileManagerTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_VIDEOS')]
#[Route('/api', name: 'api_', format: 'json')]
class VideosApiController extends AbstractController
{
    use FileManagerTrait;

    const string FILE_MANAGER_PATH = '/fileManager';

    public function __construct(
        #[Autowire('%videos.base_folder%')]
        private readonly string $baseFolder)
    {
    }

}
