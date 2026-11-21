<?php

namespace App\PerfectworldBundle\Model\Auth;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

class VerifyEmailRequest
{
    #[Assert\NotBlank(message: 'Login session ID is required.')]
    #[SerializedName('login_session_id')]
    private string $loginSessionId = '';

    #[Assert\NotBlank(message: 'Verification code is required.')]
    private string $code = '';

    public function getLoginSessionId(): string
    {
        return $this->loginSessionId;
    }

    public function setLoginSessionId(string $loginSessionId): static
    {
        $this->loginSessionId = trim($loginSessionId);

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = trim($code);

        return $this;
    }
}
