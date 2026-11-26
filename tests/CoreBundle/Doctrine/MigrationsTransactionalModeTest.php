<?php

namespace App\Tests\CoreBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * MySQL commits implicitly on DDL, so a migration that runs DDL must not be wrapped in a transaction: doctrine/migrations
 * would find it already committed and log a deprecation. A migration that only changes data keeps the default, so its
 * statements stay atomic. Each migration runs against a stubbed connection, which records the SQL it would execute.
 */
class MigrationsTransactionalModeTest extends TestCase
{
    private const string DDL_PATTERN = '/^\s*(CREATE|ALTER|DROP|RENAME|TRUNCATE)\b/i';

    /**
     * @return iterable<string, array{string}>
     */
    public static function migrationProvider(): iterable
    {
        $files = glob(dirname(__DIR__, 3) . '/migrations/Version*.php') ?: [];
        foreach ($files as $file) {
            yield basename($file, '.php') => [$file];
        }
    }

    public function testMigrationsAreFound(): void
    {
        $this->assertNotEmpty(iterator_to_array(self::migrationProvider()));
    }

    #[DataProvider('migrationProvider')]
    public function testTransactionalModeMatchesTheStatements(string $file): void
    {
        $executed = [];
        $migration = $this->instantiate($file, $executed);

        $migration->up(new Schema());
        try {
            $migration->down(new Schema());
        } catch (AbortMigration|IrreversibleMigration) {
            // A down() that refuses to run adds nothing to check
        }

        $statements = array_merge(
            array_map(static fn ($query): string => $query->getStatement(), $migration->getSql()),
            $executed,
        );
        $runsDdl = [] !== preg_grep(self::DDL_PATTERN, $statements);
        $name = basename($file, '.php');

        if ($runsDdl) {
            $this->assertFalse($migration->isTransactional(), sprintf('%s runs DDL, which MySQL commits implicitly: isTransactional() must return false.', $name));
        } else {
            $this->assertTrue($migration->isTransactional(), sprintf('%s only changes data: remove its isTransactional() override, so its statements run in one transaction.', $name));
        }
    }

    /**
     * @param list<string> $executed receives the statements the migration runs directly on the connection
     */
    private function instantiate(string $file, array &$executed): AbstractMigration
    {
        require_once $file;
        $class = 'DoctrineMigrations\\' . basename($file, '.php');

        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$executed): int {
            $executed[] = $sql;

            return 0;
        });

        $migration = new $class($connection, new NullLogger());
        $this->assertInstanceOf(AbstractMigration::class, $migration);

        return $migration;
    }
}
