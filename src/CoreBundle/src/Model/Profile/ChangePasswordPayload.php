<?php

namespace App\CoreBundle\Model\Profile;

use App\CoreBundle\Validator\NewPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/me/password ("current_password", "new_password"). The confirmation of the new password is
 * checked by the form on the page.
 *
 * The current password is not validated here with #[UserPassword]: a wrong one would be refused before the action
 * runs, and the attempt would escape the rate limiter of UserPasswordManager::change(), which checks it instead.
 */
final readonly class ChangePasswordPayload
{
    public function __construct(
        #[Assert\NotBlank(message: 'Enter your current password.')]
        #[\SensitiveParameter]
        public string $currentPassword = '',
        #[NewPassword]
        #[Assert\NotIdenticalTo(propertyPath: 'currentPassword', message: 'The new password must be different from the current one.')]
        #[\SensitiveParameter]
        public string $newPassword = '',
    ) {
    }
}
