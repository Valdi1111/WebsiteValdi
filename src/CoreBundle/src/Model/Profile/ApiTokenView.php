<?php

namespace App\CoreBundle\Model\Profile;

/**
 * An API token of the logged-in user as the profile page sees it (GET /api/me/tokens).
 *
 * The value is given only when it is asked for (GET /api/me/tokens/{id}) and when the token is created: the list
 * reads no encrypted column, so a value that cannot be decrypted never breaks it.
 */
final readonly class ApiTokenView
{
    /**
     * @param string|null $description null for the tokens made before descriptions existed
     * @param bool        $valid       false when disabled: the token does not log in
     * @param string|null $token       the value, only when asked for
     */
    public function __construct(
        public int $id,
        public ?string $description,
        public bool $valid,
        #[\SensitiveParameter]
        public ?string $token = null,
    ) {
    }
}
