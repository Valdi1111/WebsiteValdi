<?php

namespace App\HoyoverseBundle\Service\Pull;

use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Pull\PullBackupImporterInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class PullBackupImporterManager
{
    /** @var array<string, PullBackupImporterInterface> */
    private array $importersByKey = [];

    /**
     * @param iterable<PullBackupImporterInterface> $importers
     */
    public function __construct(
        #[AutowireIterator('hoyoverse.pull_backup_importer')]
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
     * @return list<PullBackupImporterInterface>
     */
    public function getAllImporters(): array
    {
        return array_values($this->importersByKey);
    }

    /**
     * @param GameInterface|class-string<GameInterface> $gameService
     * @return list<PullBackupImporterInterface>
     */
    public function getForGameService(GameInterface|string $gameService): array
    {
        $matched = [];
        foreach ($this->importersByKey as $importer) {
            if ($this->supportsGameService($importer, $gameService)) {
                $matched[] = $importer;
            }
        }

        return $matched;
    }

    /**
     * @param GameInterface|class-string<GameInterface> $gameService
     */
    public function supportsGameService(PullBackupImporterInterface $importer, GameInterface|string $gameService): bool
    {
        $serviceClass = is_object($gameService) ? $gameService::class : $gameService;

        foreach ($importer::getSupportedGameServices() as $supportedClass) {
            if (is_a($serviceClass, $supportedClass, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param GameInterface|class-string<GameInterface> $gameService
     * @return list<array{key: string, label: string, description: string, accept: string, extensions: list<string>}>
     */
    public function getMetadataForGameService(GameInterface|string $gameService): array
    {
        $result = [];
        foreach ($this->getForGameService($gameService) as $importer) {
            $extensions = $importer->getAcceptedExtensions();
            $result[] = [
                'key' => $importer::getKey(),
                'label' => $importer->getLabel(),
                'description' => $importer->getDescription(),
                'accept' => implode(',', $extensions),
                'extensions' => $extensions,
            ];
        }

        return $result;
    }
}
