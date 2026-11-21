<?php

namespace App\PerfectworldBundle\Model\Pull;

use App\PerfectworldBundle\Entity\PerfectworldGameProfile;
use App\PerfectworldBundle\Model\Game\GameInterface;

/**
 * Pull history importer for one third-party export format (tagged via _instanceof in services.yaml).
 */
interface PullBackupImporterInterface
{
    public static function getKey(): string;

    public function getLabel(): string;

    public function getDescription(): string;

    /**
     * Accepted file extensions (e.g. ['.json']).
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
     * Maximum number of files accepted in one upload (1 = single file).
     */
    public function getMaxFiles(): int;

    /**
     * Fast structural validation of one file, run synchronously before queueing.
     *
     * @throws \InvalidArgumentException with a user-facing message when the file is not importable
     */
    public function inspect(string $filePath): PullFileInspection;

    /**
     * Parses, repairs and enriches all records of one file for the target profile.
     *
     * @return list<ImportedPullRecord>
     *
     * @throws \InvalidArgumentException with a user-facing message when the file is not importable
     */
    public function parse(PerfectworldGameProfile $profile, string $filePath): array;
}
