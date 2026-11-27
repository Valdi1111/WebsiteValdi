<?php

namespace App\GamesBundle\Chat\Command;

use App\CoreBundle\Attribute\AsChatCommand;
use App\CoreBundle\Model\Chat\ChatAction;
use App\CoreBundle\Model\Chat\ChatActionStyle;
use App\CoreBundle\Model\Chat\ChatAutocompleteInterface;
use App\CoreBundle\Model\Chat\ChatCommandBuilder;
use App\CoreBundle\Model\Chat\ChatCommandInterface;
use App\CoreBundle\Model\Chat\ChatReply;
use App\CoreBundle\Model\Chat\ChatRequest;
use App\CoreBundle\Model\Chat\ChatUser;
use App\CoreBundle\Model\Notification\UniversalEmbed;
use App\CoreBundle\Service\Chat\ChatPaginator;
use App\GamesBundle\Chat\Action\GamesChatAction;
use App\GamesBundle\Chat\ChatProfileSelector;
use App\GamesBundle\Model\Chat\ChatProfileSelection;
use App\GamesBundle\Model\Profile\GameProfileRef;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Base of the game chat commands: the game and profile options (with autocomplete), the profiles they select, the
 * answer paged to the platform and its buttons (◀ ▶ pages, 🔄 Refresh). A command supplies its sections and its embeds;
 * the "games" button action (GamesChatAction) calls render() again with another page or a fresh read.
 *
 * Every command name goes in #[AsChatCommand], with role ROLE_USER_GAMES; the descriptions of the commands and options
 * need their Italian text in the GamesBundle translations (domain "chat").
 */
abstract class AbstractProfileChatCommand implements ChatCommandInterface, ChatAutocompleteInterface
{
    public const string ROLE = 'ROLE_USER_GAMES';
    public const string OPTION_GAME = 'game';
    public const string OPTION_PROFILE = 'profile';

    protected ChatProfileSelector $selector;
    private ChatPaginator $paginator;

    /**
     * Setter injection: the subclasses keep their own constructors for what they read and show.
     */
    #[Required]
    public function setChatServices(ChatProfileSelector $selector, ChatPaginator $paginator): void
    {
        $this->selector = $selector;
        $this->paginator = $paginator;
    }

    /**
     * Sections the command shows: the autocomplete leaves out the games and profiles without any of them, and so does
     * the answer once read (an unreadable profile is kept: its answer says why). [] = every profile.
     *
     * @return list<string>
     */
    abstract protected function getSectionKeys(): array;

    /**
     * What the command shows, for "None of your profiles has <subject>.": "stamina", "endgames"...
     */
    abstract protected function getSubject(): string;

    /**
     * The embeds of the selected profiles, in their order; [] when none has anything to show.
     *
     * @param bool $fresh skip the caches (Refresh)
     * @return list<UniversalEmbed>
     */
    abstract protected function buildEmbeds(ChatUser $user, ChatProfileSelection $selection, bool $fresh): array;

    /**
     * Whether the answer gets a Refresh button: only commands that read the game APIs.
     */
    public function canRefresh(): bool
    {
        return true;
    }

    /**
     * The profiles of the selection that a Refresh reads again: those the answer may show. A profile without the
     * command's sections (NTE under /stamina) is never counted against its limit, which the site shares.
     *
     * @return list<GameProfileRef>
     */
    public function getRefreshRefs(ChatProfileSelection $selection): array
    {
        $refs = [];
        foreach ($selection->profiles as $index => $profile) {
            if ($this->selector->mayShow($profile, $this->getSectionKeys())) {
                $refs[] = $selection->refs[$index];
            }
        }

        return $refs;
    }

    /**
     * The name of #[AsChatCommand], for the buttons.
     */
    public function getName(): string
    {
        $attributes = (new \ReflectionClass(static::class))->getAttributes(AsChatCommand::class);
        if ($attributes === []) {
            throw new \LogicException(sprintf('%s has no #[%s].', static::class, AsChatCommand::class));
        }

        return $attributes[0]->newInstance()->name;
    }

    public function configure(ChatCommandBuilder $builder): void
    {
        $builder
            ->addOption(self::OPTION_GAME, 'Only the profiles of this game', autocomplete: true)
            ->addOption(self::OPTION_PROFILE, 'Only this profile', autocomplete: true);
    }

    public function execute(ChatRequest $request): ChatReply
    {
        $selection = $this->selector->select($request->user->user, self::stringOrNull($request->get(self::OPTION_GAME)), self::stringOrNull($request->get(self::OPTION_PROFILE)));
        if (is_string($selection)) {
            return ChatReply::error($selection);
        }

        return $this->render($request->user, $selection, 1, false);
    }

    /**
     * The answer for one page, with its buttons. Called by execute() and by the "games" button action.
     */
    public function render(ChatUser $user, ChatProfileSelection $selection, int $page, bool $fresh): ChatReply
    {
        $embeds = $this->buildEmbeds($user, $selection, $fresh);
        if ($embeds === []) {
            return ChatReply::text(sprintf('None of your profiles has %s.', $this->getSubject()));
        }

        $paged = $this->paginator->paginate($embeds, $user->platform, $page);
        $buttons = [];
        if ($paged->page > 1) {
            $buttons[] = new ChatAction('Previous', GamesChatAction::ref($this->getName(), $paged->page - 1, false, $selection), '◀️');
        }
        if ($paged->page < $paged->pageCount) {
            $buttons[] = new ChatAction('Next', GamesChatAction::ref($this->getName(), $paged->page + 1, false, $selection), '▶️');
        }
        if ($this->canRefresh()) {
            $buttons[] = new ChatAction('Refresh', GamesChatAction::ref($this->getName(), $paged->page, true, $selection), '🔄', ChatActionStyle::Primary);
        }

        return new ChatReply(null, $paged->embeds, $buttons !== [] ? [$buttons] : [], $paged->page, $paged->pageCount);
    }

    public function autocomplete(ChatUser $user, ?string $subcommand, string $option, string $partial, array $arguments): array
    {
        return match ($option) {
            self::OPTION_GAME => $this->selector->suggestGames($user->user, $this->getSectionKeys(), $partial),
            self::OPTION_PROFILE => $this->selector->suggestProfiles($user->user, $this->getSectionKeys(), self::stringOrNull($arguments[self::OPTION_GAME] ?? null), $partial),
            default => [],
        };
    }

    private static function stringOrNull(string|int|bool|null $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
