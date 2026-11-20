<?php

namespace App\GamesBundle\Command;

use App\GamesBundle\Model\Game\PullItemIconQuery;
use App\GamesBundle\Service\PullItemIconResolverManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:pulls:icon-resolvers',
    description: 'Testa e confronta tutti i provider di icone pull per un determinato gioco e item.',
)]
class PullIconResolversCommand extends Command
{
    public function __construct(
        private readonly PullItemIconResolverManager $resolverManager,
        #[Target('games.pull_icon_fetch.client')]
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('game', 'g', InputOption::VALUE_REQUIRED, 'gameBiz (es. hk4e_global, hkrpg_global, nap_global, endfield)')
            ->addOption('id', null, InputOption::VALUE_OPTIONAL, 'item_id dell\'item')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Nome inglese dell\'item')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Tipo item (Character, Weapon, Light Cone, Agents, W-Engine, Bangboo, char, weapon)')
            ->addOption('check-http', null, InputOption::VALUE_NONE, 'Verifica HTTP GET sugli URL restituiti mostrando status e dimensione');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $gameBiz = (string) $input->getOption('game');

        if ($gameBiz === '' || !$this->resolverManager->supportsGameBiz($gameBiz)) {
            $io->error(sprintf('gameBiz non valido o non supportato: "%s"', $gameBiz));
            return Command::FAILURE;
        }

        $query = new PullItemIconQuery(
            itemId: $input->getOption('id') ?: null,
            name: $input->getOption('name') ?: null,
            itemType: $input->getOption('type') ?: null,
        );

        $checkHttp = (bool) $input->getOption('check-http');
        $candidates = $this->resolverManager->resolveCandidateUrlsDetailedByGameBiz($gameBiz, $query);

        if ($candidates === []) {
            $io->warning('Nessun provider registrato per questo gameBiz.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($candidates as $entry) {
            $url = $entry['url'];
            $httpInfo = '-';
            if ($checkHttp && $url !== null) {
                try {
                    $resp = $this->httpClient->request('GET', $url);
                    $status = $resp->getStatusCode();
                    $body = $status === 200 ? $resp->getContent(false) : '';
                    $ct = $resp->getHeaders(false)['content-type'][0] ?? '?';
                    $httpInfo = sprintf('HTTP %d (%s, %d B)', $status, explode(';', $ct)[0], strlen($body));
                } catch (\Throwable $e) {
                    $httpInfo = 'ERR: ' . $e->getMessage();
                }
            }

            $rows[] = [
                $entry['priority'],
                $entry['resolver'],
                $url ?? ($entry['error'] ? 'ERR: ' . $entry['error'] : '(null)'),
                $httpInfo,
            ];
        }

        $io->table(['Priority', 'Resolver', 'Resolved URL', 'HTTP Check'], $rows);

        return Command::SUCCESS;
    }
}
