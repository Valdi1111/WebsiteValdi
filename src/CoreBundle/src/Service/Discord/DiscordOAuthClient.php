<?php

namespace App\CoreBundle\Service\Discord;

use App\CoreBundle\Exception\DiscordOAuthException;
use App\CoreBundle\Model\Discord\DiscordUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Authorization code grant of Discord OAuth2 (https://discord.com/developers/docs/topics/oauth2), "identify" scope
 * only: enough to read who the user is, to link the account. No login with Discord.
 *
 * The redirect URI is always on DEFAULT_URI, whatever subdomain the flow starts from: Discord accepts only the
 * redirect URIs registered in the application, compared as strings, so one callback per environment is registered
 * instead of one per subdomain. The session cookie is shared on %domain_name%, so the callback still finds the state.
 *
 * The access token is used once and revoked: nothing is read from Discord after the link.
 */
class DiscordOAuthClient
{
    public const string AUTHORIZE_URL = 'https://discord.com/oauth2/authorize';
    public const string SCOPE = 'identify';
    public const string CALLBACK_ROUTE = 'core_discord_callback';

    public function __construct(
        #[Target('discord.client')]
        private readonly HttpClientInterface $client,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(env: 'DISCORD_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'DISCORD_CLIENT_SECRET')]
        #[\SensitiveParameter]
        private readonly string $clientSecret,
        #[Autowire(env: 'DEFAULT_URI')]
        private readonly string $defaultUri,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * False without the client id and secret of the Discord application: linking is then disabled.
     */
    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    /**
     * The same in the authorization request and in the code exchange, as Discord requires.
     */
    public function getRedirectUri(): string
    {
        return rtrim($this->defaultUri, '/').$this->urlGenerator->generate(self::CALLBACK_ROUTE);
    }

    /**
     * @param string $state random value kept in the session, checked on the callback
     */
    public function getAuthorizationUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'scope' => self::SCOPE,
            'state' => $state,
            'redirect_uri' => $this->getRedirectUri(),
            // Asks again when already authorized, so that the user sees which account is being linked
            'prompt' => 'consent',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges the code of the callback for an access token, reads the user and revokes the token.
     *
     * @throws DiscordOAuthException when the code cannot be exchanged or the user cannot be read
     */
    public function fetchUser(string $code): DiscordUser
    {
        $accessToken = $this->exchangeCode($code);

        try {
            return DiscordUser::fromApi($this->client->request('GET', 'users/@me', ['auth_bearer' => $accessToken])->toArray());
        } catch (HttpClientExceptionInterface $e) {
            throw new DiscordOAuthException('Could not read the Discord user: '.$e->getMessage(), 0, $e);
        } finally {
            $this->revoke($accessToken);
        }
    }

    private function exchangeCode(string $code): string
    {
        try {
            // Form-encoded only: the token endpoint refuses JSON
            $data = $this->client->request('POST', 'oauth2/token', [
                'auth_basic' => [$this->clientId, $this->clientSecret],
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->getRedirectUri(),
                ],
            ])->toArray();
        } catch (HttpClientExceptionInterface $e) {
            throw new DiscordOAuthException('Could not exchange the Discord authorization code: '.$e->getMessage(), 0, $e);
        }

        $accessToken = $data['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new DiscordOAuthException('Discord answered the code exchange without an access token.');
        }

        return $accessToken;
    }

    /**
     * A failed revocation does not undo the link: the token expires by itself (7 days) and grants "identify" only.
     */
    private function revoke(string $accessToken): void
    {
        try {
            $response = $this->client->request('POST', 'oauth2/token/revoke', [
                'auth_basic' => [$this->clientId, $this->clientSecret],
                'body' => ['token' => $accessToken, 'token_type_hint' => 'access_token'],
            ]);
            if ($response->getStatusCode() >= 300) {
                $this->logger?->warning('Discord did not revoke the access token: HTTP {status}.', ['status' => $response->getStatusCode()]);
            }
        } catch (HttpClientExceptionInterface $e) {
            $this->logger?->warning('Discord did not revoke the access token: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
