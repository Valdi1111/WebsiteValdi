<?php

namespace App\GryphlineBundle\Model\Game;

use App\GryphlineBundle\Service\SkportUtilsService;
use Psr\Log\LoggerInterface;

interface GameInterface
{
    public static function getGameBiz(): string;

    public static function getGameId(): int;

    public function getGameName(): string;

    public function getIcon(): ?string;

    public function getLogger(): LoggerInterface;

    public function getSkportUtils(): ?SkportUtilsService;
}
