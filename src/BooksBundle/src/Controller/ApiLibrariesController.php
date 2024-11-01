<?php

namespace App\BooksBundle\Controller;

use App\BooksBundle\Entity\Library;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_BOOKS', null, 'Access Denied.')]
#[Route('/api', name: 'api_', format: 'json')]
class ApiLibrariesController extends AbstractController
{

    public function __construct()
    {
    }

    #[Route('/libraries', name: 'libraries', methods: ['GET'])]
    public function apiLibrariesAll(#[MapEntity(class: Library::class, expr: 'repository.findBy({}, {"name": "ASC"})')] iterable $libraries): Response
    {
        return $this->json($libraries);
    }

}
