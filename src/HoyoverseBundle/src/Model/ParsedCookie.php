<?php

namespace App\HoyoverseBundle\Model;

use Symfony\Component\Serializer\Attribute\SerializedName;

class ParsedCookie implements \JsonSerializable, \Stringable
{
    #[SerializedName('ltoken_v2')]
    private ?string $ltokenV2 = null;

    #[SerializedName('ltuid_v2')]
    private ?string $ltuidV2 = null;

    #[SerializedName('ltmid_v2')]
    private ?string $ltmidV2 = null;

    #[SerializedName('cookie_token_v2')]
    private ?string $cookieTokenV2 = null;

    #[SerializedName('account_mid_v2')]
    private ?string $accountMidV2 = null;

    #[SerializedName('account_id_v2')]
    private ?string $accountIdV2 = null;

    public function getLtokenV2(): ?string
    {
        return $this->ltokenV2;
    }

    public function setLtokenV2(?string $ltokenV2): ParsedCookie
    {
        $this->ltokenV2 = $ltokenV2;
        return $this;
    }

    public function getLtuidV2(): ?string
    {
        return $this->ltuidV2;
    }

    public function setLtuidV2(?string $ltuidV2): ParsedCookie
    {
        $this->ltuidV2 = $ltuidV2;
        return $this;
    }

    public function getLtmidV2(): ?string
    {
        return $this->ltmidV2;
    }

    public function setLtmidV2(?string $ltmidV2): ParsedCookie
    {
        $this->ltmidV2 = $ltmidV2;
        return $this;
    }

    public function getCookieTokenV2(): ?string
    {
        return $this->cookieTokenV2;
    }

    public function setCookieTokenV2(?string $cookieTokenV2): ParsedCookie
    {
        $this->cookieTokenV2 = $cookieTokenV2;
        return $this;
    }

    public function getAccountMidV2(): ?string
    {
        return $this->accountMidV2;
    }

    public function setAccountMidV2(?string $accountMidV2): ParsedCookie
    {
        $this->accountMidV2 = $accountMidV2;
        return $this;
    }

    public function getAccountIdV2(): ?string
    {
        return $this->accountIdV2;
    }

    public function setAccountIdV2(?string $accountIdV2): ParsedCookie
    {
        $this->accountIdV2 = $accountIdV2;
        return $this;
    }

    public function isValid(): bool
    {
        return !empty($this->getLtokenV2()) && !empty($this->getLtuidV2()) && !empty($this->getLtmidV2());
    }

    public function canRedeemCodes(): bool
    {
        return !empty($this->getCookieTokenV2()) && !empty($this->getAccountMidV2()) && !empty($this->getAccountIdV2());
    }

    public function jsonSerialize(): array
    {
        $json = [
            'ltoken_v2' => $this->getLtokenV2(),
            'ltuid_v2' => $this->getLtuidV2(),
            'ltmid_v2' => $this->getLtmidV2(),
        ];
        if ($this->canRedeemCodes()) {
            $json['cookie_token_v2'] = $this->getCookieTokenV2();
            $json['account_mid_v2'] = $this->getAccountMidV2();
            $json['account_id_v2'] = $this->getAccountIdV2();
        }
        return array_filter(
            $json,
            static fn(?string $val): bool => $val !== null && $val !== ''
        );
    }

    /**
     * Ricostruisce la stringa dei cookie delimitata da '; ' tramite http_build_query.
     */
    public function __toString(): string
    {
        return http_build_query($this->jsonSerialize(), '', '; ');
    }
}