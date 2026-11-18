<?php

namespace App\GryphlineBundle\Model\Auth;

use App\GryphlineBundle\Entity\GryphlineAccount;
use Symfony\Component\Serializer\Attribute\SerializedName;

class GryphlineLoginStepResult
{
    public const string STATUS_AUTHENTICATED = 'authenticated';
    public const string STATUS_CAPTCHA_REQUIRED = 'captcha_required';

    /**
     * @param array<string, mixed>|null $captcha
     */
    public function __construct(
        private string $status,
        #[SerializedName('login_session_id')]
        private ?string $loginSessionId = null,
        private ?array $captcha = null,
        private ?GryphlineAccount $account = null,
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

    public function getAccount(): ?GryphlineAccount
    {
        return $this->account;
    }

    public function setAccount(?GryphlineAccount $account): static
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
