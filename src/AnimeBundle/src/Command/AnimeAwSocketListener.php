<?php

namespace App\AnimeBundle\Command;

use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use App\AnimeBundle\Exception\UnhandledWebsiteException;
use App\AnimeBundle\Service\AnimeWorldService;
use ElephantIO\Client;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\ErrorHandler\Error\UndefinedMethodError;

#[AsCommand(name: 'anime:aw-socket-listener', description: 'Start anime world socket listener')]
class AnimeAwSocketListener extends Command
{

    public function __construct(private readonly LoggerInterface $elephantIoLogger, private readonly LoggerInterface $awHandlerLogger, private readonly AnimeWorldService $awService, private readonly ParameterBagInterface $params, string $name = null)
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = Client::create($this->params->get('anime.aw.api_url'), ['client' => Client::CLIENT_4X, 'logger' => $this->elephantIoLogger]);
        $client->connect();
        $client->emit('authorization', [
            'auth' => [
                'clientId' => $this->params->get('anime.aw.client_id'),
                'apiKey' => $this->params->get('anime.aw.api_key')
            ]
        ]);
        while (true) {
            try {
                $packet = $client->drain();
                if (!$packet) {
                    continue;
                }
                try {
                    $this->{"handle" . str_replace('_', '', ucwords($packet->event, "_"))}($packet->data);
                } catch (UndefinedMethodError $e) {
                    $this->awHandlerLogger->error($e->getMessage());
                }
            } catch (Exception $e) {
                $this->awHandlerLogger->error("Error while parsing data from socket.io", ['exception' => $e]);
            }
        }
        return Command::SUCCESS;
    }

    protected function handleAuthorized(array $data): void
    {
        $this->awHandlerLogger->info("Successfully connected", [
            'id' => $data['auth']['id'],
            'usersId' => $data['auth']['usersId'],
            'scopes' => $data['auth']['scopes'],
        ]);
    }

    protected function handleUnauthenticated(array $data): void
    {
    }

    protected function handleUnauthorized(array $data): void
    {
    }

    protected function handleAuthorizationExpired(array $data): void
    {
    }

    protected function handleAuthorizationExceeded(array $data): void
    {
    }

    protected function handleAuthorizationFlooded(array $data): void
    {
    }

    protected function handleDisconnect(array $data): void
    {
    }

    /**
     * Event dispatched when a new anime is added.
     * @param array{
     *     metadata: array{id: int, date: int},
     *     anime: array{id: int, title: string, jtitle: string, categories: ?string[], link: string, dub: bool, image: string, cover: ?string, trailer: ?string, malId: ?int, anilistId: ?int}
     * } $data
     * @return void
     * @throws Exception
     */
    protected function handleEventAnime(array $data): void
    {
    }

    /**
     * Event dispatched when a new episode is added.
     * @param array{
     *     metadata: array{id: int, date: int},
     *     anime: array{id: int, title: string, jtitle: string, categories: ?string[], link: string, dub: bool, image: string, cover: ?string, trailer: ?string, malId: ?int, anilistId: ?int},
     *     episode: array{id: int, number: int|float|string, doubleEpisode: bool, link: string}
     * } $data
     * @return void
     * @throws Exception
     */
    protected function handleEventEpisode(array $data): void
    {
        $this->awHandlerLogger->info("Received EventEpisode", [
            'id' => $data['anime']['id'],
            'title' => $data['anime']['title'],
            'episode' => $data['episode']['link'],
        ]);
        if ($data['anime']['dub']) {
            return;
        }
        try {
            $episodes = $this->awService->createEpisodeDownloads($data['episode']['link']);
            if (!count($episodes)) {
                $this->awHandlerLogger->error("No episode found!", ['episode' => $data['episode']]);
                return;
            }
            $this->awHandlerLogger->info("Added episode!", [
                    'file' => $episodes[0]->getFile(),
                    'episode' => $episodes[0]->getEpisode(),
                    'malId' => $episodes[0]->getMalId()]
            );
        } catch (CacheAnimeNotFoundException $e) {
            $this->awHandlerLogger->warning($e->getMessage());
        }
    }

    /**
     * Event dispatched when a new news is added.
     * @param array{
     *     metadata: array{id: int, date: int},
     *     news: array{id: int, title: string, link: string, description: string, image: string, categories: ?string[]}
     * } $data
     * @return void
     * @throws Exception
     */
    protected function handleEventNews(array $data): void
    {
    }

}