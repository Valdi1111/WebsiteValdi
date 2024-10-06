<?php

namespace App\CoreBundle\Controller;

use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

trait FileManagerTrait
{

    /** @var string[] */
    const array EXTENSION_MAP = [
        'zip' => 'archive',
        'rar' => 'archive',
        '7z' => 'archive',
        'tar' => 'archive',
        'gz' => 'archive',

        'mp3' => 'audio',
        'ogg' => 'audio',
        'flac' => 'audio',
        'wav' => 'audio',

        'html' => 'code',
        'htm' => 'code',
        'js' => 'code',
        'json' => 'code',
        'css' => 'code',
        'scss' => 'code',
        'sass' => 'code',
        'less' => 'code',
        'php' => 'code',
        'sh' => 'code',
        'coffee' => 'code',
        'txt' => 'code',
        'md' => 'code',
        'go' => 'code',
        'yml' => 'code',

        'docx' => 'document',
        'doc' => 'document',
        'xlsx' => 'document',
        'xls' => 'document',
        'pptx' => 'document',
        'ppt' => 'document',
        'pdf' => 'document',
        'djvu' => 'document',
        'djv' => 'document',

        'mpg' => 'video',
        'mp4' => 'video',
        'avi' => 'video',
        'mkv' => 'video',
        'ogv' => 'video',
        'mov' => 'video',

        'png' => 'image',
        'jpg' => 'image',
        'jpeg' => 'image',
        'webp' => 'image',
        'gif' => 'image',
        'tiff' => 'image',
        'tif' => 'image',
        'svg' => 'image',
    ];

    private function getFolderSerialize(string $path, SplFileInfo $folder): array
    {
        return [
            'value' => $folder->getFilename(),
            'id' => $path . $folder->getFilename() . "/",
            'size' => 0,
            'date' => $folder->getCTime(),
            'type' => 'folder',
            'data' => $this->getFoldersRecursive($path . $folder->getFilename() . "/"),
        ];
    }

    private function getFileSerialize(string $path, SplFileInfo $file): array
    {
        return [
            'value' => $file->getFilename(),
            'id' => $path . $file->getFilename(),
            'size' => $file->getSize(),
            'date' => $file->getMTime(),
            'type' => isset(self::EXTENSION_MAP[$file->getExtension()]) ? self::EXTENSION_MAP[$file->getExtension()] : 'file',
        ];
    }

    private function getFoldersRecursive(string $path): array
    {
        $finder = new Finder();
        $finder->depth('== 0')->directories()->in($this->baseFolder . $path);
        $folders = [];
        foreach ($finder as $folder) {
            $folders[] = $this->getFolderSerialize($path, $folder);
        }
        return $folders;
    }

    #[Route('/folders', name: 'folders', methods: ['GET'])]
    public function fileManagerFolders(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $path = $req->query->getString('id');
        return $this->json($this->getFoldersRecursive($path));
    }

    #[Route('/files', name: 'files', methods: ['GET'])]
    public function fileManagerFiles(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $path = $req->query->getString('id');
        $search = $req->query->getString('search');
        $finder = new Finder();
        $finder->depth('== 0')->files()->in($this->baseFolder . $path);
        if (!empty($search)) {
            $finder->name("/(?i)($search)/");
        }
        $files = [];
        foreach ($finder as $file) {
            $files[] = $this->getFileSerialize($path, $file);
        }
        return $this->json($files);
    }

    #[Route('/info', name: 'info', methods: ['GET'])]
    public function fileManagerInfo(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $free = disk_free_space($this->baseFolder);
        $total = disk_total_space($this->baseFolder);
        return $this->json([
            'stats' => [
                'free' => $free,
                'total' => $total,
                'used' => $total - $free,
            ],
            'features' => [
                'preview' => [
                    'document' => true,
                    'image' => true,
                ],
                'meta' => [
                    'audio' => true,
                    'image' => true,
                    'video' => true,
                ],
            ],
        ]);
    }

    #[Route('/meta', name: 'meta', methods: ['GET'])]
    public function fileManagerMeta(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $path = $req->query->getString('id');
        // TODO send extra info
        $file = new File($this->baseFolder . $path);
        $type = self::EXTENSION_MAP[$file->getExtension()];
        if ($type === 'audio') {

        }
        if ($type === 'image') {
            $size = getimagesize($file->getPathname());
            return $this->json([
                'Width' => strval($size[0]),
                'Height' => strval($size[1]),
                //'Mime' => $size['mime'],
                //'Channels' => strval($size['channels']),
                //'Bits' => strval($size['bits']),
            ]);
        }
        return $this->json([]);
    }

    #[Route('/makedir', name: 'makedir', methods: ['POST'])]
    public function fileManagerMkdir(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $path = $req->request->getString('id');
        $name = $req->request->getString('name');
        $filesystem = new Filesystem();
        if (!$filesystem->exists($this->baseFolder . $path)) {
            return $this->json(['invalid' => true, 'error' => 'Directory not found!']);
        }
        $newPath = $this->baseFolder . $path . $name;
        if ($filesystem->exists($newPath)) {
            return $this->json(['invalid' => true, 'error' => 'Directory already exists!']);
        }
        try {
            $filesystem->mkdir($newPath);
        } catch (\Exception $e) {
            return $this->json(['invalid' => true, 'error' => $e->getMessage()]);
        }
        return $this->json($this->getFolderSerialize($path, new File($newPath, false)));
    }

    #[Route('/direct', name: 'direct', methods: ['GET'])]
    public function fileManagerDirect(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $path = $req->query->getString('id');
        $download = $req->query->getBoolean('download');
        $file = new File($this->baseFolder . $path);
        return $this->file(
            $file,
            $file->getFilename(),
            $download ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE
        );
    }

    #[Route('/preview', name: 'preview', methods: ['GET'])]
    public function fileManagerPreview(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $path = $req->query->getString('id');
        $width = $req->query->getInt('width');
        $height = $req->query->getInt('height');
        $file = new File($this->baseFolder . $path);
        return $this->file($file, $file->getFilename());
    }

    #[Route('/text', name: 'text_get', methods: ['GET'])]
    public function fileManagerTextGet(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $path = $req->query->getString('id');
        $file = new File($this->baseFolder . $path);
        return new Response($file->getContent());
    }

    #[Route('/text', name: 'text_post', methods: ['POST'])]
    public function fileManagerTextPost(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $path = $req->request->getString('id');
        $content = $req->request->get('content');
        $file = new File($this->baseFolder . $path);
        $filesystem = new Filesystem();
        $filesystem->dumpFile($file->getPathname(), $content);
        $relative = '/' . Path::makeRelative($file->getPathname(), $this->baseFolder);
        return $this->json($this->getFileSerialize($relative, $file));
    }

    #[Route('/icons/{skin}/{size}/{type}/{name}', name: 'icons_skin', methods: ['GET'])]
    #[Route('/icons/{size}/{type}/{name}', name: 'icons', methods: ['GET'])]
    public function fileManagerIcons(Request $req, string $size, string $type, string $name): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $file = new File("bundles/core/filemanager/icons/$size/$name", false);
        if ($file->isFile()) {
            return $this->file($file, $file->getFilename());
        }
        $file = new File("bundles/core/filemanager/icons/$size/types/$type.svg", false);
        if ($file->isFile()) {
            return $this->file($file, $file->getFilename());
        }
        return $this->file($file, $file->getFilename());
    }

    #[Route('/rename', name: 'rename', methods: ['POST'])]
    public function fileManagerRename(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $path = $req->request->getString('id');
        $name = $req->request->getString('name');
        $filesystem = new Filesystem();
        if (!$filesystem->exists($this->baseFolder . $path)) {
            return $this->json(['invalid' => true, 'error' => 'File not found!']);
        }
        $folder = Path::getDirectory($this->baseFolder . $path);
        $relative = $filesystem->makePathRelative($folder, $this->baseFolder);
        $newPath = $folder . '/' . $name;
        if ($filesystem->exists($newPath)) {
            return $this->json(['invalid' => true, 'error' => 'File already exists!']);
        }
        try {
            $filesystem->rename($this->baseFolder . $path, $newPath);
        } catch (\Exception $e) {
            return $this->json(['invalid' => true, 'error' => $e->getMessage()]);
        }
        return $this->json(['invalid' => false, 'error' => '', 'id' => '/' . $relative . $name]);
    }

    #[Route('/copy', name: 'copy', methods: ['POST'])]
    public function fileManagerCopy(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $path = $req->request->getString('id');
        $to = $req->request->getString('to');
        $filesystem = new Filesystem();
        if (!$filesystem->exists($this->baseFolder . $path)) {
            return $this->json(['invalid' => true, 'error' => 'File not found!']);
        }
        if (!$filesystem->exists($this->baseFolder . $to)) {
            return $this->json(['invalid' => true, 'error' => 'Directory not found!']);
        }
        $file = new File($this->baseFolder . $path);
        $newPath = $to . $file->getFilename();
        if ($filesystem->exists($this->baseFolder . $newPath)) {
            $newPath = $to . Path::getFilenameWithoutExtension($file->getPathname()) . '1' . '.' . $file->getExtension();
        }
        try {
            $filesystem->copy($this->baseFolder . $path, $this->baseFolder . $newPath);
        } catch (\Exception $e) {
            return $this->json(['invalid' => true, 'error' => $e->getMessage()]);
        }
        $newFile = new File($this->baseFolder . $newPath);
        return $this->json($this->getFileSerialize($to, $newFile));
    }

    #[Route('/move', name: 'move', methods: ['POST'])]
    public function fileManagerMove(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $path = $req->request->getString('id');
        $to = $req->request->getString('to');
        $filesystem = new Filesystem();
        if (!$filesystem->exists($this->baseFolder . $path)) {
            return $this->json(['invalid' => true, 'error' => 'File not found!']);
        }
        if (!$filesystem->exists($this->baseFolder . $to)) {
            return $this->json(['invalid' => true, 'error' => 'Directory not found!']);
        }
        $file = new File($this->baseFolder . $path);
        $newPath = $to . $file->getFilename();
        try {
            $filesystem->rename($this->baseFolder . $path, $this->baseFolder . $newPath);
        } catch (\Exception $e) {
            return $this->json(['invalid' => true, 'error' => $e->getMessage()]);
        }
        $newFile = new File($this->baseFolder . $newPath);
        return $this->json($this->getFileSerialize($to, $newFile));
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function fileManagerDelete(Request $req): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $path = $req->request->getString('id');
        $filesystem = new Filesystem();
        if (!$filesystem->exists($this->baseFolder . $path)) {
            return $this->json(['invalid' => true, 'error' => 'File not found!']);
        }
        try {
            $filesystem->remove($this->baseFolder . $path);
        } catch (\Exception $e) {
            return $this->json(['invalid' => true, 'error' => $e->getMessage()]);
        }
        return $this->json([]);
    }

}