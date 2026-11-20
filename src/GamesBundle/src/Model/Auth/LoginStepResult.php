<?php

namespace App\GamesBundle\Model\Auth;

use App\GamesBundle\Model\Game\GameAccountInterface;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Outcome of one step of a provider direct-login wizard, serialized to the games SPA.
 */
class LoginStepResult
{
    public const string STATUS_AUTHENTICATED = 'authenticated';
    public const string STATUS_CAPTCHA_REQUIRED = 'captcha_required';
    public const string STATUS_EMAIL_VERIFICATION_REQUIRED = 'email_verification_required';

    /**
     * @param array<string, mixed>|null $captcha
     */
    public function __construct(
        private string $status,
        #[SerializedName('login_session_id')]
        private ?string $loginSessionId = null,
        private ?array $captcha = null,
        private ?GameAccountInterface $account = null,
        private ?string $message = null,
    ) {
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getLoginSessionId(): ?string
    {
        return $this->loginSessionId;
    }

    public function setLoginSessionId(?string $loginSessionId): static
    {
        $this->loginSessionId = $loginSessionId;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCaptcha(): ?array
    {
        return $this->captcha;
    }

    /**
     * @param array<string, mixed>|null $captcha
     */
    public function setCaptcha(?array $captcha): static
    {
        $this->captcha = $captcha;

        return $this;
    }

    public function getAccount(): ?GameAccountInterface
    {
        return $this->account;
    }

    public function setAccount(?GameAccountInterface $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }
}
