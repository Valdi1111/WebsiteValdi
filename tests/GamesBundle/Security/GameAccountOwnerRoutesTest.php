<?php

namespace App\Tests\GamesBundle\Security;

use App\CoreBundle\Entity\User;
use App\GamesBundle\Model\Game\GameAccountInterface;
use App\GamesBundle\Security\GameAccountOwnerVoter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\Voter\RoleVoter;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\EventListener\IsGrantedAttributeListener;

/**
 * Every provider route with an {account} parameter must be protected by the shared owner voter.
 *
 * Reads the real route collection, then runs Symfony's IsGranted listener on each action as a request would:
 * the owner passes, another user gets a 403. A new action without the attribute (or without an $account argument,
 * which the class-level attribute needs as subject) fails here instead of in production.
 */
class GameAccountOwnerRoutesTest extends KernelTestCase
{
    private const array PROVIDER_NAMESPACES = ['App\\HoyoverseBundle\\', 'App\\GryphlineBundle\\', 'App\\PerfectworldBundle\\'];
    private const array PROVIDER_ROLES = ['ROLE_USER_HOYOVERSE', 'ROLE_USER_GRYPHLINE', 'ROLE_USER_PERFECTWORLD'];

    /**
     * @return array<string, array{class-string, string}> route name => [controller class, action]
     */
    private function findAccountRoutes(): array
    {
        self::bootKernel();
        $routes = [];

        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            if (!str_contains($route->getPath(), '{account}') || !str_contains($controller, '::')) {
                continue;
            }
            [$class, $action] = explode('::', $controller, 2);
            foreach (self::PROVIDER_NAMESPACES as $namespace) {
                if (str_starts_with($class, $namespace)) {
                    $routes[$name] = [$class, $action];
                }
            }
        }

        return $routes;
    }

    private static function createUser(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    /**
     * Runs the IsGranted listener (class and method attributes) with the given account as the "account" argument.
     *
     * @param class-string $class
     */
    private static function runIsGranted(string $class, string $action, GameAccountInterface $account, User $currentUser): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($currentUser, 'main', self::PROVIDER_ROLES));
        $listener = new IsGrantedAttributeListener(
            new AuthorizationChecker($tokenStorage, new AccessDecisionManager([new RoleVoter(), new GameAccountOwnerVoter()])),
        );

        $method = new \ReflectionMethod($class, $action);
        $arguments = array_map(
            static fn (\ReflectionParameter $param): mixed => $param->getName() === 'account' ? $account : null,
            $method->getParameters(),
        );
        $controller = [(new \ReflectionClass($class))->newInstanceWithoutConstructor(), $action];
        $kernel = self::$kernel;
        self::assertInstanceOf(HttpKernelInterface::class, $kernel);

        $listener->onKernelControllerArguments(new ControllerArgumentsEvent($kernel, $controller, $arguments, new Request(), HttpKernelInterface::MAIN_REQUEST));
    }

    public function testEveryAccountRouteIsProtectedByTheOwnerVoter(): void
    {
        $routes = $this->findAccountRoutes();

        foreach (self::PROVIDER_NAMESPACES as $namespace) {
            $this->assertNotEmpty(
                array_filter($routes, static fn (array $target): bool => str_starts_with($target[0], $namespace)),
                "No {account} route found for $namespace",
            );
        }

        foreach ($routes as $name => [$class, $action]) {
            $method = new \ReflectionMethod($class, $action);

            $ownerAttributes = array_filter(
                [...(new \ReflectionClass($class))->getAttributes(IsGranted::class), ...$method->getAttributes(IsGranted::class)],
                static fn (\ReflectionAttribute $attribute): bool => ($attribute->getArguments()[0] ?? $attribute->getArguments()['attribute'] ?? null) === GameAccountOwnerVoter::OWNER,
            );
            $this->assertCount(1, $ownerAttributes, "$name ($class::$action) must have exactly one #[IsGranted(GameAccountOwnerVoter::OWNER)]");
            $this->assertSame('account', array_values($ownerAttributes)[0]->newInstance()->subject, "$name: the owner check must use the 'account' argument");

            $accountParam = array_values(array_filter($method->getParameters(), static fn (\ReflectionParameter $p): bool => $p->getName() === 'account'))[0] ?? null;
            $this->assertNotNull($accountParam, "$name: $class::$action needs an \$account argument");
            $accountClass = $accountParam->getType() instanceof \ReflectionNamedType ? $accountParam->getType()->getName() : '';
            $this->assertTrue(is_a($accountClass, GameAccountInterface::class, true), "$name: \$account must be a game account");

            /** @var GameAccountInterface&object $ownAccount */
            $ownAccount = (new $accountClass())->setUser(self::createUser(1));
            self::runIsGranted($class, $action, $ownAccount, self::createUser(1));

            /** @var GameAccountInterface&object $foreignAccount */
            $foreignAccount = (new $accountClass())->setUser(self::createUser(2));
            try {
                self::runIsGranted($class, $action, $foreignAccount, self::createUser(1));
                $this->fail("$name: the account of another user must be denied");
            } catch (AccessDeniedException $e) {
                $this->assertSame([GameAccountOwnerVoter::OWNER], $e->getAttributes(), $name);
            }
        }
    }
}
