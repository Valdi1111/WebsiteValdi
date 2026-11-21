<?php

namespace App\Tests\CoreBundle\Doctrine;

use App\CoreBundle\Doctrine\EncryptedTextType;
use App\CoreBundle\Service\Encryption\DatabaseCipher;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Types\Type;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * With DATABASE_ENCRYPTION_KEY set, the key must travel config → core.database_encryption_key →
 * DatabaseCipher → resolver set in CoreBundle::boot() → registered DBAL type. If a link broke, the app would
 * silently keep writing plain text.
 *
 * The key is read at runtime (%env()%, resolved when the cipher is built), so the cached container needs no rebuild.
 */
class EncryptedTextKeyWiringTest extends KernelTestCase
{
    private const string ENV_NAME = 'DATABASE_ENCRYPTION_KEY';

    /** @var array{server: mixed, env: mixed} */
    private array $previous;

    private static function key(): string
    {
        return base64_encode(str_repeat("\x07", 32));
    }

    protected function setUp(): void
    {
        $this->previous = ['server' => $_SERVER[self::ENV_NAME] ?? null, 'env' => $_ENV[self::ENV_NAME] ?? null];
        $_SERVER[self::ENV_NAME] = $_ENV[self::ENV_NAME] = self::key();
    }

    protected function tearDown(): void
    {
        // Shut the kernel down first, then give the environment back
        parent::tearDown();

        if ($this->previous['server'] === null) {
            unset($_SERVER[self::ENV_NAME]);
        } else {
            $_SERVER[self::ENV_NAME] = $this->previous['server'];
        }

        if ($this->previous['env'] === null) {
            unset($_ENV[self::ENV_NAME]);
        } else {
            $_ENV[self::ENV_NAME] = $this->previous['env'];
        }
    }

    public function testConfiguredKeyReachesTheRegisteredType(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        // Creating the connection registers doctrine.dbal.types (no database access)
        $container->get('doctrine.dbal.default_connection');
        $type = Type::getType(EncryptedTextType::NAME);
        $platform = new MySQL80Platform();

        $stored = $type->convertToDatabaseValue('ltoken_v2=wired', $platform);

        $this->assertTrue($container->get(DatabaseCipher::class)->isEnabled());
        $this->assertIsString($stored);
        $this->assertTrue(DatabaseCipher::isEncrypted($stored));
        $this->assertSame('ltoken_v2=wired', $type->convertToPHPValue($stored, $platform));
        // Encrypted with the configured key, not with another cipher
        $this->assertSame('ltoken_v2=wired', (new DatabaseCipher(self::key()))->decrypt($stored));

        // CoreBundle::shutdown() removes its resolver: no stale cipher once the kernel is gone
        self::ensureKernelShutdown();
        $this->assertSame('ltoken_v2=wired', $type->convertToDatabaseValue('ltoken_v2=wired', $platform));
    }
}
