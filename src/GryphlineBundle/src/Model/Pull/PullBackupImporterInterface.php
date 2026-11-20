<?php

namespace App\GryphlineBundle\Model\Pull;

use App\GamesBundle\Model\Pull\PullBackupValidationResult;
use App\GryphlineBundle\Entity\GryphlineGameProfile;
use App\GryphlineBundle\Model\Game\GameInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('gryphline.pull_backup_importer')]
interface PullBackupImporterInterface
{
    public static function getKey(): string;

    public function getLabel(): string;

    public function getDescription(): string;

    /**
     * Accepted file extensions (e.g. ['.json'], ['.csv']).
     *
     * @return list<string>
     */
    public function getAcceptedExtensions(): array;

    /**
     * Game services this importer applies to.
     *
     * @return list<class-string<GameInterface>>
     */
    public static function getSupportedGameServices(): array;

    /**
     * Fast synchronous structural validation before queueing/importing.
     */
    public function validate(string $filePath): PullBackupValidationResult;

    /**
     * Parses and enriches all pull records from the backup file for the target profile.
     *
     * @return list<ImportedPullRecord>
     */
    public function parse(GryphlineGameProfile $profile, string $filePath): array;
}
