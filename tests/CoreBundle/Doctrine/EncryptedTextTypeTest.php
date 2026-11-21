<?php

namespace App\Tests\CoreBundle\Doctrine;

use App\CoreBundle\CoreBundle;
use App\CoreBundle\Doctrine\EncryptedTextType;
use App\CoreBundle\Exception\DatabaseEncryptionException;
use App\CoreBundle\Service\Encryption\DatabaseCipher;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Types\TextType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;

/**
 * Behaviour of encrypted columns for each key state: merging the type must change nothing without a key,
 * and a value that cannot be decrypted must never reach the entity.
 */
class EncryptedTextTypeTest extends TestCase
{
    private const string COOKIE = 'ltuid_v2=12345678; ltoken_v2=v2_secret-token';

    private EncryptedTextType $type;
    private MySQL80Platform $platform;

    protected function setUp(): void
    {
        $this->type = new EncryptedTextType();
        $this->platform = new MySQL80Platform();
    }

    protected function tearDown(): void
    {
        // The resolver is static: never leak a key into other tests
        EncryptedTextType::setCipherResolver(null);
    }

    private static function key(string $byte = "\x01"): string
    {
        return base64_encode(str_repeat($byte, 32));
    }

    private function useKey(?string $key): void
    {
        $cipher = new DatabaseCipher($key);
        EncryptedTextType::setCipherResolver(static fn (): DatabaseCipher => $cipher);
    }

    private function encryptedWith(string $key, string $plaintext = self::COOKIE): string
    {
        return (new DatabaseCipher($key))->encrypt($plaintext);
    }

    public function testSqlDeclarationIsTheTextOne(): void
    {
        $column = ['name' => 'cookie'];

        $this->assertSame((new TextType())->getSQLDeclaration($column, $this->platform), $this->type->getSQLDeclaration($column, $this->platform));
        $this->assertSame('LONGTEXT', $this->type->getSQLDeclaration($column, $this->platform));
    }

    // --- No key: same behaviour as Types::TEXT ---

    /**
     * @return iterable<string, array{?string}>
     */
    public static function missingKeyProvider(): iterable
    {
        yield 'null key' => [null];
        yield 'empty key' => [''];
    }

    #[DataProvider('missingKeyProvider')]
    public function testWithoutKeyValuesAreWrittenInPlainText(?string $key): void
    {
        $this->useKey($key);

        $this->assertSame(self::COOKIE, $this->type->convertToDatabaseValue(self::COOKIE, $this->platform));
    }

    #[DataProvider('missingKeyProvider')]
    public function testWithoutKeyPlainValuesAreRead(?string $key): void
    {
        $this->useKey($key);

        $this->assertSame(self::COOKIE, $this->type->convertToPHPValue(self::COOKIE, $this->platform));
    }

    #[DataProvider('missingKeyProvider')]
    public function testWithoutKeyEncryptedValuesFailClosed(?string $key): void
    {
        $this->useKey($key);

        $this->expectException(DatabaseEncryptionException::class);
        $this->expectExceptionMessage('No database encryption key is configured');
        $this->type->convertToPHPValue($this->encryptedWith(self::key()), $this->platform);
    }

    #[DataProvider('missingKeyProvider')]
    public function testWithoutKeyPlainValueWithTheEncryptedPrefixIsRefused(?string $key): void
    {
        // Stored as is, it would be read back as an encrypted value and throw on every read
        $this->useKey($key);

        $this->expectException(DatabaseEncryptionException::class);
        $this->expectExceptionMessage('starting with "enc:v1:" cannot be stored');
        $this->type->convertToDatabaseValue('enc:v1:pasted-by-a-user', $this->platform);
    }

    // --- Valid key ---

    public function testWithKeyValuesAreWrittenEncryptedAndReadBack(): void
    {
        $this->useKey(self::key());

        $stored = $this->type->convertToDatabaseValue(self::COOKIE, $this->platform);

        $this->assertIsString($stored);
        $this->assertTrue(DatabaseCipher::isEncrypted($stored));
        $this->assertStringNotContainsString('v2_secret-token', $stored);
        $this->assertSame(self::COOKIE, $this->type->convertToPHPValue($stored, $this->platform));
    }

    public function testWithKeyPlainValueWithTheEncryptedPrefixIsEncryptedAndReadBack(): void
    {
        $this->useKey(self::key());

        $stored = $this->type->convertToDatabaseValue('enc:v1:pasted-by-a-user', $this->platform);

        $this->assertNotSame('enc:v1:pasted-by-a-user', $stored);
        $this->assertSame('enc:v1:pasted-by-a-user', $this->type->convertToPHPValue($stored, $this->platform));
    }

    public function testWithKeyPlainValuesNotConvertedYetAreRead(): void
    {
        $this->useKey(self::key());

        $this->assertSame(self::COOKIE, $this->type->convertToPHPValue(self::COOKIE, $this->platform));
    }

    public function testValuesWrittenWithoutKeyAreReadAfterEnablingIt(): void
    {
        $this->useKey(null);
        $stored = $this->type->convertToDatabaseValue(self::COOKIE, $this->platform);

        $this->useKey(self::key());
        $this->assertSame(self::COOKIE, $this->type->convertToPHPValue($stored, $this->platform));
    }

    // --- Wrong key, altered data, malformed key ---

    public function testWithWrongKeyEncryptedValuesFailClosed(): void
    {
        $this->useKey(self::key("\x02"));

        $this->expectException(DatabaseEncryptionException::class);
        $this->expectExceptionMessage('wrong encryption key or altered value');
        $this->type->convertToPHPValue($this->encryptedWith(self::key("\x01")), $this->platform);
    }

    public function testWithWrongKeyPlainValuesAreStillRead(): void
    {
        $this->useKey(self::key("\x02"));

        $this->assertSame(self::COOKIE, $this->type->convertToPHPValue(self::COOKIE, $this->platform));
    }

    public function testAlteredValueFailsClosed(): void
    {
        $this->useKey(self::key());
        $stored = $this->encryptedWith(self::key());
        $raw = base64_decode(substr($stored, strlen(DatabaseCipher::PREFIX)), true);
        $raw[-1] = chr(ord($raw[-1]) ^ 0x01);

        $this->expectException(DatabaseEncryptionException::class);
        $this->type->convertToPHPValue(DatabaseCipher::PREFIX . base64_encode($raw), $this->platform);
    }

    public function testMalformedKeyFailsOnWriteAndOnEncryptedReadButPlainValuesAreRead(): void
    {
        $this->useKey('not-a-valid-key');

        $this->assertSame(self::COOKIE, $this->type->convertToPHPValue(self::COOKIE, $this->platform));

        try {
            $this->type->convertToDatabaseValue(self::COOKIE, $this->platform);
            $this->fail('A malformed key must not store the value in plain text.');
        } catch (DatabaseEncryptionException $e) {
            $this->assertStringContainsString('exactly 32 bytes', $e->getMessage());
        }

        $this->expectException(DatabaseEncryptionException::class);
        $this->expectExceptionMessage('exactly 32 bytes');
        $this->type->convertToPHPValue($this->encryptedWith(self::key()), $this->platform);
    }

    // --- null and empty string ---

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function nullAndEmptyProvider(): iterable
    {
        foreach (['no key' => null, 'valid key' => self::key(), 'malformed key' => 'not-a-valid-key'] as $label => $key) {
            yield 'null, ' . $label => [$key, null];
            yield 'empty string, ' . $label => [$key, ''];
        }
    }

    #[DataProvider('nullAndEmptyProvider')]
    public function testNullAndEmptyStringAreStoredAndReadUnchanged(?string $key, ?string $value): void
    {
        $this->useKey($key);

        $this->assertSame($value, $this->type->convertToDatabaseValue($value, $this->platform));
        $this->assertSame($value, $this->type->convertToPHPValue($value, $this->platform));
    }

    // --- Outside the kernel ---

    public function testWithoutResolverTheTypeBehavesAsWithoutKey(): void
    {
        EncryptedTextType::setCipherResolver(null);

        $this->assertSame(self::COOKIE, $this->type->convertToDatabaseValue(self::COOKIE, $this->platform));
        $this->assertSame(self::COOKIE, $this->type->convertToPHPValue(self::COOKIE, $this->platform));

        $this->expectException(DatabaseEncryptionException::class);
        $this->type->convertToPHPValue($this->encryptedWith(self::key()), $this->platform);
    }

    public function testWithoutResolverPlainValueWithTheEncryptedPrefixIsRefused(): void
    {
        EncryptedTextType::setCipherResolver(null);

        $this->expectException(DatabaseEncryptionException::class);
        $this->expectExceptionMessage('starting with "enc:v1:" cannot be stored');
        $this->type->convertToDatabaseValue('enc:v1:pasted-by-a-user', $this->platform);
    }

    public function testPlainValuesDoNotNeedTheCipher(): void
    {
        $calls = 0;
        EncryptedTextType::setCipherResolver(static function () use (&$calls): DatabaseCipher {
            ++$calls;

            return new DatabaseCipher(self::key());
        });

        $this->type->convertToPHPValue(self::COOKIE, $this->platform);
        $this->type->convertToPHPValue(null, $this->platform);
        $this->type->convertToDatabaseValue(null, $this->platform);
        $this->type->convertToDatabaseValue('', $this->platform);

        $this->assertSame(0, $calls);
    }

    // --- Resolver lifecycle (CoreBundle::boot() / shutdown()) ---

    public function testClearCipherResolverOnlyRemovesTheExpectedResolver(): void
    {
        $current = static fn (): DatabaseCipher => new DatabaseCipher(self::key());
        EncryptedTextType::setCipherResolver($current);

        EncryptedTextType::clearCipherResolver(static fn (): DatabaseCipher => new DatabaseCipher(self::key()));
        $this->assertTrue(DatabaseCipher::isEncrypted($this->type->convertToDatabaseValue(self::COOKIE, $this->platform)));

        EncryptedTextType::clearCipherResolver($current);
        $this->assertSame(self::COOKIE, $this->type->convertToDatabaseValue(self::COOKIE, $this->platform));
    }

    public function testShuttingDownABundleKeepsTheResolverOfABundleBootedLater(): void
    {
        // Two kernels in the same process: the second boot replaced the resolver of the first one
        $first = self::bootBundle(self::key("\x01"));
        $second = self::bootBundle(self::key("\x02"));

        $first->shutdown();
        $stored = $this->type->convertToDatabaseValue(self::COOKIE, $this->platform);
        $this->assertSame(self::COOKIE, (new DatabaseCipher(self::key("\x02")))->decrypt($stored));

        $second->shutdown();
        $this->assertSame(self::COOKIE, $this->type->convertToDatabaseValue(self::COOKIE, $this->platform));
    }

    private static function bootBundle(string $key): CoreBundle
    {
        $container = new Container();
        $container->set(DatabaseCipher::class, new DatabaseCipher($key));

        $bundle = new CoreBundle();
        $bundle->setContainer($container);
        $bundle->boot();

        return $bundle;
    }
}
