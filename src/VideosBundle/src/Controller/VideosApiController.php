<?php

namespace App\VideosBundle\Controller;

use App\CoreBundle\Controller\FileManagerTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
class VideosApiController extends AbstractController
{
    use FileManagerTrait;

    const FILE_MANAGER_PATH = '/fileManager';

    public function __construct(#[Autowire('%videos.base_folder%')] private readonly string $baseFolder)
    {
    }

    #[Route('/videos/{path}', name: 'videos', requirements: ['path' => '.*'], methods: ['GET'])]
    public function apiVideos(Request $req, string $path): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $filepath = $this->baseFolder . '/' . $path;
        if (!file_exists($filepath)) {
            return $this->json(['error' => true, 'message' => "Video file not found."], 400);
        }
        return $this->file(new File($filepath), 'video.mp4');
    }

    private function searchFiles(string $baseFolder, string $path = ""): ?array
    {
        $fullPath = $baseFolder . '/' . $path;
        if (!is_dir($fullPath)) {
            return null;
        }
        $list = [];
        foreach (scandir($fullPath) as $item) {
            $file = $path . '/' . $item;
            if ($item === '.' || $item === '..') {
                continue;
            }
            $list[] = [
                'type' => is_dir($baseFolder . '/' . $file) ? 'folder' : 'file',
                'path' => $file,
                'name' => $item,
            ];
            //if (pathinfo($file, PATHINFO_EXTENSION) === 'epub') {
            //    $all[] = $path;
            //}
        }
        return $list;
    }

    #[Route('/files/{path}', name: 'files', requirements: ['path' => '.*'], defaults: ['path' => ''], methods: ['GET'])]
    public function apiFiles(Request $req, string $path): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $path = $path ? '/' . $path : "";
        $files = $this->searchFiles($this->baseFolder, $path);
        if (!$files) {
            return $this->json(['error' => true, 'message' => "This is not a folder."], 400);
        }
        return $this->json($files);
    }

}
