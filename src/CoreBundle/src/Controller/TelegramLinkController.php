<?php

namespace App\CoreBundle\Controller;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Exception\TelegramAccountAlreadyLinkedException;
use App\CoreBundle\Exception\TelegramBotException;
use App\CoreBundle\Exception\TelegramMessagesNotAllowedException;
use App\CoreBundle\Exception\TelegramOAuthException;
use App\CoreBundle\Service\Telegram\TelegramAccountLinker;
use App\CoreBundle\Service\Telegram\TelegramOAuthClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Link Telegram" from the profile page: full-page redirects of the OpenID Connect flow of Telegram
 * (TelegramOAuthClient). Unlinking and reading the linked account are in ApiMeTelegramController.
 *
 * As in DiscordLinkController, the flow can start on any subdomain, while the callback is always on DEFAULT_URI: the
 * state, the PKCE code verifier and the subdomain to go back to are kept in the session, shared on %domain_name%. The
 * user ends on /profile of that subdomain with "?telegram=<result>", one of the RESULT_* constants, which the profile
 * page shows.
 *
 * Above the "/profile/{path}" catch-all of ProfileController.
 */
#[IsGranted('ROLE_USER', null, 'Access Denied.')]
class TelegramLinkController extends AbstractController
{
    public const string RESULT_LINKED = 'linked';
    public const string RESULT_CANCELLED = 'cancelled';
    public const string RESULT_ALREADY_LINKED = 'already_linked';
    /** Logged in, but without "Allow Messages": the bot could not send the welcome message */
    public const string RESULT_MESSAGES_NOT_ALLOWED = 'messages_not_allowed';
    public const string RESULT_UNAVAILABLE = 'unavailable';
    public const string RESULT_ERROR = 'error';

    private const string SESSION_KEY = 'core_telegram_link';

    public function __construct(
        private readonly TelegramOAuthClient $oauthClient,
        private readonly TelegramAccountLinker $linker,
        #[Autowire('%domain_name%')]
        private readonly string $domainName,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[Route('/profile/telegram/connect', name: 'telegram_connect', methods: ['GET'], priority: 1)]
    public function connect(Request $request): RedirectResponse
    {
        $origin = $this->isOwnHost($request->getHost()) ? $request->getSchemeAndHttpHost() : '';
        if (!$this->oauthClient->isConfigured()) {
            return $this->backToProfile($origin, self::RESULT_UNAVAILABLE);
        }

        // A new flow replaces a pending one: only the last authorization request can complete
        $state = bin2hex(random_bytes(16));
        $codeVerifier = TelegramOAuthClient::createCodeVerifier();
        $request->getSession()->set(self::SESSION_KEY, ['state' => $state, 'code_verifier' => $codeVerifier, 'origin' => $origin]);

        return $this->redirect($this->oauthClient->getAuthorizationUrl($state, $codeVerifier));
    }

    #[Route('/profile/telegram/callback', name: 'telegram_callback', methods: ['GET'], priority: 1)]
    public function callback(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        // Removed at once: a state is good for one callback
        $pending = $request->getSession()->remove(self::SESSION_KEY);
        $state = $request->query->get('state');
        if (!is_array($pending) || !is_string($pending['state'] ?? null) || !is_string($state) || !hash_equals($pending['state'], $state)) {
            // Not started from this session, already used, or forged: the origin is unknown
            return $this->backToProfile('', self::RESULT_ERROR);
        }
        $origin = is_string($pending['origin'] ?? null) ? $pending['origin'] : '';

        $error = $request->query->get('error');
        if ($error !== null) {
            return $this->backToProfile($origin, $error === 'access_denied' ? self::RESULT_CANCELLED : self::RESULT_ERROR);
        }

        $code = $request->query->get('code');
        $codeVerifier = $pending['code_verifier'] ?? null;
        if (!is_string($code) || $code === '' || !is_string($codeVerifier)) {
            return $this->backToProfile($origin, self::RESULT_ERROR);
        }

        try {
            $this->linker->link($user, $this->oauthClient->fetchUser($code, $codeVerifier));
        } catch (TelegramAccountAlreadyLinkedException) {
            return $this->backToProfile($origin, self::RESULT_ALREADY_LINKED);
        } catch (TelegramMessagesNotAllowedException) {
            return $this->backToProfile($origin, self::RESULT_MESSAGES_NOT_ALLOWED);
        } catch (TelegramOAuthException|TelegramBotException $e) {
            $this->logger?->error('Telegram account not linked: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return $this->backToProfile($origin, self::RESULT_ERROR);
        }

        return $this->backToProfile($origin, self::RESULT_LINKED);
    }

    /**
     * @param string $origin scheme and host of the subdomain the flow started from, '' for the current one
     */
    private function backToProfile(string $origin, string $result): RedirectResponse
    {
        return $this->redirect($origin.$this->generateUrl('core_profile', ['telegram' => $result]));
    }

    /**
     * The site or one of its subdomains: the origin is never another site, whatever Host header the request had.
     */
    private function isOwnHost(string $host): bool
    {
        return $host === $this->domainName || str_ends_with($host, '.'.$this->domainName);
    }
}
