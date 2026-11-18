<?php

namespace App\CoreBundle\Model\Auth;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

class SubmitCaptchaRequest
{
    #[Assert\NotBlank(message: 'Login session ID is required.')]
    #[SerializedName('login_session_id')]
    private string $loginSessionId = '';

    /**
     * @var array<string, mixed>
     */
    #[Assert\NotBlank(message: 'Captcha payload is required.')]
    #[Assert\Type('array')]
    private array $captcha = [];

    public function getLoginSessionId(): string
    {
        return $this->loginSessionId;
    }

    public function setLoginSessionId(string $loginSessionId): static
    {
        $this->loginSessionId = $loginSessionId;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCaptcha(): array
    {
        return $this->captcha;
    }

    /**
     * @param array<string, mixed> $captcha
     */
    public function setCaptcha(array $captcha): static
    {
        $this->captcha = $captcha;

        return $this;
    }
}
