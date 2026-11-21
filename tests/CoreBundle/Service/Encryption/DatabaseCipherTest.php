<?php

namespace App\Tests\CoreBundle\Service\Encryption;

use App\CoreBundle\Exception\DatabaseEncryptionException;
use App\CoreBundle\Service\Encryption\DatabaseCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DatabaseCipherTest extends TestCase
{
    private const string COOKIE = 'ltuid_v2=12345678; ltoken_v2=v2_secret-token; stoken_v2=v2_stoken==; mid=abc';

    private static function key(string $byte = "\x01"): string
    {
        return base64_encode(str_repeat($byte, 32));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function plaintextProvider(): iterable
    {
        yield 'cookie string' => [self::COOKIE];
        yield 'single character' => ['x'];
        yield 'utf-8' => ['Zenless Zone Zero ゼンレスゾーンゼロ — Ñandú 🎮'];
        yield 'long value' => [str_repeat('ltoken_v2=' . bin2hex(random_bytes(32)) . '; ', 2000)];
        yield 'binary-looking value' => ["\0\x01\xff=;"];
    }

    #[DataProvider('plaintextProvider')]
    public function testRoundTrip(string $plaintext): void
    {
        $cipher = new DatabaseCipher(self::key());

        $encrypted = $cipher->encrypt($plaintext);

        $this->assertTrue(DatabaseCipher::isEncrypted($encrypted));
        $this->assertSame($plaintext, $cipher->decrypt($encrypted));
    }

    public function testFormatIsPrefixPlusBase64OfNonceTagAndCiphertext(): void
    {
        $encrypted = (new DatabaseCipher(self::key()))->encrypt(self::COOKIE);

        $this->assertStringStartsWith(DatabaseCipher::PREFIX, $encrypted);
        // The cookie contains "; ", which base64 never does
        $this->assertStringNotContainsString(self::COOKIE, $encrypted);
        $this->assertStringNotContainsString('v2_secret-token', $encrypted);

        $raw = base64_decode(substr($encrypted, strlen(DatabaseCipher::PREFIX)), true);
        $this->assertIsString($raw);
        $this->assertSame(12 + 16 + strlen(self::COOKIE), strlen($raw));
    }

    public function testSameValueEncryptsDifferentlyEachTime(): void
    {
        $cipher = new DatabaseCipher(self::key());

        $first = $cipher->encrypt(self::COOKIE);
        $second = $cipher->encrypt(self::COOKIE);

        $this->assertNotSame($first, $second);
        $this->assertSame(self::COOKIE, $cipher->decrypt($first));
        $this->assertSame(self::COOKIE, $cipher->decrypt($second));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function tamperedOffsetProvider(): iterable
    {
        yield 'nonce' => [0];
        yield 'tag' => [12];
        yield 'ciphertext' => [28];
        yield 'last ciphertext byte' => [-1];
    }

    #[DataProvider('tamperedOffsetProvider')]
    public function testTamperedValueIsRejected(int $offset): void
    {
        $cipher = new DatabaseCipher(self::key());
        $raw = base64_decode(substr($cipher->encrypt(self::COOKIE), strlen(DatabaseCipher::PREFIX)), true);
        $raw[$offset] = chr(ord($raw[$offset]) ^ 0x01);

        $this->expectException(DatabaseEncryptionException::class);
        $this->expectExceptionMessage('wrong encryption key or altered value');
        $cipher->decrypt(DatabaseCipher::PREFIX . base64_encode($raw));
    }

    public function testWrongKeyIsRejected(): void
    {
        $encrypted = (new DatabaseCipher(self::key("\x01")))->encrypt(self::COOKIE);

        $this->expectException(DatabaseEncryptionException::class);
        $this->expectExceptionMessage('wrong encryption key or altered value');
        (new DatabaseCipher(self::key("\x02")))->decrypt($encrypted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedValueProvider(): iterable
    {
        yield 'not base64' => [DatabaseCipher::PREFIX . '%%%not-base64%%%'];
        yield 'too short for nonce and tag' => [DatabaseCipher::PREFIX . base64_encode(str_repeat('a', 27))];
        yield 'prefix only' => [DatabaseCipher::PREFIX];
    }

    #[DataProvider('malformedValueProvider')]
    public function testMalformedValueIsRejected(string $value): void
    {
        $this->expectException(DatabaseEncryptionException::class);
        $this->expectExceptionMessage('Malformed encrypted value');
        (new DatabaseCipher(self::key()))->decrypt($value);
    }

    public function testPlainValueIsNotDecrypted(): void
    {
        $this->expectException(DatabaseEncryptionException::class);
        $this->expectExceptionMessage('not an encrypted value');
        (new DatabaseCipher(self::key()))->decrypt(self::COOKIE);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedKeyProvider(): iterable
    {
        yield 'not base64' => ['this is not a base64 key!'];
        yield '16 bytes' => [base64_encode(str_repeat("\x01", 16))];
        yield '33 bytes' => [base64_encode(str_repeat("\x01", 33))];
        yield 'raw 32-character string' => [str_repeat('k', 32)];
    }

    #[DataProvider('malformedKeyProvider')]
    public function testMalformedKeyFailsOnUseWithTheExpectedFormat(string $key): void
    {
        // Building the cipher must not throw: only code using encrypted columns fails
        $cipher = new DatabaseCipher($key);
        $this->assertTrue($cipher->isEnabled());

        try {
            $cipher->encrypt(self::COOKIE);
            $this->fail('A malformed key must not encrypt.');
        } catch (DatabaseEncryptionException $e) {
            $this->assertStringContainsString('exactly 32 bytes', $e->getMessage());
            $this->assertStringContainsString('openssl rand -base64 32', $e->getMessage());
            $this->assertStringNotContainsString($key, $e->getMessage());
        }

        $this->expectException(DatabaseEncryptionException::class);
        $this->expectExceptionMessage('exactly 32 bytes');
        $cipher->assertUsable();
    }

    public function testMalformedKeyCannotDecrypt(): void
    {
        $encrypted = (new DatabaseCipher(self::key()))->encrypt(self::COOKIE);

        $this->expectException(DatabaseEncryptionException::class);
        $this->expectExceptionMessage('exactly 32 bytes');
        (new DatabaseCipher('short'))->decrypt($encrypted);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function missingKeyProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
    }

    #[DataProvider('missingKeyProvider')]
    public function testMissingKeyDisablesEncryption(?string $key): void
    {
        $cipher = new DatabaseCipher($key);
        $this->assertFalse($cipher->isEnabled());

        try {
            $cipher->encrypt(self::COOKIE);
            $this->fail('Without a key nothing can be encrypted.');
        } catch (DatabaseEncryptionException $e) {
            $this->assertStringContainsString('DATABASE_ENCRYPTION_KEY', $e->getMessage());
        }

        $encrypted = (new DatabaseCipher(self::key()))->encrypt(self::COOKIE);
        $this->expectException(DatabaseEncryptionException::class);
        $this->expectExceptionMessage('No database encryption key is configured');
        $cipher->decrypt($encrypted);
    }

    public function testDefaultConstructorIsDisabled(): void
    {
        $this->assertFalse((new DatabaseCipher())->isEnabled());
    }

    public function testIsEncryptedChecksThePrefixOnly(): void
    {
        $this->assertTrue(DatabaseCipher::isEncrypted('enc:v1:anything'));
        $this->assertFalse(DatabaseCipher::isEncrypted(self::COOKIE));
        $this->assertFalse(DatabaseCipher::isEncrypted(''));
        $this->assertFalse(DatabaseCipher::isEncrypted('ENC:V1:abc'));
        $this->assertFalse(DatabaseCipher::isEncrypted(' enc:v1:abc'));
    }
}
