<?php

namespace App\HoyoverseBundle\Controller;

use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasPowershellPullImportInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

class PublicPowershellScriptController extends AbstractController
{
    private const string SCRIPT_TEMPLATE_PATH = __DIR__ . '/../../resources/scripts/extract_pulls.ps1';

    /**
     * @param ServiceLocator<GameInterface> $locatorByGameId
     */
    public function __construct(
        #[AutowireLocator(services: 'hoyoverse.game.id', indexAttribute: 'key')]
        private readonly ServiceLocator $locatorByGameId,
    ) {
    }

    #[Route('/pulls/script/powershell', name: 'public_pulls_script_powershell', methods: ['GET'])]
    public function getPowershellScript(Request $request): Response
    {
        $gameId = $request->query->getInt('gameId');
        if (!$this->locatorByGameId->has($gameId)) {
            throw new BadRequestHttpException('Unsupported game service.');
        }

        $gameService = $this->locatorByGameId->get($gameId);
        if (!$gameService instanceof HasPowershellPullImportInterface) {
            throw new BadRequestHttpException('This game does not support PowerShell pull import.');
        }

        $template = file_get_contents(self::SCRIPT_TEMPLATE_PATH);
        if ($template === false) {
            throw new \RuntimeException('PowerShell script template not found.');
        }

        $config = $gameService->getPowershellPullConfig();

        $psFolders = implode(', ', array_map(
            static fn (string $f): string => "'" . str_replace("'", "''", $f) . "'",
            $config->getLocalLowFolders()
        ));
        $psLogFiles = implode(', ', array_map(
            static fn (string $f): string => "'" . str_replace("'", "''", $f) . "'",
            $config->getLogFiles()
        ));
        $psTitle = str_replace("'", "''", $gameService->getGameName());
        $psUrlRegex = str_replace("'", "''", $config->getUrlRegex());
        $gachaConfig = $gameService->getGachaConfig();
        $psGachaEndpointUrl = str_replace("'", "''", $gachaConfig->getDefaultEndpointUrl());
        $firstBannerType = array_key_first($gachaConfig->getBannerTypes());
        $psTestGachaType = $firstBannerType !== null ? str_replace("'", "''", (string) $firstBannerType) : '';

        $script = strtr($template, [
            '__TITLE__' => $psTitle,
            '__FOLDERS__' => $psFolders,
            '__LOG_FILES__' => $psLogFiles,
            '__DATA_FOLDER_PATTERN__' => $config->getDataFolderPattern(),
            '__URL_REGEX__' => $psUrlRegex,
            '__GACHA_ENDPOINT_URL__' => $psGachaEndpointUrl,
            '__TEST_GACHA_TYPE__' => $psTestGachaType,
        ]);

        return new Response($script, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
