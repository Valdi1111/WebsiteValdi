<?php

namespace App\Tests\CoreBundle\Service;

use App\CoreBundle\Service\Discord\DiscordOAuthClient;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DiscordOAuthClient as the container builds it, with the real env vars: the redirect URI registered on Discord is
 * the callback on DEFAULT_URI, and linking is disabled while the Discord application is not configured.
 */
class DiscordOAuthClientWiringTest extends KernelTestCase
{
    public function testRedirectUriIsTheCallbackOnTheMainDomain(): void
    {
        $client = static::getContainer()->get(DiscordOAuthClient::class);

        $this->assertSame(
            'https://'.static::getContainer()->getParameter('domain_name').'/profile/discord/callback',
            $client->getRedirectUri(),
        );
    }

    public function testLinkingIsDisabledWithTheEmptyDefaultsOfDotEnv(): void
    {
        $this->assertFalse(static::getContainer()->get(DiscordOAuthClient::class)->isConfigured());
    }
}
