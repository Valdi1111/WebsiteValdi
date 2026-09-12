<?php

namespace App\HoyoverseBundle\MessageHandler;

use App\HoyoverseBundle\Exception\CodeRedeemFailedException;
use App\HoyoverseBundle\Message\GameProfileCodeRedeemMessage;
use App\HoyoverseBundle\Message\TaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasAutoCodeRedemptionInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @extends AbstractTaskMessageHandler<GameProfileCodeRedeemMessage>
 */
#[AsMessageHandler]
class GameProfileCodeRedeemMessageHandler extends AbstractTaskMessageHandler
{
    private const float RATE_LIMIT_SECONDS = 6.0;

    private ?CacheItemPoolInterface $redemptionHistoryCache = null;

    public function getCache(): CacheItemPoolInterface
    {
        return $this->redemptionHistoryCache;
    }

    #[Required]
    public function setCache(CacheItemPoolInterface $hoyoverseRedemptionHistoryCache): void
    {
        $this->redemptionHistoryCache = $hoyoverseRedemptionHistoryCache;
    }

    public function __invoke(GameProfileCodeRedeemMessage $message): void
    {
        $this->handleTask($message);
    }

    protected function processProfile(
        GameInterface        $gameService,
        RuntimeAccountData   $accountData,
        TaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasAutoCodeRedemptionInterface) {
            return;
        }

        $profileId = $accountData->getGameProfile()->getId();
        $redeemableCode = $message->getRedeemableCode();

        $redeemedKey = sprintf('redeemed_%d_%s', $profileId, $redeemableCode->getCode());
        $pendingKey = sprintf('pending_%d_%s', $profileId, $redeemableCode->getCode());

        // Se già presente nello storico definitivo, rimuovi lock ed esci
        if ($this->getCache()->hasItem($redeemedKey)) {
            $this->getCache()->deleteItem($pendingKey);
            return;
        }

        // Rate limiting calcolato sull'IP / id del gioco prima di chiamare l'API
        $rateLimitKey = sprintf('last_redeem_timestamp_%s', $accountData->getGameProfile()->getGameId());
        $this->waitRateLimit($rateLimitKey);

        try {
            $gameService->redeemCode($accountData, $redeemableCode);

            // 1. Successo: salvataggio permanente (senza expiresAfter)
            $this->markAsRedeemed($redeemedKey);
            $this->getCache()->deleteItem($pendingKey);

            $notification = $this->createNotificationWithAccountData($accountData, "Code Redeem")
                ->setDescription("Code Successfully Redeemed!")
                ->addField("Code", $redeemableCode->getCode(), icon: "💰", inline: true)
                ->addField("Rewards", implode("\n", $redeemableCode->getRewards()), inline: true);

            $this->sendNotificationToAccountData($accountData, $notification);

        } catch (CodeRedeemFailedException $e) {
            $retcode = $e->getHoyolabRetcode();

            // Codici che non potranno MAI più avere successo su questo profilo
            if (in_array($retcode, [
                -2017, // Already claimed
                -2016, // Expired / Invalid
                -2003, // Invalid / Does not exist
                -2018, // Max uses reached for this account type
                -2004, // Code already used
                -2014, // In-game only
                -2015, // Invalid server/region
            ], true)) {
                $this->markAsRedeemed($redeemedKey);
                $this->getCache()->deleteItem($pendingKey);

                $notification = $this->createNotificationWithAccountData($accountData, "Code Redeem")
                    ->setDescription("Code Redeem Failed! ({$e->getHoyolabMessage()} {$e->getHoyolabRetcode()})")
                    ->addField("Code", "[{$redeemableCode->getCode()}]({$gameService->getRedemptionLink()}?code={$redeemableCode->getCode()})", icon: "💰", inline: true);

                $this->sendNotificationToAccountData($accountData, $notification);
                return;
            }

            // Errore transitorio: rimuovi solo il lock pending per permettere retry
            $this->getCache()->deleteItem($pendingKey);

            $notification = $this->createNotificationWithAccountData($accountData, "Code Redeem")
                ->setDescription("Code Redeem Failed! ({$e->getHoyolabMessage()} {$e->getHoyolabRetcode()})")
                ->addField("Code", "[{$redeemableCode->getCode()}]({$gameService->getRedemptionLink()}?code={$redeemableCode->getCode()})", icon: "💰", inline: true)
                ->addField("Rewards", implode("\n", $redeemableCode->getRewards()), inline: true);
            // TODO possibile pulsante di retry?

            $this->sendNotificationToAccountData($accountData, $notification);
            throw $e;
        } catch (\Throwable $e) {
            // Se fallisce per errore di rete, cURL, DB, ecc. rimuove il lock
            $this->getCache()->deleteItem($pendingKey);
            throw $e;
        } finally {
            // Registra l'orario di fine richiesta così il prossimo messaggio calcola 6 secondi pieni da adesso
            $this->updateRateLimitTimestamp($rateLimitKey);
        }
    }

    /**
     * Garantisce che intercorrano almeno RATE_LIMIT_SECONDS tra una chiamata e l'altra.
     */
    private function waitRateLimit(string $rateLimitKey): void
    {
        $item = $this->getCache()->getItem($rateLimitKey);

        if ($item->isHit()) {
            $lastTimestamp = (float) $item->get();
            $elapsed = microtime(true) - $lastTimestamp;

            if ($elapsed < self::RATE_LIMIT_SECONDS) {
                $waitTimeMicroseconds = (int) ((self::RATE_LIMIT_SECONDS - $elapsed) * 1_000_000);
                usleep($waitTimeMicroseconds);
            }
        }
    }

    private function updateRateLimitTimestamp(string $rateLimitKey): void
    {
        $item = $this->getCache()->getItem($rateLimitKey);
        $item->set(microtime(true));
        $item->expiresAfter(60);
        $this->getCache()->save($item);
    }

    private function markAsRedeemed(string $cacheKey): void
    {
        $item = $this->getCache()->getItem($cacheKey);
        $item->set(true);
        // Nessun expiresAfter: la chiave resta per sempre
        $this->getCache()->save($item);
    }

}