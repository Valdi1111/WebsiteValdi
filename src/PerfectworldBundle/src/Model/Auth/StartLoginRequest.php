<?php

namespace App\PerfectworldBundle\Model\Auth;

use Symfony\Component\Validator\Constraints as Assert;

class StartLoginRequest
{
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Please enter a valid email address.')]
    private string $email = '';

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = trim($email);

        return $this;
    }
}
