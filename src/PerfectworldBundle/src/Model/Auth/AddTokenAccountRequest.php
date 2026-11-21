<?php

namespace App\PerfectworldBundle\Model\Auth;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Manual token login: either the "_websdk_" Local Storage JSON ({"uid":…,"token":…}) or uid + token.
 */
class AddTokenAccountRequest
{
    private ?string $websdk = null;

    private ?string $uid = null;

    private ?string $token = null;

    public function getWebsdk(): ?string
    {
        return $this->websdk;
    }

    public function setWebsdk(?string $websdk): static
    {
        $this->websdk = $websdk !== null ? trim($websdk) : null;

        return $this;
    }

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(string|int|null $uid): static
    {
        $this->uid = $uid !== null ? trim((string) $uid) : null;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(?string $token): static
    {
        $this->token = $token !== null ? trim($token) : null;

        return $this;
    }

    /**
     * @return array{uid: string, token: string}|null
     */
    public function getCredentials(): ?array
    {
        $uid = $this->uid;
        $token = $this->token;

        if ($this->websdk !== null && $this->websdk !== '') {
            try {
                $decoded = json_decode($this->websdk, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            if (!is_array($decoded)) {
                return null;
            }
            $uid = trim((string) ($decoded['uid'] ?? ''));
            $token = trim((string) ($decoded['token'] ?? ''));
        }

        if ($uid === null || $token === null || !ctype_digit($uid) || !preg_match('/^[A-Za-z0-9-]{8,128}$/', $token)) {
            return null;
        }

        return ['uid' => $uid, 'token' => $token];
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->getCredentials() === null) {
            $context->buildViolation('Paste the "_websdk_" value ({"uid":…,"token":…}) copied from the Perfect World website.')
                ->atPath('websdk')
                ->addViolation();
        }
    }
}
