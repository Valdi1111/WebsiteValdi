<?php

namespace App\AnimeBundle\Command;

use App\AnimeBundle\Entity\EpisodeDownloadRequest;
use App\AnimeBundle\Exception\CacheAnimeNotFoundException;
use App\AnimeBundle\Service\AnimeDownloaderInterface;
use ElephantIO\Client;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\ErrorHandler\Error\UndefinedMethodError;

/**
 * Al momento non viene utilizzato il client di socket.io di php.
 * Si utilizza ancora il client node che effettua una HTTP POST quando riceve un nuovo anime.
 */
#[AsCommand(name: 'anime:aw-socket-listener', description: 'Start anime world socket listener')]
class AnimeAwSocketListener extends Command
{
    private ?Client $client;

    public function __construct(
        private readonly LoggerInterface                                    $elephantIoLogger,
        private readonly LoggerInterface                                    $animeAwHandlerLogger,
        private readonly AnimeDownloaderInterface                           $animeWorldDownloader,
        #[Autowire('%anime.animeworld.api_url%')] private readonly string   $awApiUrl,
        #[Autowire('%anime.animeworld.client_id%')] private readonly string $awClientId,
        #[Autowire('%anime.animeworld.api_key%')] private readonly string   $awApiKey,
        ?string                                                             $name = null)
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->createClient();
        while (true) {
            try {
                $packet = $this->client->drain();
                if (!$packet || !$packet->event) {
                    continue;
                }
                try {
                    $this->{"handle" . str_replace('_', '', ucwords($packet->event, "_"))}($packet->data);
                } catch (UndefinedMethodError $e) {
                    $this->animeAwHandlerLogger->error($e->getMessage());
                }
            } catch (Exception $e) {
                $this->animeAwHandlerLogger->error("Error while parsing data from socket.io", ['exception' => $e]);
                sleep(3);
                $this->destroyClient();
                $this->createClient();
                continue;
            }
        }
        return Command::SUCCESS;
    }

    public function createClient(): void
    {
        $this->client = Client::create($this->awApiUrl, [
            'client' => Client::CLIENT_4X,
            'logger' => $this->elephantIoLogger,
            'transport' => 'websocket',
        ]);
        $this->client->connect();
        $this->client->emit('authorization', [
            'auth' => [
                'clientId' => $this->awClientId,
                'apiKey' => $this->awApiKey,
            ]
        ]);
    }

    public function destroyClient(): void
    {
        $this->client->disconnect();
        $this->client = null;
    }

    protected function handleAuthorized(array $data): void
    {
        $this->animeAwHandlerLogger->info("Successfully connected", [
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
        $this->animeAwHandlerLogger->info("Received EventEpisode", [
            'id' => $data['anime']['id'],
            'title' => $data['anime']['title'],
            'episode' => $data['episode']['link'],
        ]);
        if ($data['anime']['dub']) {
            return;
        }
        $downloadReq = (new EpisodeDownloadRequest())
            ->setUrl($data['episode']['link']);
        try {
            $episodes = $this->animeWorldDownloader->createEpisodeDownloads($downloadReq);
            if (!count($episodes)) {
                $this->animeAwHandlerLogger->error("No episode found!", ['episode' => $data['episode']]);
                return;
            }
            $this->animeAwHandlerLogger->info("Added episode!", [
                'file' => $episodes[0]->getFile(),
                'episode' => $episodes[0]->getEpisode(),
                'malId' => $episodes[0]->getMalId()]
            );
        } catch (CacheAnimeNotFoundException $e) {
            $this->animeAwHandlerLogger->warning($e->getMessage());
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