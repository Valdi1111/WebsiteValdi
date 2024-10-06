<?php

namespace App\BooksBundle\Controller;

use App\BooksBundle\Repository\LibraryRepository;
use App\CoreBundle\Controller\FileManagerTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_BOOKS', null, 'Access Denied.')]
#[Route('/api/libraries/{libraryId}/files', name: 'api_libraries_id_files_', requirements: ['libraryId' => '\d+'], format: 'json')]
class ApiFilesController extends AbstractController
{
    use FileManagerTrait;

    private string $baseFolder;

    public function __construct(
        private readonly LibraryRepository $libraryRepo,
        private readonly RequestStack      $requestStack)
    {
        $req = $this->requestStack->getCurrentRequest();
        $library = $this->libraryRepo->findOneBy(['id' => $req->attributes->getInt('libraryId')]);
        if (!$library) {
            throw new NotFoundHttpException("Library not found.");
        }
        $this->baseFolder = $library->getBasePath();
    }

}
