<?php

namespace App\GryphlineBundle\Service\Pull;

use App\GryphlineBundle\Model\Game\GameInterface;
use App\GryphlineBundle\Model\Pull\PullBackupImporterInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class PullBackupImporterManager
{
    /** @var array<string, PullBackupImporterInterface> */
    private array $importersByKey = [];

    /**
     * @param iterable<PullBackupImporterInterface> $importers
     */
    public function __construct(
        #[AutowireIterator('gryphline.pull_backup_importer')]
        iterable $importers,
    ) {
        foreach ($importers as $importer) {
            $this->importersByKey[$importer::getKey()] = $importer;
        }
    }

    /**
     * @return list<PullBackupImporterInterface>
     */
    public function getAll(): array
    {
        return array_values($this->importersByKey);
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
        $gameClass = is_object($gameService) ? $gameService::class : $gameService;
        $matching = [];

        foreach ($this->importersByKey as $importer) {
            foreach ($importer::getSupportedGameServices() as $supportedClass) {
                if ($gameClass === $supportedClass || is_subclass_of($gameClass, $supportedClass)) {
                    $matching[] = $importer;
                    break;
                }
            }
        }

        return $matching;
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
     * Returns frontend-ready metadata for all importers supporting the given game service.
     *
     * @param class-string<GameInterface>|GameInterface $gameService
     * @return list<array{key: string, label: string, description: string, accepted_extensions: list<string>, accept: string}>
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
            ],
            $this->getForGameService($gameService)
        );
    }
}
