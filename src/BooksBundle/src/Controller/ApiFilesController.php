<?php

namespace App\BooksBundle\Controller;

use App\CoreBundle\Controller\FileManagerTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_BOOKS', null, 'Access Denied.')]
#[Route('/api/fileManager', name: 'api_fileManager_', format: 'json')]
class ApiFilesController extends AbstractController
{
    use FileManagerTrait;

    public function __construct(
        #[Autowire('%books.base_folder%')]
        private readonly string $baseFolder)
    {
    }

}
