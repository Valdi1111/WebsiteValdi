<?php

namespace App\Tests\GamesBundle\Doctrine;

use App\CoreBundle\Doctrine\EncryptedTextType;
use App\CoreBundle\Model\Encryption\EncryptedColumn;
use App\CoreBundle\Service\Encryption\EncryptedColumnLocator;
use App\GryphlineBundle\Entity\GryphlineAccount;
use App\HoyoverseBundle\Entity\HoyoverseAccount;
use App\PerfectworldBundle\Entity\PerfectworldAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Boots the real kernel without a database connection (metadata only needs the platform, taken from the
 * serverVersion of DATABASE_URL): exactly the five credential columns of the game accounts use the CoreBundle
 * encrypted column type, and the conversion command finds them.
 */
class EncryptedCredentialMappingTest extends KernelTestCase
{
    private const array ENCRYPTED_FIELDS = [
        HoyoverseAccount::class => ['cookie'],
        GryphlineAccount::class => ['accountToken', 'sessionCred', 'sessionSalt'],
        PerfectworldAccount::class => ['token'],
    ];

    private function getEntityManager(): EntityManagerInterface
    {
        self::bootKernel();

        // Lazy service: it is built by the first method call (the metadata tests below do not need the DBAL types)
        return static::getContainer()->get('doctrine')->getManager();
    }

    public function testCredentialColumnsAreMappedOnTheEncryptedType(): void
    {
        $entityManager = $this->getEntityManager();

        foreach (self::ENCRYPTED_FIELDS as $class => $fields) {
            $metadata = $entityManager->getClassMetadata($class);
            foreach ($fields as $field) {
                $this->assertSame(EncryptedTextType::NAME, $metadata->getTypeOfField($field), $class . '::$' . $field);
            }
        }

        // Identifiers and device ids stay searchable in plain text
        $this->assertSame('string', $entityManager->getClassMetadata(HoyoverseAccount::class)->getTypeOfField('accountUid'));
        $this->assertSame('string', $entityManager->getClassMetadata(PerfectworldAccount::class)->getTypeOfField('ndid'));
    }

    public function testConversionCommandFindsExactlyTheCredentialColumns(): void
    {
        $entityManager = $this->getEntityManager();
        $accountTables = array_map(
            static fn (string $class): string => $entityManager->getClassMetadata($class)->getTableName(),
            array_keys(self::ENCRYPTED_FIELDS),
        );

        // Other bundles may add encrypted columns: only the game account tables are checked here
        $columns = array_filter(
            (new EncryptedColumnLocator($entityManager))->findColumns(),
            static fn (EncryptedColumn $column): bool => in_array($column->table, $accountTables, true),
        );

        $this->assertSame([
            'gryphline_account.account_token.id',
            'gryphline_account.session_cred.id',
            'gryphline_account.session_salt.id',
            'hoyoverse_account.cookie.id',
            'perfectworld_account.token.id',
        ], array_values(array_map(static fn (EncryptedColumn $column): string => $column->table . '.' . $column->column . '.' . $column->idColumn, $columns)));
    }
}
