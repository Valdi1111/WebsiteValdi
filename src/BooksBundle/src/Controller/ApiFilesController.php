<?php

namespace App\BooksBundle\Controller;

use App\BooksBundle\Repository\LibraryRepository;
use App\CoreBundle\Controller\FileManagerTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_BOOKS', null, 'Access Denied.')]
#[Route('/api/libraries/{library}/files', name: 'api_libraries_id_files_', requirements: ['library' => '\d+'], format: 'json')]
class ApiFilesController extends AbstractController
{
    use FileManagerTrait;

    private string $baseFolder;

    public function __construct(
        private readonly LibraryRepository $libraryRepo,
        private readonly RequestStack      $requestStack)
    {
        $req = $this->requestStack->getCurrentRequest();
        $library = $this->libraryRepo->find($req->attributes->getInt('library'));
        if (!$library) {
            throw $this->createNotFoundException("Library not found.");
        }
        $this->baseFolder = $library->getBasePath();
    }

    public function getBaseFolder(): string
    {
        return $this->baseFolder;
    }

}
