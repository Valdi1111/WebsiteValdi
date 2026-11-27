<?php

namespace App\GamesBundle\Chat\Action;

use App\CoreBundle\Attribute\AsChatAction;
use App\CoreBundle\Model\Chat\ChatActionHandlerInterface;
use App\CoreBundle\Model\Chat\ChatActionRef;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatUser;
use App\CoreBundle\Service\Chat\ChatCommandRegistry;
use App\CoreBundle\Service\Chat\ChatDispatcher;
use App\GamesBundle\Chat\ChatProfileSelector;
use App\GamesBundle\Chat\Command\AbstractProfileChatCommand;
use App\GamesBundle\Model\Chat\ChatProfileSelection;
use App\GamesBundle\Service\Status\ProfileStatusRefreshLimiter;

/**
 * The buttons of the game chat commands: another page, or the same page read again without the caches (Refresh).
 *
 * The reference is "games:<command>:<page>:<refresh 0|1>:<game slug>:<provider>:<profile id>", with either the game or
 * the profile (never both: the profile implies its game, and the reference must fit 64 bytes). Buttons live in old
 * messages across deploys, so every part is checked again; the selection goes through the registry (role and owner)
 * and a Refresh through the rate limiter, before anything is queued: a refusal is answered at once and the message stays.
 */
#[AsChatAction(self::NAME)]
class GamesChatAction implements ChatActionHandlerInterface
{
    public const string NAME = 'games';
    private const int ARGS = 6;

    public function __construct(
        private readonly ChatCommandRegistry $commands,
        private readonly ChatProfileSelector $selector,
        private readonly ProfileStatusRefreshLimiter $refreshLimiter,
    ) {
    }

    public static function ref(string $command, int $page, bool $refresh, ChatProfileSelection $selection): ChatActionRef
    {
        return new ChatActionRef(self::NAME, [
            $command,
            (string) $page,
            $refresh ? '1' : '0',
            $selection->profile === null ? ($selection->game?->slug ?? '') : '',
            $selection->profile?->provider ?? '',
            $selection->profile !== null ? (string) $selection->profile->id : '',
        ]);
    }

    public function authorize(ChatUser $user, ChatActionRef $ref): ?string
    {
        $parsed = $this->parse($ref);
        if ($parsed === null) {
            return ChatDispatcher::INVALID_ACTION;
        }
        [$command, , $refresh, $slug, $provider, $id] = $parsed;
        if (!$this->commands->isGranted($user->user, $command->getName())) {
            return ChatDispatcher::FORBIDDEN;
        }

        $selection = $this->selector->fromButton($user->user, $slug, $provider, $id);
        if (is_string($selection)) {
            return $selection;
        }

        // Counted here, before the press is queued: an over-limit Refresh is refused at once
        return $refresh ? $this->refreshLimiter->consumeAll($user->user, $command->getRefreshRefs($selection)) : null;
    }

    public function handle(ChatUser $user, ChatActionRef $ref, string $locale): ChatReply
    {
        $parsed = $this->parse($ref);
        if ($parsed === null) {
            return ChatReply::error(ChatDispatcher::INVALID_ACTION);
        }
        [$command, $page, $refresh, $slug, $provider, $id] = $parsed;

        $selection = $this->selector->fromButton($user->user, $slug, $provider, $id);
        if (is_string($selection)) {
            return ChatReply::error($selection);
        }

        return $command->render($user, $selection, $page, $refresh);
    }

    /**
     * @return array{AbstractProfileChatCommand, int, bool, string, string, string}|null null for anything this version
     *                                                                                   of the buttons does not make
     */
    private function parse(ChatActionRef $ref): ?array
    {
        if (count($ref->args) !== self::ARGS) {
            return null;
        }
        [$name, $page, $refresh, $slug, $provider, $id] = $ref->args;
        if (!ctype_digit($page) || (int) $page < 1 || !in_array($refresh, ['0', '1'], true) || !$this->commands->has($name)) {
            return null;
        }
        $command = $this->commands->get($name);
        if (!$command instanceof AbstractProfileChatCommand || ($refresh === '1' && !$command->canRefresh())) {
            return null;
        }

        return [$command, (int) $page, $refresh === '1', $slug, $provider, $id];
    }
}
