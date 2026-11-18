<?php

namespace App\GryphlineBundle\Model\Auth;

use Symfony\Component\Validator\Constraints as Assert;

class StartLoginRequest
{
    #[Assert\NotBlank(message: 'Email address is required.')]
    #[Assert\Email(message: 'Please enter a valid email address.')]
    private string $email = '';

    #[Assert\NotBlank(message: 'Password is required.')]
    private string $password = '';

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = trim($email);

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
