# Encrypted Columns (`encrypted_text`)

`CoreBundle` provides a Doctrine column type that stores secrets (cookies, tokens) encrypted at rest, and a console command that converts the existing values. Any bundle can use it by mapping a field with the type. Today it holds the game account credentials (list in the [GamesBundle guide](../../../../GamesBundle/README.md#credentials-encryption-at-rest)).

Without `DATABASE_ENCRYPTION_KEY` (the default, also in the `test` environment) values are stored and read in plain text as with `Types::TEXT`, with one difference: a value starting with `enc:v1:` is refused on write (`DatabaseEncryptionException`), since it would be read back as an encrypted value. Enabling, converting and going back on a server: [`SERVER_SETUP_AND_DEPLOY.md` §3.4](../../../../../docs/SERVER_SETUP_AND_DEPLOY.md#34-database-encryption-optional).

| Class | Role |
| :--- | :--- |
| `Doctrine/EncryptedTextType` | DBAL type `encrypted_text` (`EncryptedTextType::NAME`), registered in `config/packages/doctrine.yaml` of the bundle |
| `Service/Encryption/DatabaseCipher` | AES-256-GCM cipher, key from the `core.database_encryption_key` parameter |
| `Service/Encryption/EncryptedColumnLocator` | lists every field mapped with the type, in any entity |
| `Service/Encryption/EncryptedColumnConverter` | counts and converts the raw values of one column |
| `Command/DatabaseEncryptCommand` | `core:database:encrypt` (`--dry-run`, `--decrypt`) |
| `Model/Encryption/{EncryptedColumn, EncryptedColumnStats}` | column reference and counts |
| `Exception/DatabaseEncryptionException` | every failure; messages never contain values |

---

## 1. Encrypting a column

```php
use App\CoreBundle\Doctrine\EncryptedTextType;

#[ORM\Column(type: EncryptedTextType::NAME, nullable: true)]
private ?string $apiToken = null;
```

- The SQL declaration is the one of `Types::TEXT` (`LONGTEXT` on MySQL): a column that is already `TEXT` needs no migration. Any other column (e.g. `VARCHAR(255)`) needs one to become `LONGTEXT`, since encrypted values are longer (example: `migrations/Version20261121183000.php`).
- The entity always sees the plain value; nothing else changes in the code.
- The same value encrypts differently each time: an encrypted column cannot be used in `WHERE`, indexes, unique constraints or deduplication. Keep a plain identifier next to it for lookups.
- `core:database:encrypt` finds the new column by itself from the Doctrine metadata.

---

## 2. Key and format

- **Key**: `DATABASE_ENCRYPTION_KEY`, the base64 encoding of 32 random bytes (`openssl rand -base64 32`), from the secrets vault (`bin/console secrets:set DATABASE_ENCRYPTION_KEY --env=prod`, as for `PASSWORDS_ENCRYPTION_KEY`) or, as an alternative, from `.env.local`, read at runtime:
  ```yaml
  # config/packages/core.yaml
  core:
      database_encryption_key: '%env(default::DATABASE_ENCRYPTION_KEY)%'
  ```
- **Format**: `enc:v1:` + base64(nonce (12 bytes) | tag (16 bytes) | ciphertext), AES-256-GCM with a random nonce per value and the constant AAD `core.encrypted_text.v1`.

| Key | Writing | Reading a plain value | Reading an `enc:v1:` value |
| :--- | :--- | :--- | :--- |
| not set or empty | plain text (as `TEXT`); refused if it starts with `enc:v1:` | returned | exception |
| valid | encrypted | returned (not converted yet) | decrypted |
| valid but not the one used to encrypt, or value altered | encrypted with the current key | returned | exception |
| malformed (not 32 bytes of base64) | exception | returned | exception |

- `null` and empty strings are stored unchanged.
- A value that cannot be decrypted is never returned: `DatabaseEncryptionException` makes the request or the message handler fail instead ("fails closed"). An operation that loads many rows fails as a whole on a single unreadable one.
- A malformed key is reported only when a value is encrypted or an encrypted one is read, so code that does not touch encrypted columns keeps working.

---

## 3. Converting existing values (`core:database:encrypt`)

```bash
php bin/console core:database:encrypt --dry-run   # per table and column: plain, encrypted, unreadable; writes nothing
php bin/console core:database:encrypt             # encrypts the values still in plain text
php bin/console core:database:encrypt --decrypt   # way back: decrypts them (before removing the key)
```

- Columns come from the Doctrine mapping (every field of type `encrypted_text`); values are read and written with SQL on the raw column, so the type does not decrypt them on the way.
- A row is updated only if it still holds exactly the value read (binary comparison, MySQL syntax): rows changed meanwhile by a worker are left alone and reported as skipped.
- Nothing is written without a valid key, or when an encrypted value does not decrypt with the configured key (wrong key). `--dry-run` also counts without a key, then ends with an error.
- With `--decrypt`, values whose plain text starts with `enc:v1:` stay encrypted and are reported: stored in plain text they could not be read back.
- Running it again changes nothing; only counts are printed, never values. Run it with `APP_ENV=prod`: in debug environments the SQL log records query parameters.
- It is not a migration on purpose: the deploy runs migrations while the old workers are still running.

---

## 4. Key custody and limits

- Keep a copy of the key outside the server and apart from the database backups: a backup together with its key gives the values away, and without the key the encrypted values cannot be recovered.
- There is no key rotation: go back with the old key, then enable the new one.

**Not covered** (stored in plain text, or protected by other means):
- `CoreBundle` `Token.accessToken` (unique and looked up by value: it would need a hash, not encryption);
- `PasswordsBundle`, which has its own `EncryptionService` and `PASSWORDS_ENCRYPTION_KEY` (its columns could now adopt `encrypted_text`);
- `authkey` (HoYoverse) and `u8Token` (Gryphline) in the payload of `SyncPullsPageProfileMessage`, stored in the Messenger table while queued;
- the multi-step login cache (`GamesBundle` `AuthSessionStore`: the temporary password is encrypted with a key derived from `kernel.secret`, the rest of the session data is not);
- identifiers and device ids (e.g. `account_uid`, `pw_uid`, `device_id`, `ndid`, `email` of the game accounts);
- backups and database dumps taken while the values were in plain text, and the SQL query log of debug environments.

---

## 5. Design notes

| Decision | Reason |
| :--- | :--- |
| A DBAL type | Entities and services keep handling plain strings; without a key nothing is encrypted. |
| Conversion by a command, not a migration | Plain values stay readable once the key is set, so encryption can be enabled first and the data converted afterwards, and the command can also go back. The deploy script runs migrations while the old workers are still up. |
| Cipher reached through a resolver set in `CoreBundle::boot()` | DBAL types are not services. The resolver is lazy (the key is validated on first use) and `shutdown()` removes it only if no kernel booted later replaced it. |
| Prefix `enc:v1:` | Tells encrypted values from the plain values not converted yet, and leaves room for a future format. |

---

## 6. Testing

- No database needed: `tests/CoreBundle/Service/Encryption/DatabaseCipherTest`, `tests/CoreBundle/Doctrine/EncryptedTextTypeTest` (every key state, `null`, empty string, `enc:v1:` plain values, no resolver, resolver lifecycle) and `tests/CoreBundle/Command/DatabaseEncryptCommandTest` (dry run, encrypt, decrypt, second run, row changed meanwhile, values kept encrypted by `--decrypt`, missing/malformed/wrong key, no value printed, on an in-memory table).
- Kernel, without a connection (the platform comes from the `serverVersion` of `DATABASE_URL`): `EncryptedTextTypeRegistrationTest` (boots without a key, type registered) and `EncryptedTextKeyWiringTest` (sets `DATABASE_ENCRYPTION_KEY` before booting and checks that the registered type encrypts with it). `tests/GamesBundle/Doctrine/EncryptedCredentialMappingTest` checks the game account columns.
