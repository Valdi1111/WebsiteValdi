<?php

namespace App\BooksBundle\Controller;

use App\BooksBundle\Entity\Library;
use App\CoreBundle\Attribute\ResolveEntityFromRoute;
use App\CoreBundle\Controller\FileManagerTrait;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[ResolveEntityFromRoute(setter: 'setLibrary')]
#[IsGranted('ROLE_USER_BOOKS', null, 'Access Denied.')]
#[Route('/api/libraries/{library}/files', name: 'api_libraries_id_files_', requirements: ['library' => '\d+'], format: 'json')]
class ApiFilesController extends AbstractController
{
    use FileManagerTrait;

    private Library $library;

    public function __construct(
    )
    {
    }

    protected function getLibrary(): Library
    {
        return $this->library;
    }

    public function setLibrary(#[MapEntity(id: 'library', message: "Library not found.")] Library $library): void
    {
        $this->library = $library;
    }

    public function getBaseFolder(): string
    {
        return $this->library->getBasePath();
    }

}
