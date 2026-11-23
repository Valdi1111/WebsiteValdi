<?php

namespace App\Tests\CoreBundle\Doctrine;

use App\CoreBundle\Doctrine\EncryptedTextType;
use App\CoreBundle\Entity\Token;
use App\CoreBundle\Entity\User;
use App\CoreBundle\Model\Encryption\EncryptedColumn;
use App\CoreBundle\Service\Encryption\EncryptedColumnLocator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Boots the real kernel without a database connection (metadata only): the value of an API token is encrypted at
 * rest and converted by core:database:encrypt, its hash stays plain, unique and searchable.
 */
class TokenMappingTest extends KernelTestCase
{
    private function getEntityManager(): EntityManagerInterface
    {
        self::bootKernel();

        return static::getContainer()->get('doctrine')->getManager();
    }

    public function testTheValueIsEncryptedAndTheHashIsAUniquePlainColumn(): void
    {
        $metadata = $this->getEntityManager()->getClassMetadata(Token::class);

        $this->assertSame(EncryptedTextType::NAME, $metadata->getTypeOfField('accessToken'));
        $this->assertSame('string', $metadata->getTypeOfField('tokenHash'));
        $this->assertSame(['columns' => ['token_hash']], $metadata->table['uniqueConstraints']['IDX_token_hash']);
    }

    public function testConversionCommandFindsTheValueColumn(): void
    {
        $entityManager = $this->getEntityManager();

        $columns = array_filter(
            (new EncryptedColumnLocator($entityManager))->findColumns(),
            static fn (EncryptedColumn $column): bool => $column->table === 'token',
        );

        $this->assertSame(['token.access_token.id'], array_values(array_map(
            static fn (EncryptedColumn $column): string => $column->table.'.'.$column->column.'.'.$column->idColumn,
            $columns,
        )));
    }

    public function testANewTokenHasARandomValueAndItsHash(): void
    {
        $user = (new User())->setEmail('me@example.test');

        $first = Token::createFor($user, 'First');
        $second = Token::createFor($user, 'Second');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $first->getAccessToken());
        $this->assertNotSame($first->getAccessToken(), $second->getAccessToken());
        $this->assertSame(hash('sha256', (string) $first->getAccessToken()), $first->getTokenHash());
        $this->assertSame([User::class, 'me@example.test', 'First', true], [$first->getClass(), $first->getUsername(), $first->getDescription(), $first->isValid()]);
    }
}
