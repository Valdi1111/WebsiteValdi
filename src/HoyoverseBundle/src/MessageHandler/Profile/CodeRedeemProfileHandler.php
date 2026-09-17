<?php

namespace App\HoyoverseBundle\MessageHandler\Profile;

use App\HoyoverseBundle\Exception\CodeRedeemFailedException;
use App\HoyoverseBundle\Message\Profile\CodeRedeemProfileMessage;
use App\HoyoverseBundle\Message\Profile\ProfileTaskMessageInterface;
use App\HoyoverseBundle\Model\Game\GameInterface;
use App\HoyoverseBundle\Model\Game\HasAutoCodeRedemptionInterface;
use App\HoyoverseBundle\Model\RuntimeAccountData;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @implements AbstractProfileTaskMessageHandler<CodeRedeemProfileMessage>
 */
#[AsMessageHandler]
class CodeRedeemProfileHandler extends AbstractProfileTaskMessageHandler
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

    public function __invoke(CodeRedeemProfileMessage $message): void
    {
        $this->handleProfile($message);
    }

    protected function processProfile(
        GameInterface $gameService,
        RuntimeAccountData $accountData,
        ProfileTaskMessageInterface $message
    ): void
    {
        if (!$gameService instanceof HasAutoCodeRedemptionInterface) {
            return;
        }

        $profileId = $accountData->getGameProfile()->getId();
        $redeemableCode = $message->getRedeemableCode();

        $redeemedKey = sprintf('redeemed_%d_%s', $profileId, $redeemableCode->getCode());
        $pendingKey = sprintf('pending_%d_%s', $profileId, $redeemableCode->getCode());

        // Exit immediately if this code was already processed previously
        if ($this->getCache()->hasItem($redeemedKey)) {
            $this->getCache()->deleteItem($pendingKey);
            return;
        }

        // Apply rate limiting per game service to protect endpoints from anti-bot triggers
        $rateLimitKey = sprintf('last_redeem_timestamp_%s', $accountData->getGameProfile()->getGameId());
        $this->waitRateLimit($rateLimitKey);

        try {
            $gameService->redeemCode($accountData, $redeemableCode);

            // Persist successful redemption permanently and release the pending lock
            $this->markAsRedeemed($redeemedKey);
            $this->getCache()->deleteItem($pendingKey);

            $notification = $this->createNotificationWithAccountData($accountData, "Code Redeem")
                ->setDescription("Code Successfully Redeemed!")
                ->addField("Code", $redeemableCode->getCode(), icon: "💰", inline: true)
                ->addField("Rewards", implode("\n", $redeemableCode->getRewards()), inline: true);

            $this->sendNotificationToAccountData($accountData, $notification);

        } catch (CodeRedeemFailedException $e) {
            $retcode = $e->getHoyolabRetcode();

            // Terminal error codes that will never succeed on future attempts for this profile
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

            // Transient error: release pending lock so Messenger retry strategy can handle it
            $this->getCache()->deleteItem($pendingKey);

            $notification = $this->createNotificationWithAccountData($accountData, "Code Redeem")
                ->setDescription("Code Redeem Failed! ({$e->getHoyolabMessage()} {$e->getHoyolabRetcode()})")
                ->addField("Code", "[{$redeemableCode->getCode()}]({$gameService->getRedemptionLink()}?code={$redeemableCode->getCode()})", icon: "💰", inline: true)
                ->addField("Rewards", implode("\n", $redeemableCode->getRewards()), inline: true);
            // TODO possibile pulsante di retry?

            $this->sendNotificationToAccountData($accountData, $notification);
            throw $e;
        } catch (\Throwable $e) {
            // Ensure pending lock is released on transport, connection, or database errors
            $this->getCache()->deleteItem($pendingKey);
            throw $e;
        } finally {
            // Track the completion timestamp to guarantee RATE_LIMIT_SECONDS before the next call
            $this->updateRateLimitTimestamp($rateLimitKey);
        }
    }

    /**
     * Enforces an interval of at least RATE_LIMIT_SECONDS between consecutive API calls.
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
        // Persist indefinitely without expiration
        $this->getCache()->save($item);
    }
}