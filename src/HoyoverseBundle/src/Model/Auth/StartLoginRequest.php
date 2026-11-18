<?php

namespace App\HoyoverseBundle\Model\Auth;

use Symfony\Component\Validator\Constraints as Assert;

class StartLoginRequest
{
    #[Assert\NotBlank(message: 'Account email or username is required.')]
    private string $account = '';

    #[Assert\NotBlank(message: 'Password is required.')]
    private string $password = '';

    public function getAccount(): string
    {
        return $this->account;
    }

    public function setAccount(string $account): static
    {
        $this->account = trim($account);

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(#[\SensitiveParameter] string $password): static
    {
        $this->password = $password;

        return $this;
    }
}
