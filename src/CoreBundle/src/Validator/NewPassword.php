<?php

namespace App\CoreBundle\Validator;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Rules of a new password, the same on the profile page (ChangePasswordPayload) and on the reset page
 * (ResetPasswordFormType).
 *
 * The maximum is the one of the password hashers, which refuse longer passwords. NotCompromisedPassword asks the
 * haveibeenpwned.com API with the first 5 characters of the SHA-1 of the password (k-anonymity); when the API cannot
 * be reached the check is skipped, instead of failing every password change. It is disabled in the test environment
 * (config/packages/validator.yaml).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class NewPassword extends Assert\Compound
{
    public const int MIN_LENGTH = 12;
    public const int MAX_LENGTH = 4096;

    protected function getConstraints(array $options): array
    {
        // One message at a time, and the API is asked only about passwords that pass the other rules
        return [
            new Assert\Sequentially([
                new Assert\NotBlank(message: 'Enter a new password.'),
                new Assert\Length(min: self::MIN_LENGTH, max: self::MAX_LENGTH),
                new Assert\PasswordStrength(),
                new Assert\NotCompromisedPassword(skipOnError: true),
            ]),
        ];
    }
}
