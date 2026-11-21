<?php

namespace App\PerfectworldBundle\Model\Auth;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

class ResendEmailRequest
{
    #[Assert\NotBlank(message: 'Login session ID is required.')]
    #[SerializedName('login_session_id')]
    private string $loginSessionId = '';

    public function getLoginSessionId(): string
    {
        return $this->loginSessionId;
    }

    public function setLoginSessionId(string $loginSessionId): static
    {
        $this->loginSessionId = trim($loginSessionId);

        return $this;
    }
}
