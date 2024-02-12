<?php

namespace App\BooksBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Discovery;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Annotation\Route;

class BooksIndexController extends AbstractController
{

    #[Route('/{path}', name: 'index', requirements: ['path' => '.*'], methods: ['GET'], priority: -10)]
    public function index(Request $request, HubInterface $hub, Discovery $discovery): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $discovery->addLink($request);
        return $this->render('@BooksBundle/index.html.twig', [
            'mercure_hub_url' => $hub->getPublicUrl(),
        ]);
    }

}
