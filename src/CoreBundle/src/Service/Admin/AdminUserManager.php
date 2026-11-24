<?php

namespace App\CoreBundle\Service\Admin;

use App\CoreBundle\Entity\Role;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Entity\UserRole;
use App\CoreBundle\Exception\CannotDisableOwnAccountException;
use App\CoreBundle\Exception\CannotRemoveOwnAdminRoleException;
use App\CoreBundle\Exception\EmailAlreadyUsedException;
use App\CoreBundle\Exception\UnknownRoleException;
use App\CoreBundle\Model\Admin\AdminRoleView;
use App\CoreBundle\Model\Admin\AdminUserView;
use App\CoreBundle\Repository\ResetPasswordRequestRepository;
use App\CoreBundle\Repository\RoleRepository;
use App\CoreBundle\Repository\UserRepository;
use App\CoreBundle\Security\RememberMeTokenRevoker;
use App\CoreBundle\Service\Password\WelcomeLinkSender;
use App\CoreBundle\Service\UserRoleResolver;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Users admin page (/api/admin/users): listing, creating, disabling and enabling users, and changing their roles.
 *
 * Assigning roles changes no cached role map (core.security_hierarchy.cache holds the hierarchy by role, not users).
 */
class AdminUserManager
{
    /** The site-wide admin role, which the users admin page and its API require */
    public const string ADMIN_ROLE = 'ROLE_ADMIN';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RoleRepository $roleRepository,
        private readonly UserRoleResolver $roleResolver,
        private readonly RoleHierarchyInterface $roleHierarchy,
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
        return array_map($this->getUserView(...), $this->userRepository->findAllWithRoles());
    }

    public function getUserView(User $user): AdminUserView
    {
        return AdminUserView::fromUser($user, $this->roleResolver->getReachableRoleNames($user));
    }

    /**
     * @return list<AdminRoleView> every role, the site-wide ones first
     */
    public function getRoleViews(): array
    {
        return array_map(
            function (Role $role): AdminRoleView {
                $name = (string) $role->getName();
                $implies = array_values(array_diff($this->roleHierarchy->getReachableRoleNames([$name]), [$name]));
                sort($implies);

                return AdminRoleView::fromRole($role, $implies);
            },
            $this->roleRepository->findAllSorted(),
        );
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
     * Replaces the assigned roles with the given ones: user_role rows are added and removed, those kept are left as
     * they are. Nothing is saved when the set does not change.
     *
     * The user is logged out at their next request, and gets the new roles at the next login: the session keeps the
     * roles of the login, and ContextListener drops it when they differ from those of the database. The API tokens
     * get the new roles at once. An admin changing their own roles is logged out too.
     *
     * An admin cannot leave themselves without the admin role (assigned or inherited). This also keeps at least one
     * admin: only an admin can make the change, and never on their own role.
     *
     * @param list<string> $roleNames roles to assign
     * @param User         $admin     the admin making the change
     *
     * @return bool whether the roles changed
     *
     * @throws UnknownRoleException
     * @throws CannotRemoveOwnAdminRoleException
     */
    public function setRoles(User $user, array $roleNames, User $admin): bool
    {
        $roles = $this->findRoles($roleNames);
        $names = array_map(static fn (Role $role): string => (string) $role->getName(), $roles);

        if ($user->getId() === $admin->getId() && !in_array(self::ADMIN_ROLE, $this->roleHierarchy->getReachableRoleNames($names), true)) {
            throw new CannotRemoveOwnAdminRoleException();
        }

        $changed = false;
        // On a copy: removing changes the collection
        foreach ($user->getAssignedRoles()->toArray() as $assignedRole) {
            if (!in_array($assignedRole->getRole()?->getName(), $names, true)) {
                // Deleted at the flush (orphanRemoval)
                $user->removeAssignedRole($assignedRole);
                $changed = true;
            }
        }

        $kept = $user->getRoles();
        foreach ($roles as $role) {
            if (!in_array($role->getName(), $kept, true)) {
                $user->addAssignedRole((new UserRole())->setRole($role));
                $changed = true;
            }
        }

        if ($changed) {
            $this->userRepository->save($user);
        }

        return $changed;
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
