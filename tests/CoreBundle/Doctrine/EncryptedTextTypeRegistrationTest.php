<?php

namespace App\Tests\CoreBundle\Doctrine;

use App\CoreBundle\Doctrine\EncryptedTextType;
use App\CoreBundle\Service\Encryption\DatabaseCipher;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Types\Type;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Boots the real kernel without a database connection and without DATABASE_ENCRYPTION_KEY: the app starts,
 * encryption is disabled and the encrypted_text DBAL type is registered.
 */
class EncryptedTextTypeRegistrationTest extends KernelTestCase
{
    public function testKernelBootsWithoutKeyAndValuesStayInPlainText(): void
    {
        $key = $_SERVER['DATABASE_ENCRYPTION_KEY'] ?? $_ENV['DATABASE_ENCRYPTION_KEY'] ?? getenv('DATABASE_ENCRYPTION_KEY');
        if (is_string($key) && $key !== '') {
            $this->markTestSkipped('DATABASE_ENCRYPTION_KEY is set in this environment.');
        }

        self::bootKernel();
        $cipher = static::getContainer()->get(DatabaseCipher::class);

        $this->assertInstanceOf(DatabaseCipher::class, $cipher);
        $this->assertFalse($cipher->isEnabled());
        // The type reaches the container cipher through the resolver set in CoreBundle::boot()
        $this->assertSame('ltoken_v2=plain', (new EncryptedTextType())->convertToDatabaseValue('ltoken_v2=plain', new MySQL80Platform()));
    }

    public function testEncryptedTypeIsRegistered(): void
    {
        self::bootKernel();
        // Creating the connection is what registers doctrine.dbal.types (no database access)
        static::getContainer()->get('doctrine.dbal.default_connection');

        $this->assertTrue(Type::hasType(EncryptedTextType::NAME));
        $this->assertInstanceOf(EncryptedTextType::class, Type::getType(EncryptedTextType::NAME));
    }
}
