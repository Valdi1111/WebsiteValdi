<?php

namespace App\Tests\CoreBundle\Doctrine;

use Doctrine\Migrations\DependencyFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * MySQL commits implicitly on DDL, so new migrations are generated non-transactional (doctrine/migrations deprecates
 * committing a transaction that is already committed). The deploy runs every pending migration in one command, so
 * all-or-nothing, which refuses non-transactional migrations, must stay off.
 */
class MigrationsConfigurationTest extends KernelTestCase
{
    public function testNewMigrationsAreGeneratedNonTransactional(): void
    {
        $this->assertFalse($this->getDependencyFactory()->getConfiguration()->isTransactional());
    }

    public function testAllOrNothingStaysOff(): void
    {
        $this->assertFalse($this->getDependencyFactory()->getConfiguration()->isAllOrNothing());
    }

    private function getDependencyFactory(): DependencyFactory
    {
        $factory = static::getContainer()->get('doctrine.migrations.dependency_factory');
        $this->assertInstanceOf(DependencyFactory::class, $factory);

        return $factory;
    }
}
