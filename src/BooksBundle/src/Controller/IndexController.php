<?php

namespace App\BooksBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Discovery;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER_BOOKS', null, 'Access Denied.')]
class IndexController extends AbstractController
{

    #[Route('/manifest', name: 'manifest', methods: ['GET'])]
    public function manifest(Request $request): Response
    {
        return $this->json([
            "name" => "Books Valdi",
            "short_name" => "Books",
            "description" => "Read light novels online",
            "categories" => [
                "books"
            ],
            "lang" => "en",
            "background_color" => "#2c2c2c",
            "theme_color" => "#2c2c2c",
            "start_url" => "/",
            "scope" => "/",
            "id" => "/",
            "display" => "standalone",
            "display_override" => [
                //"window-controls-overlay",
                "minimal-ui",
            ],
            "prefer_related_applications" => false,
            "protocol_handlers" => [
                [
                    "protocol" => "web+booksvaldi",
                    "url" => "/%s",
                ]
            ],
            "related_applications" => [],
            "dir" => "ltr",
            "edge_side_panel" => [
                "preferred_width" => 400,
            ],
            "orientation" => "portrait-primary",
            "icons" => [
                [
                    "src" => "/bundles/books/icon.svg",
                    "type" => "image/svg+xml",
                    "sizes" => "48x48 72x72 96x96 128x128 256x256 512x512",
                    "purpose" => "any"
                ],
                [
                    "src" => "/bundles/books/icon.svg",
                    "type" => "image/svg+xml",
                    "sizes" => "48x48 72x72 96x96 128x128 256x256 512x512",
                    "purpose" => "maskable"
                ],
            ],
            "shortcuts" => [
                [
                    "name" => "All books",
                    "short_name" => "All books",
                    "description" => "All books",
                    "url" => "/library/all",
                    "icons" => [
                        [
                            "src" => "/bundles/books/icon.svg",
                            "type" => "image/svg+xml",
                            "sizes" => "48x48 72x72 96x96 128x128 256x256 512x512",
                            "purpose" => "any"
                        ]
                    ]
                ],
                [
                    "name" => "Shelves",
                    "short_name" => "Shelves",
                    "description" => "Shelves",
                    "url" => "/library/shelves",
                    "icons" => [
                        [
                            "src" => "/bundles/books/icon.svg",
                            "type" => "image/svg+xml",
                            "sizes" => "48x48 72x72 96x96 128x128 256x256 512x512",
                            "purpose" => "any"
                        ]
                    ]
                ],
                [
                    "name" => "Not in shelves",
                    "short_name" => "Not in shelves",
                    "description" => "Not in shelves",
                    "url" => "/library/not-in-shelves",
                    "icons" => [
                        [
                            "src" => "/bundles/books/icon.svg",
                            "type" => "image/svg+xml",
                            "sizes" => "48x48 72x72 96x96 128x128 256x256 512x512",
                            "purpose" => "any"
                        ]
                    ]
                ],
            ]
        ]);
    }

    #[Route('/{path}', name: 'index', requirements: ['path' => '^(?!api/).*'], methods: ['GET'], priority: -10)]
    public function index(Request $request, HubInterface $hub, Discovery $discovery): Response
    {
        $discovery->addLink($request);
        return $this->render('@Books/index.html.twig', [
            'mercure_hub_url' => $hub->getPublicUrl(),
        ]);
    }

}
