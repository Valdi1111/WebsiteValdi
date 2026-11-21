<?php

namespace App\CoreBundle\Command;

use App\CoreBundle\Doctrine\EncryptedTextType;
use App\CoreBundle\Exception\DatabaseEncryptionException;
use App\CoreBundle\Model\Encryption\EncryptedColumn;
use App\CoreBundle\Model\Encryption\EncryptedColumnStats;
use App\CoreBundle\Service\Encryption\DatabaseCipher;
use App\CoreBundle\Service\Encryption\EncryptedColumnConverter;
use App\CoreBundle\Service\Encryption\EncryptedColumnLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Converts the values of every column mapped with EncryptedTextType to the encrypted format, or back to plain text
 * with --decrypt. Safe to run again. Prints counts only, never values.
 */
#[AsCommand(name: 'core:database:encrypt', description: 'Encrypt the values of the encrypted columns with DATABASE_ENCRYPTION_KEY, or decrypt them with --decrypt')]
class DatabaseEncryptCommand extends Command
{
    public function __construct(
        private readonly EncryptedColumnLocator $columnLocator,
        private readonly EncryptedColumnConverter $converter,
        private readonly DatabaseCipher $cipher,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Decrypt the encrypted values back to plain text (way back, before removing the key)')]
        bool $decrypt = false,
        #[Option('Only count plain and encrypted values per column, write nothing', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        $columns = $this->columnLocator->findColumns();
        if ($columns === []) {
            $io->warning(sprintf('No column is mapped with the "%s" type.', EncryptedTextType::NAME));

            return Command::SUCCESS;
        }

        try {
            $this->cipher->assertUsable();
            $keyError = null;
        } catch (DatabaseEncryptionException $e) {
            $keyError = $e->getMessage();
        }

        // A malformed key always stops here; without a key a dry run still counts the values
        if ($keyError !== null && (!$dryRun || $this->cipher->isEnabled())) {
            $io->error($keyError . ' Nothing was written.');

            return Command::FAILURE;
        }

        $stats = array_map($this->converter->scan(...), $columns);
        $unreadable = array_sum(array_map(static fn (EncryptedColumnStats $stat): int => $stat->unreadable ?? 0, $stats));

        if ($dryRun || $unreadable > 0) {
            $this->renderStats($io, $stats, false);
        }

        if ($unreadable > 0) {
            $io->error(sprintf('%d encrypted value(s) cannot be decrypted with the configured key (wrong key, altered data, or a plain value starting with "%s"). Nothing was written.', $unreadable, DatabaseCipher::PREFIX));

            return Command::FAILURE;
        }

        if ($dryRun) {
            if ($keyError !== null) {
                $io->error($keyError . ' Values were only counted.');

                return Command::FAILURE;
            }

            $pending = array_sum(array_map(static fn (EncryptedColumnStats $stat): int => $decrypt ? $stat->encrypted : $stat->plaintext, $stats));
            $io->success(sprintf('Dry run: %d value(s) would be %s. Nothing was written.', $pending, $decrypt ? 'decrypted' : 'encrypted'));

            return Command::SUCCESS;
        }

        $results = array_map(fn (EncryptedColumn $column): EncryptedColumnStats => $this->converter->convert($column, $decrypt), $columns);
        $this->renderStats($io, $results, true, $decrypt);

        $unreadable = array_sum(array_map(static fn (EncryptedColumnStats $stat): int => $stat->unreadable ?? 0, $results));
        $keptEncrypted = array_sum(array_map(static fn (EncryptedColumnStats $stat): int => $stat->keptEncrypted, $results));
        $skipped = array_sum(array_map(static fn (EncryptedColumnStats $stat): int => $stat->skipped, $results));
        $converted = array_sum(array_map(static fn (EncryptedColumnStats $stat): int => $stat->converted, $results));

        if ($skipped > 0) {
            $io->warning(sprintf('%d row(s) changed while being converted and were left as they are: run the command again.', $skipped));
        }

        $failures = [];
        if ($unreadable > 0) {
            $failures[] = sprintf('%d value(s) could not be decrypted and were left as they are.', $unreadable);
        }
        if ($keptEncrypted > 0) {
            $failures[] = sprintf('%d value(s) were left encrypted: their plain text starts with "%s", so in plain text they would be taken for encrypted values and could not be read without a key. Replace those values before removing the key.', $keptEncrypted, DatabaseCipher::PREFIX);
        }
        if ($failures !== []) {
            $io->error([...$failures, sprintf('%d value(s) %s.', $converted, $decrypt ? 'decrypted' : 'encrypted')]);

            return Command::FAILURE;
        }

        $io->success(sprintf('%d value(s) %s. Check the result with --dry-run.', $converted, $decrypt ? 'decrypted' : 'encrypted'));

        return Command::SUCCESS;
    }

    /**
     * @param list<EncryptedColumnStats> $stats
     */
    private function renderStats(SymfonyStyle $io, array $stats, bool $withResults, bool $decrypt = false): void
    {
        $headers = $withResults
            ? ['Table', 'Column', 'Plain text (before)', 'Encrypted (before)', 'Unreadable', 'Converted', 'Skipped (changed meanwhile)']
            : ['Table', 'Column', 'Plain text', 'Encrypted', 'Unreadable'];
        if ($withResults && $decrypt) {
            $headers[] = 'Kept encrypted';
        }

        $rows = [];
        foreach ($stats as $stat) {
            $row = [
                $stat->column->table,
                $stat->column->column,
                (string) $stat->plaintext,
                (string) $stat->encrypted,
                $stat->unreadable !== null ? (string) $stat->unreadable : '-',
            ];
            if ($withResults) {
                $row[] = (string) $stat->converted;
                $row[] = (string) $stat->skipped;
            }
            if ($withResults && $decrypt) {
                $row[] = (string) $stat->keptEncrypted;
            }
            $rows[] = $row;
        }

        $io->table($headers, $rows);
    }
}
