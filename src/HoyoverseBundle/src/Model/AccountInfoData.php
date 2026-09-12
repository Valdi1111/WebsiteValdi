<?php

namespace App\HoyoverseBundle\Model;

use Symfony\Component\Serializer\Attribute\SerializedPath;

/**
 * @deprecated
 */
class AccountInfoData
{
    #[SerializedPath('[cookie_info][account_id]')]
    private int $accountId;

    #[SerializedPath('[cookie_info][cookie_token]')]
    private string $cookieToken;

    private int $status;

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    public function setAccountId(int $accountId): AccountInfoData
    {
        $this->accountId = $accountId;
        return $this;
    }

    public function getCookieToken(): string
    {
        return $this->cookieToken;
    }

    public function setCookieToken(string $cookieToken): AccountInfoData
    {
        $this->cookieToken = $cookieToken;
        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): AccountInfoData
    {
        $this->status = $status;
        return $this;
    }
}