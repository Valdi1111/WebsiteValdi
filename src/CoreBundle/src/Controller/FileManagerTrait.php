<?php

namespace App\CoreBundle\Controller;

use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @mixin AbstractController
 */
trait FileManagerTrait
{
    private ?Filesystem $filesystem = null;

    public static function getFileType(string $extension): string
    {
        return match ($extension) {
            'zip', 'rar', 'tar', '7z', 'gz' => 'archive',
            'mp3', 'ogg', 'flac', 'wav' => 'audio',
            'html', 'htm', 'js', 'json', 'css', 'scss', 'sass', 'less', 'php', 'sh', 'coffee', 'txt', 'md', 'go', 'yml' => 'code',
            'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'pdf', 'djvu', 'djv' => 'document',
            'mpg', 'mp4', 'avi', 'mkv', 'ogv', 'mov' => 'video',
            'png', 'jpg', 'jpeg', 'webp', 'gif', 'tiff', 'tif', 'svg' => 'image',
            default => 'file',
        };
    }

    public abstract function getBaseFolder(): string;

    public function getFilesystem(): Filesystem
    {
        if (!$this->filesystem) {
            $adapter = new LocalFilesystemAdapter($this->getBaseFolder());
            $this->filesystem = new Filesystem($adapter, [
                Config::OPTION_DIRECTORY_VISIBILITY => 'public',
                Config::OPTION_VISIBILITY => 'public',
            ]);
        }
        return $this->filesystem;
    }

    private function checkFolderOrException(string $path, bool $exists = true, $message = null): void
    {
        if ($exists && !$this->getFilesystem()->directoryExists($path)) {
            throw new ConflictHttpException($message ?? 'Folder not found!');
        }
        if (!$exists && $this->getFilesystem()->directoryExists($path)) {
            throw new ConflictHttpException($message ?? 'Folder already exists!');
        }
    }

    private function checkFileOrException(string $path, bool $exists = true, $message = null): void
    {
        if ($exists && !$this->getFilesystem()->fileExists($path)) {
            throw new ConflictHttpException($message ?? 'File not found!');
        }
        if (!$exists && $this->getFilesystem()->fileExists($path)) {
            throw new ConflictHttpException($message ?? 'File already exists!');
        }
    }

    public function jsonFolder(string $relativeFolder, SplFileInfo|array $folder, bool $response = true): JsonResponse|array
    {
        if (is_array($folder)) {
            $folder = new File(Path::join($folder[0], $folder[1]), false);
        }
        $json = [
            'id' => Path::join($relativeFolder, $folder->getFilename()) . "/",
            'key' => Path::join($relativeFolder, $folder->getFilename()),
            'title' => $folder->getFilename(),
            'date' => $folder->getCTime(),
            'type' => 'folder',
        ];
        if (!$response) {
            return $json;
        }
        return $this->json($json);
    }

    public function jsonFile(string $relativeFolder, SplFileInfo|array $file, bool $response = true): JsonResponse|array
    {
        if (is_array($file)) {
            $file = new File(Path::join($file[0], $file[1]), false);
        }
        $json = [
            'id' => Path::join($relativeFolder, $file->getFilename()),
            'key' => Path::join($relativeFolder, $file->getFilename()),
            'title' => $file->getFilename(),
            'size' => $file->getSize(),
            'date' => $file->getMTime(),
            'type' => self::getFileType($file->getExtension()),
            'extension' => $file->getExtension(),
        ];
        if (!$response) {
            return $json;
        }
        return $this->json($json);
    }

    private function finder(string $relativePath): Finder
    {
        return new Finder()
            ->depth('== 0')
            ->in(Path::join($this->getBaseFolder(), $relativePath))
            ->sortByCaseInsensitiveName();
    }

    private function listDirectories(string $path, int $depth = -1, bool $ignoreLastLevelLeaves = false): array
    {
        $finder = $this->finder($path)->directories();
        $folders = [];
        foreach ($finder as $folder) {
            $serialized = $this->jsonFolder($path, $folder, false);
            $childPath = Path::join($path, $folder->getFilename());
            if ($depth === -1 || $depth > 0) {
                $serialized['children'] = $this->listDirectories($childPath, $depth === -1 ? -1 : $depth - 1, $ignoreLastLevelLeaves);
                $serialized['isLeaf'] = empty($serialized['children']);
            } else {
                $serialized['children'] = [];
                $serialized['isLeaf'] = $ignoreLastLevelLeaves || !$this->finder($childPath)->directories()->hasResults();
            }
            $folders[] = $serialized;
        }
        return $folders;
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/folders', name: 'folders', methods: ['GET'])]
    public function fmFolders(Request $req): Response
    {
        $folders = $this->listDirectories(
            $req->query->getString('id', '/'),
            $req->query->getInt('depth', -1),
            $req->query->getBoolean('ignoreLastLevelLeaves')
        );
        return $this->json($folders);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/files', name: 'files', methods: ['GET'])]
    public function fmFiles(Request $req): Response
    {
        $path = $req->query->getString('id');
        $search = $req->query->getString('search');
        $json = [];
        $filesFinder = $this->finder($path)->files();
        if (empty($search)) {
            $foldersFinder = $this->finder($path)->directories();
            foreach ($foldersFinder as $f) {
                $json[] = $this->jsonFolder($path, $f, false);
            }
        } else {
            $filesFinder->name("/(?i)($search)/");
        }
        foreach ($filesFinder as $f) {
            $json[] = $this->jsonFile($path, $f, false);
        }
        return $this->json($json);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/info', name: 'info', methods: ['GET'])]
    public function fmInfo(Request $req): Response
    {
        $free = disk_free_space($this->getBaseFolder());
        $total = disk_total_space($this->getBaseFolder());
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

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/meta', name: 'meta', methods: ['GET'])]
    public function fmMeta(Request $req): Response
    {
        $path = $req->query->getString('id');
        $this->checkFileOrException($path);
        // TODO send extra info
        $file = new File(Path::join($this->getBaseFolder(), $path));
        $type = self::getFileType($file->getExtension());
        $json = [];
        if ($type === 'audio') {

        }
        if ($type === 'image') {
            $size = getimagesize($file->getPathname());
            $json[] = ["label" => 'Width', "value" => $size[0] . " px"];
            $json[] = ["label" => 'Height', "value" => $size[1] . " px"];
        }
        if ($type === 'video') {
            // exiftool -overwrite_original -Microsoft:Category="Tag1" -Microsoft:Category="Tag2" "NOMEFILE"
            $command = "exiftool -j -G " . escapeshellarg($file->getPathname());
            $metadata = json_decode(shell_exec($command), true)[0];

            $props = [
                "QuickTime:Title" => "Title",
                "QuickTime:Subtitle" => "Subtitle",
                "QuickTime:Category" => "Tags",
                "QuickTime:Comment" => "Comment",

                "QuickTime:Duration" => "Duration",
                "Composite:ImageSize" => "Resolution",

                "QuickTime:Artist" => "Artist",
                "QuickTime:ContentCreateDate" => "Content create date",
                "QuickTime:Genre" => "Genre",

                "QuickTime:Director" => "Director",
                "QuickTime:Producer" => "Producer",
                "QuickTime:Writer" => "Writer",
                "QuickTime:Publisher" => "Publisher",

                "QuickTime:ParentalRating" => "Parental rating",
            ];
            foreach ($props as $prop => $label) {
                if (isset($metadata[$prop])) {
                    $json[] = [
                        "label" => $label,
                        "value" => $metadata[$prop],
                    ];
                }
            }
        }
        return $this->json($json);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/make-folder', name: 'make_folder', methods: ['POST'])]
    public function fmMakeFolder(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $name = $req->getPayload()->getString('name');
        $newPath = Path::join($path, $name);
        $this->checkFolderOrException($newPath, false);
        try {
            $this->getFilesystem()->createDirectory($newPath);
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error creating folder", $e);
        }
        return $this->jsonFolder($path, [$this->getBaseFolder(), $newPath]);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/make-file', name: 'make_file', methods: ['POST'])]
    public function fmMakeFile(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $name = $req->getPayload()->getString('name');
        $newPath = Path::join($path, $name);
        $this->checkFileOrException($newPath, false);
        try {
            $this->getFilesystem()->write($newPath, "");
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error creating file", $e);
        }
        return $this->jsonFile($path, [$this->getBaseFolder(), $newPath]);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/direct', name: 'direct', methods: ['GET'])]
    public function fmDirect(Request $req): Response
    {
        $path = $req->query->getString('id');
        $this->checkFileOrException($path);
        $download = $req->query->getBoolean('download');
        return $this->file(
            new File(Path::join($this->getBaseFolder(), $path)),
            disposition: $download ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE
        );
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/preview', name: 'preview', methods: ['GET'])]
    public function fmPreview(Request $req): Response
    {
        $path = $req->query->getString('id');
        $this->checkFileOrException($path);
        $width = $req->query->getInt('width');
        $height = $req->query->getInt('height');
        return $this->file(
            new File(Path::join($this->getBaseFolder(), $path)),
            disposition: ResponseHeaderBag::DISPOSITION_INLINE
        );
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/text', name: 'text_get', methods: ['GET'])]
    public function fmTextGet(Request $req): Response
    {
        $path = $req->query->getString('id');
        $this->checkFileOrException($path);
        try {
            $content = $this->getFilesystem()->read($path);
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error reading file", $e);
        }
        return new Response($content);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/text', name: 'text_post', methods: ['POST'])]
    public function fmTextPost(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $content = $req->getPayload()->get('content');
        $this->checkFileOrException($path);
        try {
            $this->getFilesystem()->write($path, $content);
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error writing file", $e);
        }
        return $this->jsonFile(Path::getDirectory($path), [$this->getBaseFolder(), $path]);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/icons/{skin}/{size}/{type}/{name}', name: 'icons_skin', methods: ['GET'])]
    #[Route('/icons/{size}/{type}/{name}', name: 'icons', methods: ['GET'])]
    public function fmIcons(Request $req, string $size, string $type, string $name): Response
    {
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

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/rename-folder', name: 'rename_folder', methods: ['POST'])]
    public function fmRenameFolder(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $name = $req->getPayload()->getString('name');
        $this->checkFolderOrException($path);
        $folder = Path::getDirectory($path);
        $newPath = Path::join($folder, $name);
        $file = new File(Path::join($this->getBaseFolder(), $path), false);
        if ($file->getFilename() !== $name) {
            $this->checkFolderOrException($newPath, false);
            try {
                $this->getFilesystem()->move($path, $newPath);
            } catch (FilesystemException $e) {
                throw new HttpException(500, "Error renaming file", $e);
            }
        }
        return $this->jsonFolder($folder, [$this->getBaseFolder(), $newPath]);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/rename-file', name: 'rename_file', methods: ['POST'])]
    public function fmRenameFile(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $name = $req->getPayload()->getString('name');
        $this->checkFileOrException($path);
        $folder = Path::getDirectory($path);
        $newPath = Path::join($folder, $name);
        $file = new File(Path::join($this->getBaseFolder(), $path));
        if ($file->getFilename() !== $name) {
            $this->checkFileOrException($newPath, false);
            try {
                $this->getFilesystem()->move($path, $newPath);
            } catch (FilesystemException $e) {
                throw new HttpException(500, "Error renaming file", $e);
            }
        }
        return $this->jsonFile($folder, [$this->getBaseFolder(), $newPath]);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/copy', name: 'copy', methods: ['POST'])]
    public function fmCopy(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $to = $req->getPayload()->getString('to');
        $this->checkFileOrException($path);
        $this->checkFolderOrException($to, message: 'Destination folder not found!');
        $file = new File(Path::join($this->getBaseFolder(), $path));
        $newPath = Path::join($to, $file->getFilename());
        $i = 1;
        $extension = Path::getExtension($file->getPathname());
        if ($extension) {
            $extension = "." . $extension;
        }
        while ($this->getFilesystem()->fileExists($newPath)) {
            $newPath = Path::join($to, Path::getFilenameWithoutExtension($file->getPathname()) . " ($i)" . $extension);
            $i++;
        }
        try {
            $this->getFilesystem()->copy($path, $newPath);
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error copying file", $e);
        }
        return $this->jsonFile($to, [$this->getBaseFolder(), $newPath]);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/move', name: 'move', methods: ['POST'])]
    public function fmMove(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $to = $req->getPayload()->getString('to');
        $this->checkFileOrException($path);
        $this->checkFolderOrException($to, message: 'Destination folder not found!');
        $file = new File(Path::join($this->getBaseFolder(), $path));
        $newPath = Path::join($to, $file->getFilename());
        if ($path && $newPath) {
            return $this->jsonFile($to, [$this->getBaseFolder(), $newPath]);
        }
        $this->checkFileOrException($newPath, false);
        try {
            $this->getFilesystem()->move($path, $newPath);
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error moving file", $e);
        }
        return $this->jsonFile($to, [$this->getBaseFolder(), $newPath]);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/delete-folder', name: 'delete_folder', methods: ['POST'])]
    public function fmDeleteFolder(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $this->checkFolderOrException($path);
        try {
            $this->getFilesystem()->deleteDirectory($path);
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error deleting folder", $e);
        }
        return $this->json([]);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/delete-file', name: 'delete_file', methods: ['POST'])]
    public function fmDeleteFile(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $this->checkFileOrException($path);
        try {
            $this->getFilesystem()->delete($path);
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error deleting file", $e);
        }
        return $this->json([]);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function fmUpload(Request $req): Response
    {
        $path = $req->query->getString('id');
        /** @var UploadedFile $upload */
        $upload = $req->files->get('file');
        $originalPath = $req->getPayload()->getString('original_path');
        $newPath = Path::join($path, $originalPath);
        $this->checkFileOrException($newPath, false);
        try {
            $this->getFilesystem()->write($newPath, $upload->getContent());
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error uploading file", $e);
        }
        return $this->json([]);
    }

}