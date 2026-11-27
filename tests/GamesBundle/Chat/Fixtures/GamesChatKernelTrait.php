<?php

namespace App\Tests\GamesBundle\Chat\Fixtures;

use App\CoreBundle\Entity\User;
use App\CoreBundle\Repository\RoleRepository;
use App\CoreBundle\Repository\UserRepository;
use App\Tests\CoreBundle\InMemoryUserRepository;

/**
 * The real container of a KernelTestCase around GamesChatWorld, without a database: the users in memory, no role
 * hierarchy (the roles of a user are the assigned ones), the providers of the world.
 */
trait GamesChatKernelTrait
{
    private GamesChatWorld $world;
    private InMemoryUserRepository $users;

    private function bootGamesChat(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->users = new InMemoryUserRepository();
        $container->set(UserRepository::class, $this->users);
        $container->set(RoleRepository::class, $this->createStub(RoleRepository::class));
        $container->get('core.security_hierarchy.cache')->clear();

        $this->world = new GamesChatWorld();
        $this->world->install($container);
    }

    /**
     * @param list<string> $roles
     */
    private function addUser(int $id, array $roles = [GamesChatWorld::ROLE_GAMES, GamesChatWorld::ROLE_FAKE]): User
    {
        return $this->users->add(GamesChatWorld::user($id, $roles));
    }
}
