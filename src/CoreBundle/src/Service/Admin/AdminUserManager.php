<?php

namespace App\CoreBundle\Service\Admin;

use App\CoreBundle\Entity\Role;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Entity\UserRole;
use App\CoreBundle\Exception\CannotDisableOwnAccountException;
use App\CoreBundle\Exception\EmailAlreadyUsedException;
use App\CoreBundle\Exception\UnknownRoleException;
use App\CoreBundle\Model\Admin\AdminRoleView;
use App\CoreBundle\Model\Admin\AdminUserView;
use App\CoreBundle\Repository\ResetPasswordRequestRepository;
use App\CoreBundle\Repository\RoleRepository;
use App\CoreBundle\Repository\UserRepository;
use App\CoreBundle\Security\RememberMeTokenRevoker;
use App\CoreBundle\Service\Password\WelcomeLinkSender;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Users admin page (/api/admin/users): listing, creating, disabling and enabling users.
 *
 * Assigning roles changes no cached role map (core.security_hierarchy.cache holds the hierarchy by role, not users).
 */
class AdminUserManager
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RoleRepository $roleRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly WelcomeLinkSender $welcomeLinkSender,
        private readonly ResetPasswordRequestRepository $resetPasswordRequests,
        private readonly RememberMeTokenRevoker $rememberMeTokens,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<AdminUserView> sorted by email
     */
    public function getUserViews(): array
    {
        return array_map(AdminUserView::fromUser(...), $this->userRepository->findAllWithRoles());
    }

    /**
     * @return list<AdminRoleView> every role, the site-wide ones first
     */
    public function getRoleViews(): array
    {
        return array_map(AdminRoleView::fromRole(...), $this->roleRepository->findAllSorted());
    }

    /**
     * Creates the user and emails it a link to choose the password (WelcomeLinkSender), also when the admin chose one.
     *
     * @param string|null  $password  initial password, already validated (NewPassword); null for a random one nobody
     *                                knows, until the user chooses theirs
     * @param list<string> $roleNames roles to assign
     *
     * @throws EmailAlreadyUsedException
     * @throws UnknownRoleException
     */
    public function create(string $email, #[\SensitiveParameter] ?string $password, array $roleNames): User
    {
        // Stored in lower case, so that the list shows one spelling; the unique index ignores case anyway
        $email = mb_strtolower(trim($email));
        if ($this->userRepository->emailExists($email)) {
            throw new EmailAlreadyUsedException();
        }

        $user = (new User())->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password ?? bin2hex(random_bytes(32))));
        foreach ($this->findRoles($roleNames) as $role) {
            $user->addAssignedRole((new UserRole())->setRole($role));
        }

        try {
            $this->userRepository->save($user);
        } catch (UniqueConstraintViolationException $e) {
            // Created by another request since emailExists()
            throw new EmailAlreadyUsedException($e);
        }

        $this->welcomeLinkSender->send($user, $password !== null);

        return $user;
    }

    /**
     * Disables or enables an account. A disabled user cannot log in (UserChecker) and is logged out at the next
     * request (DisabledUserLogoutListener); its remember-me logins and pending password links are deleted here. Its
     * API tokens stay, refused while the account is disabled.
     *
     * @param User $admin the admin making the change
     *
     * @throws CannotDisableOwnAccountException
     */
    public function setEnabled(User $user, bool $enabled, User $admin): void
    {
        if ($enabled) {
            if ($user->isDisabled()) {
                $user->setDisabledAt(null);
                $this->userRepository->save($user);
            }

            return;
        }

        // Also keeps at least one admin enabled: the one making the change
        if ($user->getId() === $admin->getId()) {
            throw new CannotDisableOwnAccountException();
        }
        if ($user->isDisabled()) {
            return;
        }

        $user->setDisabledAt($this->clock->now());
        $this->userRepository->save($user);
        $this->rememberMeTokens->revoke($user, false);
        $this->resetPasswordRequests->removeRequests($user);
    }

    /**
     * @param list<string> $names
     *
     * @return list<Role>
     *
     * @throws UnknownRoleException
     */
    private function findRoles(array $names): array
    {
        $names = array_values(array_unique($names));
        if ($names === []) {
            return [];
        }

        $roles = $this->roleRepository->findBy(['name' => $names]);
        // Exact names: the collation of the column would also find "role_user"
        $unknown = array_values(array_diff($names, array_map(static fn (Role $role): string => (string) $role->getName(), $roles)));
        if ($unknown !== []) {
            throw new UnknownRoleException($unknown);
        }

        return array_values($roles);
    }
}
