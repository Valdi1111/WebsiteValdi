<?php

namespace App\Tests\CoreBundle\Command;

use App\CoreBundle\Command\DatabaseEncryptCommand;
use App\CoreBundle\Exception\DatabaseEncryptionException;
use App\CoreBundle\Model\Encryption\EncryptedColumn;
use App\CoreBundle\Service\Encryption\DatabaseCipher;
use App\CoreBundle\Service\Encryption\EncryptedColumnConverter;
use App\CoreBundle\Service\Encryption\EncryptedColumnLocator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * core:database:encrypt and EncryptedColumnConverter on an in-memory table, so no database is needed: the
 * connection stub answers the SELECT and the conditional UPDATE, comparing values exactly like the binary
 * comparison of the real UPDATE.
 */
class DatabaseEncryptCommandTest extends TestCase
{
    private const string COOKIE_1 = 'ltuid_v2=1; ltoken_v2=v2_secret-one';
    private const string COOKIE_2 = 'ltuid_v2=2; ltoken_v2=v2_secret-two';
    private const string SESSION_CRED = 'session-cred-secret';

    /** @var array<string, array<int, string|null>> "table.column" => [id => stored value] */
    private array $tables = [];

    /** @var list<string> */
    private array $updates = [];

    private int $selects = 0;

    /** @var (\Closure(string, mixed): void)|null Runs before each UPDATE ("table.column", id), to simulate a worker writing meanwhile */
    private ?\Closure $beforeUpdate = null;

    protected function setUp(): void
    {
        $this->tables = [
            'hoyoverse_account.cookie' => [1 => self::COOKIE_1, 2 => self::COOKIE_2, 3 => ''],
            'gryphline_account.session_cred' => [1 => self::SESSION_CRED, 2 => null],
        ];
        $this->updates = [];
        $this->selects = 0;
        $this->beforeUpdate = null;
    }

    private static function key(string $byte = "\x01"): string
    {
        return base64_encode(str_repeat($byte, 32));
    }

    /**
     * @return list<EncryptedColumn>
     */
    private static function columns(): array
    {
        return [
            new EncryptedColumn('gryphline_account', 'session_cred', 'id'),
            new EncryptedColumn('hoyoverse_account', 'cookie', 'id'),
        ];
    }

    private function createConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());

        $connection->method('fetchAllNumeric')->willReturnCallback(function (string $sql): array {
            $this->assertMatchesRegularExpression('/^SELECT `id`, `(\w+)` FROM `(\w+)` WHERE `\1` IS NOT NULL ORDER BY `id`$/', $sql);
            preg_match('/^SELECT `id`, `(\w+)` FROM `(\w+)`/', $sql, $matches);
            ++$this->selects;

            $rows = [];
            foreach ($this->tables[$matches[2] . '.' . $matches[1]] as $id => $value) {
                if ($value !== null) {
                    $rows[] = [$id, $value];
                }
            }

            return $rows;
        });

        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []): int {
            $this->assertMatchesRegularExpression('/^UPDATE `(\w+)` SET `(\w+)` = \? WHERE `id` = \? AND CAST\(`\2` AS BINARY\) = CAST\(\? AS BINARY\)$/', $sql);
            preg_match('/^UPDATE `(\w+)` SET `(\w+)`/', $sql, $matches);
            $this->updates[] = $sql;

            [$newValue, $id, $expected] = $params;
            $name = $matches[1] . '.' . $matches[2];
            if ($this->beforeUpdate !== null) {
                ($this->beforeUpdate)($name, $id);
            }
            if (($this->tables[$name][$id] ?? null) !== $expected) {
                return 0;
            }
            $this->tables[$name][$id] = $newValue;

            return 1;
        });

        return $connection;
    }

    private function createTester(?string $key): CommandTester
    {
        $locator = $this->createStub(EncryptedColumnLocator::class);
        $locator->method('findColumns')->willReturn(self::columns());
        $cipher = new DatabaseCipher($key);

        return new CommandTester(new DatabaseEncryptCommand($locator, new EncryptedColumnConverter($this->createConnection(), $cipher), $cipher));
    }

    /**
     * Display with whitespace collapsed (SymfonyStyle wraps long messages).
     */
    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private static function assertNoValueIsPrinted(string $display): void
    {
        foreach (['v2_secret-one', 'v2_secret-two', self::SESSION_CRED, 'pasted-by-a-user'] as $secret) {
            self::assertStringNotContainsString($secret, $display);
        }
        // Messages may name the prefix, never an encrypted value
        self::assertDoesNotMatchRegularExpression('#enc:v1:[A-Za-z0-9+/=]#', $display);
    }

    private function encryptAllWith(string $key): void
    {
        $cipher = new DatabaseCipher($key);
        foreach ($this->tables as $name => $rows) {
            foreach ($rows as $id => $value) {
                if ($value !== null && $value !== '') {
                    $this->tables[$name][$id] = $cipher->encrypt($value);
                }
            }
        }
    }

    // --- Command ---

    public function testDryRunCountsPerColumnAndWritesNothing(): void
    {
        $before = $this->tables;
        $tester = $this->createTester(self::key());

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]), $tester->getDisplay());

        $display = self::display($tester);
        $this->assertStringContainsString('gryphline_account session_cred 1 0 0', $display);
        $this->assertStringContainsString('hoyoverse_account cookie 2 0 0', $display);
        $this->assertStringContainsString('3 value(s) would be encrypted', $display);
        self::assertNoValueIsPrinted($display);
        $this->assertSame([], $this->updates);
        $this->assertSame($before, $this->tables);
    }

    public function testEncryptConvertsPlainValuesAndKeepsNullAndEmpty(): void
    {
        $tester = $this->createTester(self::key());

        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $display = self::display($tester);
        $this->assertStringContainsString('3 value(s) encrypted', $display);
        self::assertNoValueIsPrinted($display);

        $cipher = new DatabaseCipher(self::key());
        $cookies = $this->tables['hoyoverse_account.cookie'];
        $this->assertTrue(DatabaseCipher::isEncrypted($cookies[1]));
        $this->assertSame(self::COOKIE_1, $cipher->decrypt($cookies[1]));
        $this->assertSame(self::COOKIE_2, $cipher->decrypt($cookies[2]));
        $this->assertSame('', $cookies[3]);
        $this->assertSame(self::SESSION_CRED, $cipher->decrypt($this->tables['gryphline_account.session_cred'][1]));
        $this->assertNull($this->tables['gryphline_account.session_cred'][2]);
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->assertSame(Command::SUCCESS, $this->createTester(self::key())->execute([]));
        $afterFirstRun = $this->tables;
        $this->updates = [];

        $tester = $this->createTester(self::key());
        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $this->assertStringContainsString('0 value(s) encrypted', self::display($tester));
        $this->assertSame([], $this->updates);
        $this->assertSame($afterFirstRun, $this->tables);
    }

    public function testDecryptRestoresPlainValues(): void
    {
        $this->encryptAllWith(self::key());
        $tester = $this->createTester(self::key());

        $this->assertSame(Command::SUCCESS, $tester->execute(['--decrypt' => true]), $tester->getDisplay());

        $display = self::display($tester);
        $this->assertStringContainsString('3 value(s) decrypted', $display);
        self::assertNoValueIsPrinted($display);
        $this->assertSame([1 => self::COOKIE_1, 2 => self::COOKIE_2, 3 => ''], $this->tables['hoyoverse_account.cookie']);
        $this->assertSame([1 => self::SESSION_CRED, 2 => null], $this->tables['gryphline_account.session_cred']);
    }

    public function testDecryptDryRunCountsEncryptedValues(): void
    {
        $this->encryptAllWith(self::key());
        $tester = $this->createTester(self::key());

        $this->assertSame(Command::SUCCESS, $tester->execute(['--decrypt' => true, '--dry-run' => true]), $tester->getDisplay());

        $display = self::display($tester);
        $this->assertStringContainsString('hoyoverse_account cookie 0 2 0', $display);
        $this->assertStringContainsString('3 value(s) would be decrypted', $display);
        $this->assertSame([], $this->updates);
    }

    /**
     * @return iterable<string, array{array<string, bool>}>
     */
    public static function realRunProvider(): iterable
    {
        yield 'encrypt' => [[]];
        yield 'decrypt' => [['--decrypt' => true]];
    }

    /**
     * @param array<string, bool> $input
     */
    #[DataProvider('realRunProvider')]
    public function testMissingKeyFailsWithoutReadingOrWriting(array $input): void
    {
        $before = $this->tables;
        $tester = $this->createTester(null);

        $this->assertSame(Command::FAILURE, $tester->execute($input));

        $this->assertStringContainsString('No database encryption key is configured', self::display($tester));
        $this->assertSame(0, $this->selects);
        $this->assertSame([], $this->updates);
        $this->assertSame($before, $this->tables);
    }

    public function testDryRunWithoutKeyStillCountsButFails(): void
    {
        $tester = $this->createTester('');

        $this->assertSame(Command::FAILURE, $tester->execute(['--dry-run' => true]));

        $display = self::display($tester);
        // Unreadable values cannot be checked without a key
        $this->assertStringContainsString('hoyoverse_account cookie 2 0 -', $display);
        $this->assertStringContainsString('No database encryption key is configured', $display);
        $this->assertSame([], $this->updates);
    }

    /**
     * @return iterable<string, array{array<string, bool>}>
     */
    public static function anyRunProvider(): iterable
    {
        yield 'encrypt' => [[]];
        yield 'decrypt' => [['--decrypt' => true]];
        yield 'dry run' => [['--dry-run' => true]];
    }

    /**
     * @param array<string, bool> $input
     */
    #[DataProvider('anyRunProvider')]
    public function testMalformedKeyFailsWithTheExpectedFormat(array $input): void
    {
        $before = $this->tables;
        $tester = $this->createTester('not-a-valid-key');

        $this->assertSame(Command::FAILURE, $tester->execute($input));

        $display = self::display($tester);
        $this->assertStringContainsString('exactly 32 bytes', $display);
        $this->assertStringNotContainsString('not-a-valid-key', $display);
        $this->assertSame([], $this->updates);
        $this->assertSame($before, $this->tables);
    }

    /**
     * @param array<string, bool> $input
     */
    #[DataProvider('anyRunProvider')]
    public function testWrongKeyIsDetectedBeforeWriting(array $input): void
    {
        $this->encryptAllWith(self::key("\x01"));
        $this->tables['hoyoverse_account.cookie'][4] = 'ltoken_v2=still-plain';
        $before = $this->tables;
        $tester = $this->createTester(self::key("\x02"));

        $this->assertSame(Command::FAILURE, $tester->execute($input));

        $display = self::display($tester);
        $this->assertStringContainsString('hoyoverse_account cookie 1 2 2', $display);
        $this->assertStringContainsString('3 encrypted value(s) cannot be decrypted with the configured key', $display);
        $this->assertStringNotContainsString('still-plain', $display);
        $this->assertSame([], $this->updates);
        $this->assertSame($before, $this->tables);
    }

    public function testNoEncryptedColumnIsAWarning(): void
    {
        $locator = $this->createStub(EncryptedColumnLocator::class);
        $locator->method('findColumns')->willReturn([]);
        $cipher = new DatabaseCipher(self::key());
        $tester = new CommandTester(new DatabaseEncryptCommand($locator, new EncryptedColumnConverter($this->createConnection(), $cipher), $cipher));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('No column is mapped with the "encrypted_text" type', self::display($tester));
    }

    // --- Converter ---

    public function testRowChangedAfterTheReadIsSkippedNotOverwritten(): void
    {
        $converter = new EncryptedColumnConverter($this->createConnection(), new DatabaseCipher(self::key()));
        $this->beforeUpdate = $this->workerRefreshesRow2(...);

        $stats = $converter->convert(new EncryptedColumn('hoyoverse_account', 'cookie', 'id'), false);

        $this->assertSame(2, $stats->plaintext);
        $this->assertSame(1, $stats->converted);
        $this->assertSame(1, $stats->skipped);
        $this->assertTrue(DatabaseCipher::isEncrypted($this->tables['hoyoverse_account.cookie'][1]));
        $this->assertSame('ltuid_v2=2; ltoken_v2=refreshed', $this->tables['hoyoverse_account.cookie'][2]);
        $this->assertCount(2, $this->updates);
    }

    public function testCommandReportsSkippedRows(): void
    {
        $this->beforeUpdate = $this->workerRefreshesRow2(...);
        $tester = $this->createTester(self::key());

        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $display = self::display($tester);
        $this->assertStringContainsString('hoyoverse_account cookie 2 0 - 1 1', $display);
        $this->assertStringContainsString('1 row(s) changed while being converted', $display);
        $this->assertStringContainsString('2 value(s) encrypted', $display);
        self::assertNoValueIsPrinted($display);
    }

    /**
     * A worker stores a refreshed cookie for hoyoverse row 2 after it was read, just before its UPDATE.
     */
    private function workerRefreshesRow2(string $name, mixed $id): void
    {
        if ($name === 'hoyoverse_account.cookie' && $id === 2) {
            $this->tables[$name][2] = 'ltuid_v2=2; ltoken_v2=refreshed';
        }
    }

    public function testDecryptKeepsValuesWhosePlainTextLooksEncrypted(): void
    {
        $cipher = new DatabaseCipher(self::key());
        // Stored while the key was set: the user pasted a "cookie" starting with the prefix
        $doubled = $cipher->encrypt('enc:v1:pasted-by-a-user');
        $this->tables['hoyoverse_account.cookie'] = [1 => $doubled, 2 => $cipher->encrypt(self::COOKIE_2)];

        $stats = (new EncryptedColumnConverter($this->createConnection(), $cipher))->convert(new EncryptedColumn('hoyoverse_account', 'cookie', 'id'), true);

        $this->assertSame(1, $stats->keptEncrypted);
        $this->assertSame(1, $stats->converted);
        $this->assertSame(0, $stats->unreadable);
        $this->assertSame($doubled, $this->tables['hoyoverse_account.cookie'][1]);
        $this->assertSame(self::COOKIE_2, $this->tables['hoyoverse_account.cookie'][2]);
        $this->assertCount(1, $this->updates);
    }

    public function testDecryptCommandFailsAndExplainsValuesKeptEncrypted(): void
    {
        $this->encryptAllWith(self::key());
        $this->tables['hoyoverse_account.cookie'][4] = (new DatabaseCipher(self::key()))->encrypt('enc:v1:pasted-by-a-user');
        $tester = $this->createTester(self::key());

        $this->assertSame(Command::FAILURE, $tester->execute(['--decrypt' => true]));

        $display = self::display($tester);
        $this->assertStringContainsString('hoyoverse_account cookie 0 3 0 2 0 1', $display);
        $this->assertStringContainsString('1 value(s) were left encrypted', $display);
        $this->assertStringContainsString('3 value(s) decrypted', $display);
        self::assertNoValueIsPrinted($display);
        $this->assertTrue(DatabaseCipher::isEncrypted($this->tables['hoyoverse_account.cookie'][4]));
    }

    public function testMixedColumnOnlyConvertsWhatIsLeft(): void
    {
        $cipher = new DatabaseCipher(self::key());
        $alreadyEncrypted = $cipher->encrypt(self::COOKIE_1);
        $this->tables['hoyoverse_account.cookie'][1] = $alreadyEncrypted;
        $converter = new EncryptedColumnConverter($this->createConnection(), $cipher);

        $stats = $converter->convert(new EncryptedColumn('hoyoverse_account', 'cookie', 'id'), false);

        $this->assertSame(1, $stats->plaintext);
        $this->assertSame(1, $stats->encrypted);
        $this->assertSame(1, $stats->converted);
        $this->assertSame($alreadyEncrypted, $this->tables['hoyoverse_account.cookie'][1]);
        $this->assertSame(self::COOKIE_2, $cipher->decrypt($this->tables['hoyoverse_account.cookie'][2]));
    }

    public function testConverterRequiresAKey(): void
    {
        $converter = new EncryptedColumnConverter($this->createConnection(), new DatabaseCipher(null));

        $thrown = false;
        try {
            $converter->convert(new EncryptedColumn('hoyoverse_account', 'cookie', 'id'), true);
        } catch (DatabaseEncryptionException) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Converting without a key must fail.');
        $this->assertSame(0, $this->selects);
        $this->assertSame([], $this->updates);
    }

    public function testScanCountsUnreadableValuesOnlyWithAUsableKey(): void
    {
        $this->encryptAllWith(self::key("\x01"));
        $column = new EncryptedColumn('hoyoverse_account', 'cookie', 'id');

        $withWrongKey = (new EncryptedColumnConverter($this->createConnection(), new DatabaseCipher(self::key("\x02"))))->scan($column);
        $withoutKey = (new EncryptedColumnConverter($this->createConnection(), new DatabaseCipher(null)))->scan($column);
        $withKey = (new EncryptedColumnConverter($this->createConnection(), new DatabaseCipher(self::key("\x01"))))->scan($column);

        $this->assertSame([0, 2, 2], [$withWrongKey->plaintext, $withWrongKey->encrypted, $withWrongKey->unreadable]);
        $this->assertSame([0, 2, null], [$withoutKey->plaintext, $withoutKey->encrypted, $withoutKey->unreadable]);
        $this->assertSame([0, 2, 0], [$withKey->plaintext, $withKey->encrypted, $withKey->unreadable]);
        $this->assertSame([], $this->updates);
    }
}
