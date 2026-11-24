<?php

namespace App\Tests\CoreBundle\Service;

use App\CoreBundle\Service\Telegram\TelegramOAuthClient;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * TelegramOAuthClient as the container builds it, with the real env vars: the redirect URI to register in the "Allowed
 * URLs" of the bot is the callback on DEFAULT_URI, and linking is disabled while the bot is not configured.
 */
class TelegramOAuthClientWiringTest extends KernelTestCase
{
    public function testRedirectUriIsTheCallbackOnTheMainDomain(): void
    {
        $client = static::getContainer()->get(TelegramOAuthClient::class);

        $this->assertSame(
            'https://'.static::getContainer()->getParameter('domain_name').'/profile/telegram/callback',
            $client->getRedirectUri(),
        );
    }

    public function testLinkingIsDisabledWithTheEmptyDefaultsOfDotEnv(): void
    {
        $this->assertFalse(static::getContainer()->get(TelegramOAuthClient::class)->isConfigured());
    }
}
