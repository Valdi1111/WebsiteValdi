<?php

namespace App\PerfectworldBundle\Service\Pull;

use App\PerfectworldBundle\Model\Game\GameInterface;
use App\PerfectworldBundle\Model\Pull\PullBackupImporterInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class PullBackupImporterManager
{
    /** @var array<string, PullBackupImporterInterface> */
    private array $importersByKey = [];

    /**
     * @param iterable<PullBackupImporterInterface> $importers
     */
    public function __construct(
        #[AutowireIterator('perfectworld.pull_backup_importer')]
        iterable $importers,
    ) {
        foreach ($importers as $importer) {
            $this->importersByKey[$importer::getKey()] = $importer;
        }
    }

    public function getByKey(string $key): ?PullBackupImporterInterface
    {
        return $this->importersByKey[$key] ?? null;
    }

    /**
     * @param class-string<GameInterface>|GameInterface $gameService
     * @return list<PullBackupImporterInterface>
     */
    public function getForGameService(string|GameInterface $gameService): array
    {
        return array_values(array_filter(
            $this->importersByKey,
            fn (PullBackupImporterInterface $importer): bool => $this->supportsGameService($importer, $gameService),
        ));
    }

    /**
     * @param class-string<GameInterface>|GameInterface $gameService
     */
    public function supportsGameService(PullBackupImporterInterface $importer, string|GameInterface $gameService): bool
    {
        $gameClass = is_object($gameService) ? $gameService::class : $gameService;
        foreach ($importer::getSupportedGameServices() as $supportedClass) {
            if ($gameClass === $supportedClass || is_subclass_of($gameClass, $supportedClass)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Frontend metadata of the importers supporting the given game service.
     * "multiple"/"max_files" switch the shared import modal to multi-file upload.
     *
     * @param class-string<GameInterface>|GameInterface $gameService
     * @return list<array{key: string, label: string, description: string, accepted_extensions: list<string>, accept: string, multiple: bool, max_files: int}>
     */
    public function getMetadataForGameService(string|GameInterface $gameService): array
    {
        return array_map(
            static fn (PullBackupImporterInterface $importer): array => [
                'key' => $importer::getKey(),
                'label' => $importer->getLabel(),
                'description' => $importer->getDescription(),
                'accepted_extensions' => $importer->getAcceptedExtensions(),
                'accept' => implode(',', $importer->getAcceptedExtensions()),
                'multiple' => $importer->getMaxFiles() > 1,
                'max_files' => $importer->getMaxFiles(),
            ],
            $this->getForGameService($gameService),
        );
    }
}
