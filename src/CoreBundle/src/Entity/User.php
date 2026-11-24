<?php

namespace App\CoreBundle\Entity;

use App\CoreBundle\Model\Discord\DiscordUser;
use App\CoreBundle\Model\Notification\NotifiableUserInterface;
use App\CoreBundle\Model\Telegram\TelegramUser;
use App\CoreBundle\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\UniqueConstraint(name: 'IDX_email', columns: ['email'])]
// One Discord account per user: the bot finds the user from the Discord id of an interaction
#[ORM\UniqueConstraint(name: 'IDX_discord_user_id', columns: ['discord_user_id'])]
// One Telegram account per user: the bot will find the user from the Telegram id of a message
#[ORM\UniqueConstraint(name: 'IDX_telegram_chat_id', columns: ['telegram_chat_id'])]
#[ORM\Table(name: 'user')]
#[ORM\Entity(repositoryClass: UserRepository::class)]
class User implements UserInterface, PasswordAuthenticatedUserInterface, NotifiableUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    /**
     * @var string|null The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $discordUserId = null;

    /**
     * Linked Discord account as it was when linked (linkDiscordAccount()), to show it on the profile page.
     * Length: DiscordUser::MAX_NAME_LENGTH.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $discordUsername = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $discordGlobalName = null;

    /** Avatar hash ("a_" + 32 hex digits when animated), null for the default avatar */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $discordAvatar = null;

    /**
     * Telegram user id, which is also the id of the private chat with the bot: the notifications are sent there.
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $telegramChatId = null;

    /**
     * Linked Telegram account as it was when linked (linkTelegramAccount()), to show it on the profile page.
     * Length: TelegramUser::MAX_NAME_LENGTH.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $telegramUsername = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $telegramName = null;

    /**
     * @var Collection<int, UserRole>
     */
    #[ORM\OneToMany(targetEntity: UserRole::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private Collection $assignedRoles;

    public function __construct()
    {
        $this->assignedRoles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->getAssignedRoles()
            ->map(fn(UserRole $a) => $a->getRole()->getName())
            ->toArray();
        return array_unique($roles);
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getDiscordUserId(): ?string
    {
        return $this->discordUserId;
    }

    public function setDiscordUserId(?string $discordUserId): static
    {
        $this->discordUserId = $discordUserId;

        return $this;
    }

    public function getDiscordUsername(): ?string
    {
        return $this->discordUsername;
    }

    public function getDiscordGlobalName(): ?string
    {
        return $this->discordGlobalName;
    }

    public function getDiscordAvatar(): ?string
    {
        return $this->discordAvatar;
    }

    /**
     * Links the account, or refreshes names and avatar when it is already linked. The caller checks that no other
     * user has the same Discord id (DiscordAccountLinker).
     */
    public function linkDiscordAccount(DiscordUser $account): static
    {
        $this->discordUserId = $account->id;
        $this->discordUsername = $account->username;
        $this->discordGlobalName = $account->globalName;
        $this->discordAvatar = $account->avatar;

        return $this;
    }

    public function unlinkDiscordAccount(): static
    {
        $this->discordUserId = null;
        $this->discordUsername = null;
        $this->discordGlobalName = null;
        $this->discordAvatar = null;

        return $this;
    }

    public function getTelegramChatId(): ?string
    {
        return $this->telegramChatId;
    }

    public function setTelegramChatId(string $telegramChatId): static
    {
        $this->telegramChatId = $telegramChatId;

        return $this;
    }

    public function getTelegramUsername(): ?string
    {
        return $this->telegramUsername;
    }

    public function getTelegramName(): ?string
    {
        return $this->telegramName;
    }

    /**
     * Links the account, or refreshes its names when it is already linked. The caller checks that no other user has
     * the same Telegram id and that the bot can message it (TelegramAccountLinker).
     */
    public function linkTelegramAccount(TelegramUser $account): static
    {
        $this->telegramChatId = $account->id;
        $this->telegramUsername = $account->username;
        $this->telegramName = $account->name;

        return $this;
    }

    public function unlinkTelegramAccount(): static
    {
        $this->telegramChatId = null;
        $this->telegramUsername = null;
        $this->telegramName = null;

        return $this;
    }

    /**
     * @return Collection<int, UserRole>
     */
    public function getAssignedRoles(): Collection
    {
        return $this->assignedRoles;
    }

    public function addAssignedRole(UserRole $assignedRole): static
    {
        if (!$this->assignedRoles->contains($assignedRole)) {
            $this->assignedRoles->add($assignedRole);
            $assignedRole->setUser($this);
        }

        return $this;
    }

    public function removeAssignedRole(UserRole $assignedRole): static
    {
        if ($this->assignedRoles->removeElement($assignedRole)) {
            // set the owning side to null (unless already changed)
            if ($assignedRole->getUser() === $this) {
                $assignedRole->setUser(null);
            }
        }

        return $this;
    }
}
