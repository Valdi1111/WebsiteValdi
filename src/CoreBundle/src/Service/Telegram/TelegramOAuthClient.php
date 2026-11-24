<?php

namespace App\CoreBundle\Service\Telegram;

use App\CoreBundle\Exception\TelegramOAuthException;
use App\CoreBundle\Model\Telegram\TelegramUser;
use Jose\Component\Checker\AlgorithmChecker;
use Jose\Component\Checker\AudienceChecker;
use Jose\Component\Checker\ClaimCheckerManager;
use Jose\Component\Checker\ExpirationTimeChecker;
use Jose\Component\Checker\HeaderCheckerManager;
use Jose\Component\Checker\IssuedAtChecker;
use Jose\Component\Checker\IssuerChecker;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSTokenSupport;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Notifier\Exception\InvalidArgumentException as InvalidDsnException;
use Symfony\Component\Notifier\Transport\Dsn;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "Log In With Telegram" (https://core.telegram.org/bots/telegram-login), the OpenID Connect authorization code flow
 * with PKCE, used to read who the user is and to link the account. No login with Telegram.
 *
 * The "telegram:bot_access" scope asks the user to let the bot message them, but the user can refuse it ("Allow
 * Messages" on the consent screen) and the token response does not tell: TelegramAccountLinker finds out by sending a
 * message. The bot must be the one of TELEGRAM_DSN, which sends the notifications: the permission is for this bot,
 * whose id is the client id.
 *
 * Like DiscordOAuthClient, the redirect URI is always on DEFAULT_URI, the one registered in the "Allowed URLs" of the
 * bot, whatever subdomain the flow starts from.
 *
 * Telegram has no UserInfo endpoint: the user is in the id token, whose signature is checked with the keys of
 * Telegram. The access token is not used.
 */
class TelegramOAuthClient
{
    public const string AUTHORIZE_URL = 'https://oauth.telegram.org/auth';
    public const string ISSUER = 'https://oauth.telegram.org';
    /** "profile" for the user id, "telegram:bot_access" for the permission of the bot to message the user */
    public const string SCOPE = 'openid profile telegram:bot_access';
    public const string CALLBACK_ROUTE = 'core_telegram_callback';
    /** The only algorithm accepted for the id token, Telegram's default (BotFather > Login Widget > Advanced) */
    public const string ALGORITHM = 'RS256';

    /**
     * Seconds of clock difference accepted on "iat" and "exp": the id token is valid for 30 seconds only, so a server
     * clock a little behind or ahead must not refuse it.
     */
    private const int ALLOWED_TIME_DRIFT = 60;

    public function __construct(
        #[Target('telegram.oauth.client')]
        private readonly HttpClientInterface $client,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'TELEGRAM_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'TELEGRAM_CLIENT_SECRET')]
        #[\SensitiveParameter]
        private readonly string $clientSecret,
        #[Autowire(env: 'TELEGRAM_DSN')]
        #[\SensitiveParameter]
        private readonly string $botDsn,
        #[Autowire(env: 'DEFAULT_URI')]
        private readonly string $defaultUri,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * False without the client id and secret of the bot, or when the client id is not the bot of TELEGRAM_DSN:
     * linking is then disabled, since the bot of the notifications could not message the linked users.
     */
    public function isConfigured(): bool
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            return false;
        }
        if ($this->getNotificationBotId() !== $this->clientId) {
            $this->logger?->error('Telegram linking is disabled: TELEGRAM_CLIENT_ID is not the id of the bot of TELEGRAM_DSN.');

            return false;
        }

        return true;
    }

    /**
     * The same in the authorization request and in the code exchange, and one of the "Allowed URLs" of the bot.
     */
    public function getRedirectUri(): string
    {
        return rtrim($this->defaultUri, '/').$this->urlGenerator->generate(self::CALLBACK_ROUTE);
    }

    /**
     * PKCE code verifier (RFC 7636): kept in the session, sent with the code exchange.
     */
    public static function createCodeVerifier(): string
    {
        return self::base64UrlEncode(random_bytes(32));
    }

    /**
     * @param string $state        random value kept in the session, checked on the callback
     * @param string $codeVerifier createCodeVerifier(), kept in the session: only its hash is sent here
     */
    public function getAuthorizationUrl(string $state, string $codeVerifier): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->getRedirectUri(),
            'scope' => self::SCOPE,
            'state' => $state,
            'code_challenge' => self::base64UrlEncode(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges the code of the callback for an id token and reads the user from it.
     *
     * @throws TelegramOAuthException when the code cannot be exchanged or the id token is not valid
     */
    public function fetchUser(string $code, string $codeVerifier): TelegramUser
    {
        return TelegramUser::fromIdTokenClaims($this->validateIdToken($this->exchangeCode($code, $codeVerifier)));
    }

    private function exchangeCode(string $code, string $codeVerifier): string
    {
        try {
            $data = $this->client->request('POST', 'token', [
                'auth_basic' => [$this->clientId, $this->clientSecret],
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->getRedirectUri(),
                    'client_id' => $this->clientId,
                    'code_verifier' => $codeVerifier,
                ],
            ])->toArray();
        } catch (HttpClientExceptionInterface $e) {
            throw new TelegramOAuthException('Could not exchange the Telegram authorization code: '.$e->getMessage(), 0, $e);
        }

        $idToken = $data['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            // An expired or reused code is refused with HTTP 200 and {"error": "invalid_grant"}
            $error = is_string($data['error'] ?? null) ? ': '.$data['error'] : '';

            throw new TelegramOAuthException('Telegram answered the code exchange without an id token'.$error.'.');
        }

        return $idToken;
    }

    /**
     * Checks the signature with the key the header names, then the issuer, the audience (this bot) and the times.
     *
     * @return array<mixed> the claims
     */
    private function validateIdToken(string $idToken): array
    {
        try {
            $jws = (new CompactSerializer())->unserialize($idToken);
            // Before choosing a key: "none", HMAC and the other algorithms of Telegram are refused here
            (new HeaderCheckerManager([new AlgorithmChecker([self::ALGORITHM], true)], [new JWSTokenSupport()]))
                ->check($jws, 0, ['alg', 'kid']);

            $kid = $jws->getSignature(0)->getProtectedHeaderParameter('kid');
            $key = $this->findKey($kid);
            if (!(new JWSVerifier(new AlgorithmManager([new RS256()])))->verifyWithKey($jws, $key, 0)) {
                throw new TelegramOAuthException('The signature of the Telegram id token is not valid.');
            }

            $claims = json_decode((string) $jws->getPayload(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($claims)) {
                throw new TelegramOAuthException('The Telegram id token has no claims.');
            }

            (new ClaimCheckerManager([
                new IssuerChecker([self::ISSUER]),
                new AudienceChecker($this->clientId),
                new IssuedAtChecker($this->clock, self::ALLOWED_TIME_DRIFT),
                new ExpirationTimeChecker($this->clock, self::ALLOWED_TIME_DRIFT),
            ]))->check($claims, ['iss', 'aud', 'iat', 'exp']);

            return $claims;
        } catch (TelegramOAuthException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Malformed token, header or claims refused by the checkers
            throw new TelegramOAuthException('The Telegram id token is not valid: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * The key of Telegram that signed the token. Fetched at each link, which is rare: no cache to go stale when
     * Telegram changes its keys.
     */
    private function findKey(mixed $kid): JWK
    {
        try {
            $keys = JWKSet::createFromKeyData($this->client->request('GET', '.well-known/jwks.json')->toArray());
        } catch (HttpClientExceptionInterface $e) {
            throw new TelegramOAuthException('Could not read the keys of Telegram: '.$e->getMessage(), 0, $e);
        }

        // The set also numbers the keys without "kid": the found key must really have this one
        if (!is_string($kid) || !$keys->has($kid) || !$keys->get($kid)->has('kid') || $keys->get($kid)->get('kid') !== $kid) {
            throw new TelegramOAuthException(sprintf('The Telegram id token is signed with an unknown key "%s".', is_string($kid) ? $kid : get_debug_type($kid)));
        }

        return $keys->get($kid);
    }

    /**
     * Id of the bot of TELEGRAM_DSN ("telegram://<bot id>:<secret>@default"), null when it cannot be read.
     */
    private function getNotificationBotId(): ?string
    {
        try {
            return (new Dsn($this->botDsn))->getUser();
        } catch (InvalidDsnException) {
            return null;
        }
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
