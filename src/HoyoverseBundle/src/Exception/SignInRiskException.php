<?php

namespace App\HoyoverseBundle\Exception;

/**
 * HoYoLAB answered the sign request with retcode 0 and message "OK", but flagged it as a risk and asked for a captcha
 * (GeeTest): the reward was NOT claimed, and only a check-in by hand on HoYoLAB can claim it.
 */
class SignInRiskException extends SignInFailedException
{
    public function __construct(
        private readonly int|string|null $riskCode
    )
    {
        parent::__construct(sprintf(
            'Check-in not claimed: HoYoLAB asked for a captcha (risk code %s)',
            $riskCode ?? 'unknown'
        ));
    }

    public function getRiskCode(): int|string|null
    {
        return $this->riskCode;
    }
}
