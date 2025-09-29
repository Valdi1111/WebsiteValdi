<?php

namespace App\CoreBundle\Controller;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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

    /** @var array<string, string> */
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

    public abstract function getBaseFolder(): string;

    public function getFilesystem(): Filesystem
    {
        if (!$this->filesystem) {
            $adapter = new LocalFilesystemAdapter($this->getBaseFolder());
            $this->filesystem = new Filesystem($adapter);
        }
        return $this->filesystem;
    }
    
    private function fileOrException(string $path, string $message = 'File not found!'): void
    {
        if ($this->getFilesystem()->fileExists($path)) {
            return;
        }
        throw new BadRequestHttpException($message);
    }

    private function noFileOrException(string $path, string $message = 'File already exists!'): void
    {
        if (!$this->getFilesystem()->fileExists($path)) {
            return;
        }
        throw new ConflictHttpException($message);
    }

    private function folderOrException(string $path, string $message = 'Folder not found!'): void
    {
        if ($this->getFilesystem()->directoryExists($path)) {
            return;
        }
        throw new BadRequestHttpException($message);
    }

    private function noFolderOrException(string $path, string $message = 'Folder already exists!'): void
    {
        if (!$this->getFilesystem()->directoryExists($path)) {
            return;
        }
        throw new ConflictHttpException($message);
    }

    private function getFolderSerialize(string $path, SplFileInfo $folder): array
    {
        $children = $this->getFoldersRecursive($path . $folder->getFilename() . "/");
        return [
            'key' => $path . $folder->getFilename() . "/",
            'title' => $folder->getFilename(),
            'children' => $children,
            'isLeaf' => empty($children),
            'date' => $folder->getCTime(),
            'type' => 'folder',
        ];
    }

    private function getFileSerialize(string $path, SplFileInfo $file): array
    {
        return [
            'key' => $path . $file->getFilename(),
            'title' => $file->getFilename(),
            'size' => $file->getSize(),
            'date' => $file->getMTime(),
            'type' => self::EXTENSION_MAP[$file->getExtension()] ?? 'file',
        ];
    }

    private function getFoldersRecursive(string $path): array
    {
        $finder = new Finder();
        $finder->depth('== 0')
            ->directories()
            ->in($this->getBaseFolder() . $path)
            ->sortByCaseInsensitiveName();
        $folders = [];
        foreach ($finder as $folder) {
            $folders[] = $this->getFolderSerialize($path, $folder);
        }
        return $folders;
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/folders', name: 'folders', methods: ['GET'])]
    public function fmFolders(Request $req): Response
    {
        $path = $req->query->getString('id');
        return $this->json($this->getFoldersRecursive($path));
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/files', name: 'files', methods: ['GET'])]
    public function fmFiles(Request $req): Response
    {
        $path = $req->query->getString('id');
        $search = $req->query->getString('search');
        $finder = new Finder();
        $finder->depth('== 0')
            ->files()
            ->in($this->getBaseFolder() . $path)
            ->sortByCaseInsensitiveName();
        $files = [];
        if (!empty($search)) {
            $finder->name("/(?i)($search)/");
        }
//        else {
//            $finderFolders = new Finder();
//            $finderFolders->depth('== 0')
//                ->directories()
//                ->in($this->getBaseFolder() . $path)
//                ->sortByCaseInsensitiveName();
//            foreach ($finderFolders as $folder) {
//                $files[] = $this->getFolderSerialize($path, $folder);
//            }
//        }
        foreach ($finder as $file) {
            $files[] = $this->getFileSerialize($path, $file);
        }
        return $this->json($files);
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
        $this->fileOrException($path);
        // TODO send extra info
        $file = new File($this->getBaseFolder() . $path);
        $type = self::EXTENSION_MAP[$file->getExtension()] ?? 'file';
        $json = [];
        if ($type === 'audio') {

        }
        if ($type === 'image') {
            $size = getimagesize($file->getPathname());
            $json['Width'] = strval($size[0]);
            $json['Height'] = strval($size[1]);
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
                    $val = $metadata[$prop];
                    if (is_array($val)) {
                        $val = implode(", ", $val);
                    }
                    $json[$label] = strval($val);
                }
            }
        }
        return $this->json($json);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/make-dir', name: 'make_dir', methods: ['POST'])]
    public function fmMakeDir(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $name = $req->getPayload()->getString('name');
        $newPath = $path . $name;
        $this->noFolderOrException($newPath);
        try {
            $this->getFilesystem()->createDirectory($newPath);
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error creating folder", $e);
        }
        return $this->json($this->getFolderSerialize($path, new File($this->getBaseFolder() . $newPath, false)));
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/make-file', name: 'make_file', methods: ['POST'])]
    public function fmMakeFile(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $name = $req->getPayload()->getString('name');
        $newPath = $path . $name;
        $this->noFileOrException($newPath);
        try {
            $this->getFilesystem()->write($newPath, "");
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error creating file", $e);
        }
        return $this->json($this->getFileSerialize($path, new File($this->getBaseFolder() . $newPath)));
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/direct', name: 'direct', methods: ['GET'])]
    public function fmDirect(Request $req): Response
    {
        $path = $req->query->getString('id');
        $this->fileOrException($path);
        $download = $req->query->getBoolean('download');
        $file = new File($this->getBaseFolder() . $path);
        return $this->file(
            $file,
            $file->getFilename(),
            $download ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE
        );
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/preview', name: 'preview', methods: ['GET'])]
    public function fmPreview(Request $req): Response
    {
        $path = $req->query->getString('id');
        $this->fileOrException($path);
        $width = $req->query->getInt('width');
        $height = $req->query->getInt('height');
        $file = new File($this->getBaseFolder() . $path);
        return $this->file($file, $file->getFilename());
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/text', name: 'text_get', methods: ['GET'])]
    public function fmTextGet(Request $req): Response
    {
        $path = $req->query->getString('id');
        $this->fileOrException($path);
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
        $this->fileOrException($path);
        try {
            $this->getFilesystem()->write($path, $content);
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error writing file", $e);
        }
        $file = new File($this->getBaseFolder() . $path);
        $relative = '/' . Path::makeRelative($file->getPathname(), $this->getBaseFolder());
        return $this->json($this->getFileSerialize($relative, $file));
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
    #[Route('/rename', name: 'rename', methods: ['POST'])]
    public function fmRename(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $name = $req->getPayload()->getString('name');
        $this->fileOrException($path);
        $folder = Path::getDirectory($this->getBaseFolder() . $path);
        $relative = Path::makeRelative($folder, $this->getBaseFolder());
        $newPath = Path::join($relative, $name);
        $file = new File($this->getBaseFolder() . $path);
        if ($file->getFilename() !== $name) {
            $this->noFileOrException($newPath);
            try {
                $this->getFilesystem()->move($path, $newPath);
            } catch (FilesystemException $e) {
                throw new HttpException(500, "Error renaming file", $e);
            }
        }
        return $this->json(['invalid' => false, 'error' => '', 'id' => '/' . $relative . $name]);
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/copy', name: 'copy', methods: ['POST'])]
    public function fmCopy(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $to = $req->getPayload()->getString('to');
        $this->fileOrException($path);
        $this->folderOrException($to, 'Destination folder not found!');
        $file = new File($this->getBaseFolder() . $path);
        $newPath = $to . $file->getFilename();
        if ($this->getFilesystem()->fileExists($newPath)) {
            $newPath = $to . Path::getFilenameWithoutExtension($file->getPathname()) . '1' . '.' . $file->getExtension();
        }
        try {
            $this->getFilesystem()->copy($path, $newPath);
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error copying file", $e);
        }
        $newFile = new File($this->getBaseFolder() . $newPath);
        return $this->json($this->getFileSerialize($to, $newFile));
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/move', name: 'move', methods: ['POST'])]
    public function fmMove(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $to = $req->getPayload()->getString('to');
        $this->fileOrException($path);
        $this->folderOrException($to, 'Destination folder not found!');
        $file = new File($this->getBaseFolder() . $path);
        $newPath = $to . $file->getFilename();
        $this->noFileOrException($newPath);
        try {
            $this->getFilesystem()->move($path, $newPath);
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error moving file", $e);
        }
        $newFile = new File($this->getBaseFolder() . $newPath);
        return $this->json($this->getFileSerialize($to, $newFile));
    }

    #[IsGranted('ROLE_USER', null, 'Access Denied.')]
    #[Route('/delete-dir', name: 'delete_dir', methods: ['POST'])]
    public function fmDeleteDir(Request $req): Response
    {
        $path = $req->getPayload()->getString('id');
        $this->folderOrException($path);
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
        $this->fileOrException($path);
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
        $newPath = $path . $originalPath;
        $this->noFileOrException($newPath);
        try {
            $this->getFilesystem()->write($newPath, $upload->getContent());
        } catch (FilesystemException $e) {
            throw new HttpException(500, "Error uploading file", $e);
        }
        return $this->json([]);
    }

}