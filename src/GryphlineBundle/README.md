# Gryphline Bundle (`GryphlineBundle`) — Feature Guide

The **`GryphlineBundle`** manages full integration with the **Gryphline / SKPort** platform for **Arknights: Endfield** (`game_id = 3`, `game_biz = endfield`).

It supports direct login with Gryphline credentials (including GeeTest v4 captcha handling) or via `ACCOUNT_TOKEN`, automatic OAuth session negotiation with `HMAC-SHA256` cryptographic signing (`vCode`), daily SKPort auto check-in, Stamina (Sanity), dailies and weeklies reminders, automatic pull history synchronization (Headhunting) with real-time Mercure progress tracking, and an extensible pipeline for third-party backup imports.

---

## 1. Feature Overview (`Arknights: Endfield`)

| Feature | Status | Details |
| :--- | :---: | :--- |
| **Credentials Login (Email + Password)** | ✅ | Direct authentication on `as.gryphline.com` with GeeTest v4 support and immediate OAuth setup |
| **`ACCOUNT_TOKEN` Login** | ✅ | Manual token entry (e.g. extracted from the SKPort `ACCOUNT_TOKEN` cookie) |
| **Account Deduplication & Safe Re-login** | ✅ | Keyed by `account_uid` (`hgId`), updates existing accounts and prevents account mismatches on re-login |
| **SKPort Auto Check-In & Missed Check-In** | ✅ | Automated daily SKPort reward claim and afternoon missed check-in reminder |
| **Stamina Reminder** | ✅ | Continuous monitoring (every 30 min) with customizable threshold (max cap: `360`) |
| **Dailies Reminder** | ✅ | Daily check at 21:00 (server local time) for *Daily Activation* (`100/100`) |
| **Weeklies Reminder** | ✅ | Weekly check every Sunday at 21:00 (server local time) for *Weekly Mission* |
| **Automatic Pulls Sync & Live Progress** | ✅ | 1-click and nightly import via OAuth `u8_token` (Character & Weapon Headhunting) with real-time Mercure progress |

### 1.1 Game Service Architecture: Capability Interfaces, Traits & Dynamic Normalization

Each game in the bundle (currently `EndfieldService`, registered in the Service Locator via the `gryphline.game.id` and `gryphline.game.biz` tags) implements `App\GryphlineBundle\Model\Game\GameInterface` and declares its capabilities through **Capability Interfaces** (shared from `GamesBundle` or specific to `GryphlineBundle`).  
Both background **MessageHandlers** and `GryphlineGameProfileNormalizer` inspect implemented interfaces via `instanceof` to enable scheduled jobs and dynamically expose only supported settings to the frontend:

| Interface | Namespace | Associated Trait | Fields / Flags Exposed by Normalizer | Purpose / Enabled Feature |
| :--- | :--- | :--- | :--- | :--- |
| `GameInterface` | `GryphlineBundle` | — | — | Base contract for a Gryphline/SKPort game service (`getGameId()`, `getGameBiz()`, `getGameName()`, `getIcon()`, `ensureCredentials()`). |
| `HasCheckInInterface` | `GryphlineBundle` | `CheckInTrait` | `skportCheckIn`, `skportMissedCheckIn` | The game supports signed daily check-in on SKPort (`/web/v1/game/{biz}/attendance`), enabling **Auto Check-In** and **Missed Check-In Reminder**. |
| `HasNotesInterface` | `GamesBundle` | `NotesTrait` | — | The game exposes the *Real-Time Card Detail* endpoint (`/api/v1/game/{biz}/card/detail`) on SKPort to read live profile data (`EndfieldNotes`). |
| `HasStaminaInterface` | `GamesBundle` | `NotesTrait` | `staminaCheck`, `staminaThreshold` | The game exposes real-time Stamina/Sanity and full recovery timestamp, enabling **Stamina Reminder**. |
| `HasDailiesInterface` | `GamesBundle` | `NotesTrait` | `dailiesCheck` | The game exposes daily progress (*Daily Activation* `100/100`), enabling the regional **Dailies Reminder**. |
| `HasWeekliesInterface` | `GamesBundle` | `NotesTrait` | `weekliesCheck` | The game exposes weekly progress (*Weekly Mission*), enabling the regional **Weeklies Reminder**. |
| `HasAutoPullImportInterface` | `GryphlineBundle` | `GryphlinePullTrait` | `syncPulls`, `supports_pulls: true` | The game supports 100% automatic Gacha/Headhunting history import via OAuth `u8_token`, enabling the **Pulls Tracker** page, **Auto Sync Pulls** toggle, and **Sync Pulls** button. |

---

## 2. Authentication: Credentials Login, Token & SKPort OAuth Flow

Each Gryphline account (`GryphlineAccount`) belongs to a platform user and is uniquely identified by the indexed `account_uid` column (storing Gryphline's official `hgId`).
- **Add Account mode**: if the user logs in with a Gryphline account whose `account_uid` (`hgId`) is already linked to their user profile, the existing account's token and credentials are updated in place without creating duplicates.
- **Re-login mode**: when re-authenticating an existing account, the backend verifies that the returned `hgId` matches `account_uid` (if set), returning `422 Unprocessable Entity` on mismatch.

### 2.1 Direct Credentials Login (Email + Password)
`GryphlineAuthService` communicates with Gryphline's Authentication Server (`https://as.gryphline.com/user/auth/v1/token_by_email_password`):
1. **Device Identification**:
   - Sends device headers `X-DeviceId` (32 hex characters), `X-DeviceName: Gryphline Integration`, `X-DeviceModel: WebsiteValdi`, `X-DeviceType: 7`, `X-OSVer: Linux`.
2. **GeeTest v4 Captcha Handling (`status: 1`)**:
   - If Gryphline requires security verification (e.g. IP reputation or failed attempts), it returns `status: 1` with `data.captcha.geetestId`, `riskType`, and a server `challenge` token.
   - The backend encrypts the password in `AuthSessionStore` (`AES-256-GCM`, 10-minute TTL) and renders the **GeeTest v4** widget on the frontend via the shared `GeetestCaptchaFrame` component.
   - Once solved, `submitCaptcha()` merges the 5 GeeTest v4 parameters (`captcha_id`, `lot_number`, `pass_token`, `gen_time`, `captcha_output`) with the server `challenge` and completes login.
3. **Immediate Finalization**:
   - Once `accountToken` and `hgId` are obtained (`status: 0`), `finalizeAuthenticatedAccount()` immediately runs the SKPort OAuth flow to validate the token and sync game profiles (`GryphlineGameProfile`).

### 2.2 Manual Token Login ("Paste Token")
From the **Paste Token** tab in the Add/Re-login modal, users can manually paste the `ACCOUNT_TOKEN` value extracted from browser cookies on `skport.com`.

### 2.3 3-Step OAuth Flow & `SkportSignerService` Cryptographic Signing
To interact with `zonai.skport.com` APIs, the long-lived `accountToken` is exchanged by `SkportAuthService::performOAuthFlow()` in 3 steps:
1. **User Info Verification**: `GET https://as.gryphline.com/user/info/v1/basic?token=...` → fetches and updates `hgId` (`account_uid`) and `nickname`.
2. **OAuth Grant Code**: `POST https://as.gryphline.com/user/oauth2/v2/grant` (`appCode: 6eb76d4e13aa36e6`, `type: 0`) → obtains an OAuth authorization code.
3. **Session Credentials**: `POST https://zonai.skport.com/web/v1/user/auth/generate_cred_by_code` → returns:
   - `cred` (`session_cred`): SKPort session token (45-minute TTL, stored in DB and refreshed automatically upon expiry).
   - `token` (`session_salt`): secret key used by `SkportSignerService` to compute the `sign` header (`MD5(HMAC-SHA256(path + query/body + timestamp + headersJson, salt))`) required on all authenticated requests to `zonai.skport.com`.

---

## 3. Daily SKPort Auto Check-In

Two scheduled tasks handle daily check-in for *Arknights: Endfield* (`/web/v1/game/endfield/attendance`):

1. **Auto Check-In (`SkportCheckInBatchMessage`)** — Schedule: `0 20 * * *`
   - For each active profile with `skportCheckIn = true`, checks via `GET /web/v1/game/endfield/attendance` whether today's check-in has already been claimed (`hasToday`).
   - If unclaimed, sends a signed `POST /web/v1/game/endfield/attendance` request with header `sk-game-role: 3_{gameUid}_{serverId}`.
   - Resolves claimed rewards (`awardIds` → `resourceInfoMap`) and notifies the user with each reward's name, quantity, and icon.
2. **Missed Check-In Reminder (`SkportMissedCheckInBatchMessage`)** — Schedule: `0 14 * * *`
   - For profiles with `skportMissedCheckIn = true`, checks at 14:00 whether daily check-in is still pending and sends a reminder notification.

---

## 4. Reminders & Real-Time Monitoring (Game Notes)

Via the signed endpoint `GET /api/v1/game/endfield/card/detail`, `EndfieldService` retrieves real-time profile metrics (`EndfieldNotes`) to drive automated reminders:

### 4.1 Stamina Reminder (`StaminaCheckBatchMessage`)
- **Schedule**: `*/30 * * * *` (every 30 minutes).
- **Parameters**:
  - Max stamina cap: `360` (regeneration rate: 1 point every 360 seconds / 6 minutes).
  - Configurable per profile via the `staminaCheck` toggle and `staminaThreshold` (`-1` = notify only at full cap `360/360`).
- **Anti-Spam Logic**: caches the last notified amount until `staminaRecoveryTime`, resetting the lock as soon as the player spends stamina in-game.

### 4.2 Dailies & Weeklies Reminders (`DailiesReminderBatchMessage` / `WeekliesReminderBatchMessage`)
Regional tasks run according to the profile's server timezone (`US_EU` → `Europe/Paris`, `SEA` → `Asia/Shanghai`):
- **Dailies Reminder** — Schedule: `0 21 * * *` (21:00 local server time)
  - Checks **Daily Activation** (`curDailyActivation / maxDailyActivation`, target `100/100`). Sends a reminder if incomplete.
- **Weeklies Reminder** — Schedule: `0 21 * * 0` (every Sunday at 21:00 local server time)
  - Checks **Weekly Mission** (`weeklyScore / weeklyTotal`). Sends a reminder before weekly reset if incomplete.

---

## 5. Automatic Pulls Synchronization (Headhunting Tracker)

Unlike games requiring manual URL extraction from local PC cache files, **Arknights: Endfield** in `GryphlineBundle` supports **100% automatic synchronization** of gacha history directly from the stored `accountToken`, persisting records in `gryphline_pull` (`GryphlinePull`).

### 5.1 Webview Authentication Flow (`u8_token`)
When a pull sync begins, `SkportAuthService::getEndfieldU8Token()` executes a dedicated OAuth chain:
1. Requests an Endfield-specific OAuth Grant Token (`POST /user/oauth2/v2/grant` with `appCode: 3dacefa138426cfe` and `type: 1`).
2. Queries `GET https://binding-api-account-prod.gryphline.com/account/binding/v1/binding_list` to find the `binding.uid` matching the profile's `roleId` (`gameUid`).
3. Exchanges `binding.uid` at `POST /account/binding/v1/u8_token_by_uid` to obtain a temporary **`u8_token`** valid for the game record webview APIs.

### 5.2 Supported Banners, Pagination & Async Queue (`gryphline_pulls`)
Using `u8_token`, the async worker `SyncPullsPageProfileHandler` (on the dedicated **`gryphline_pulls`** Messenger transport) downloads `seq_id`-paginated records from `https://ef-webview.gryphline.com/api/record/char` and `/api/record/weapon`, denormalizes them into `GryphlinePullItem` DTOs, and maps them to `GryphlinePull` via `Symfony\Component\ObjectMapper`:
- **Chartered Headhunting** (`E_CharacterGachaPoolType_Special`) — Hard Pity: `80` (`6★`), `10` (`5★`).
- **Basic Headhunting** (`E_CharacterGachaPoolType_Standard`) — Hard Pity: `80` (`6★`), `10` (`5★`).
- **Weapon Headhunting** (`E_WeaponGachaPoolType_Standard`) — Hard Pity: `40` (`6★`), `10` (`5★`).
- **Beginner Headhunting** (`E_CharacterGachaPoolType_Beginner`) — Hard Pity: `50` (`6★`), `10` (`5★`).

Key behaviors:
- **Idempotency & Database Constraints (`gryphline_pull`)**: unique constraint `IDX_gryphline_game_profile_id_pool_category_seq_id` on `(gryphline_game_profile_id, pool_category, seq_id)`. Reverse pagination stops immediately upon encountering a `seq_id` already present in the database.
- **Anti-Rate-Limit Jitter Pagination**: between pages, `SyncPullsPageProfileHandler` re-dispatches `SyncPullsPageProfileMessage` with a randomized jitter `DelayStamp` (`1500ms – 2500ms`).
- **Non-Pull Bonus Filtering**: automatically excludes non-pull bonus log entries from pity calculations (e.g. `kind = "gift_intel_book"` / *Headhunting Dossier*).
- **Empty Pool Handling (`code: 50000`)**: if a banner returns code `50000` (*"save seq id unavailable"*), it is silently treated as an empty history without failing the job.
- **Execution Modes**:
  - **Nightly Auto Sync (`SyncPullsBatchMessage`)**: runs daily at `01:00` (`0 1 * * *`) for all profiles with `syncPulls = true` enabled in `GameProfileSettingsDrawer`.
  - **Manual UI Sync**: click **Sync Pulls** on `PullsPage`.
- **Concurrency Protection (`symfony/lock`)**: every pull write operation (manual sync, nightly sync, or future backup import) acquires an exclusive per-profile lock (`gryphline_profile_pulls_{profileId}`, configured in `config/packages/lock.yaml`). On contention, Messenger workers re-queue the message with a `DelayStamp` without overlapping or losing data.

### 5.3 Item Icons & Image Caching (`LiipImagine`)
Operator and weapon thumbnails in cards (`PullsRecentBadge`) and detail tables (`PullItemThumbnail`) use the shared architecture documented in **[Pull Item Icons & Image Caching](../../docs/PULL_ITEM_ICONS.md)**:
- **External Provider (`AkeDataIconResolver`)**: implements `PullItemIconResolverInterface` for `EndfieldService` (`endfield`, priority `100`), using the dedicated `gryphline.akedata.client` HTTP client (`https://data.akedata.wiki`) and the `gryphline.item_icons.cache` pool (24h TTL with stampede protection):
  - Fetches `manifest.json` (`latest.tableCfgPath`), `asset-sync-index.json`, and `ItemTable.json`, caching only the compact `[itemId => relativePath]` lookup map.
  - **Operators** (`chr_*`): resolves icons from `charremoteicon/icon_{charId}`.
  - **Weapons**: resolves `iconId` via `ItemTable` under `itemicon/` (falling back to `gachaweapon/`).
- **API Enrichment & Local Cache**: `ApiGameProfilesController` injects `'icon_url'` via `PullItemIconUrlBuilder::enrichPullRows()`; on first request, `LiipImagineBundle` downloads and caches the resized image (`128×128`, `webp`, quality `100`) under `public/media/cache/games_pull_item_icon/`.

### 5.4 Real-Time Progress Tracking (Mercure + Polling Fallback)
All asynchronous pull operations — manual **Auto Sync**, nightly **Scheduled Sync**, and future **Backup Imports** (`GamesBundle\Model\Pull\PullOperationKind`) — are tracked uniformly by `GryphlineBundle\Service\Pull\PullOperationTracker` (extending `GamesBundle\Service\AbstractPullOperationTracker`):
- **Cache Snapshot + Mercure Updates**:
  - Operation state is stored in `gryphline.pull_import_status.cache` (1-hour TTL, configured in `src/GryphlineBundle/config/packages/cache.yaml`) and published as a **private** Mercure update on `https://{games.domain_name}/gryphline/profiles/{profileId}/pulls/progress` (payload `{ action: 'pulls:operation_progress', operation: {...} }`).
  - `GET .../pulls/overview` sets the `mercureAuthorization` cookie for the profile's topic and returns `mercure_topic`, `available_backup_importers`, and any currently running `active_operation`.
- **Shared Live UI (`@GamesBundle/components/pulls/*` on `PullsPage`)**:
  - `PullsPage` uses the shared `usePullOperationProgress` hook and `PullOperationProgressBanner` component to open an authenticated `EventSource` against `MERCURE_HUB_URL`, displaying real-time progress bars, counters (`processed / total`, `imported`, `skipped`, `promoted/linked`), and per-banner status.
  - If Mercure is unreachable, the hook automatically falls back to **1-second HTTP polling** against `GET .../pulls/operations/{operationId}` while the operation is active.
  - Upon transitioning to `completed`, the page automatically refreshes the overview and selected banner view.

### 5.5 Extensible Third-Party Backup Import Architecture (Plug & Play)
The bundle already includes the same backup import pipeline as `HoyoverseBundle`, allowing future third-party exporters (or PowerShell-based imports) to be plugged in without modifying controllers, workers, or frontend code:
- **Importer Auto-Registration (`PullBackupImporterInterface`)**:
  - Simply create a service implementing `App\GryphlineBundle\Model\Pull\PullBackupImporterInterface` and declaring supported games via `getSupportedGameServices()`.
  - The `_instanceof` rule in `src/GryphlineBundle/config/services.yaml` automatically tags the service with `gryphline.pull_backup_importer`, making it immediately available in `PullBackupImporterManager`, the CLI command, and the **`Import ▾`** dropdown on `PullsPage` (automatically hidden while no importers are registered).
- **Deduplication & Synthetic Pull Reconciliation (`PullBackupImportService` & `SyntheticPullReconcilerInterface`)**:
  - `PullBackupImportService` handles validation, batched persistence (250 records per flush), exact `(pool_category, seq_id)` deduplication, and logical multiset deduplication on `(banner_group, pulled_at, name)` for synthetic-ID pulls.
  - If a future importer generates synthetic IDs, it can also implement `App\GryphlineBundle\Model\Pull\SyntheticPullReconcilerInterface` (auto-tagged with `gryphline.synthetic_pull_reconciler`): during official syncs, `SyncPullsPageProfileHandler` delegates in-place promotion of matching synthetic rows to `PullSyntheticReconcilerManager`.
- **Async Worker & CLI (`GryphlineImportPullsCommand`)**:
  - Web upload endpoint `POST .../pulls/import-backup` (max 25 MB, synchronous validation, dispatches `ImportPullsBackupMessage` onto `gryphline_pulls`, guaranteed cleanup of temporary files under `var/tmp/gryphline_pull_imports/`).
  - Reusable CLI command:
    ```bash
    # Dry-run preview without writing to the database
    php bin/console gryphline:import-pulls <profileId> <importerKey> /path/to/file --dry-run

    # Synchronous import with progress bar
    php bin/console gryphline:import-pulls <profileId> <importerKey> /path/to/file

    # Asynchronous dispatch onto Messenger (gryphline_pulls)
    php bin/console gryphline:import-pulls <profileId> <importerKey> /path/to/file --async
    ```

---

## 6. Bundle API Endpoint Summary

| Method | Path | Description |
| :--- | :--- | :--- |
| `POST` | `/api/gryphline/accounts/login/start` | Starts direct Email + Password login on `as.gryphline.com` |
| `POST` | `/api/gryphline/accounts/{id}/relogin/start` | Starts re-login on an existing `GryphlineAccount` (validates `account_uid` / `hgId` match) |
| `POST` | `/api/gryphline/accounts/login/captcha` | Submits the GeeTest v4 captcha solution together with the session `challenge` |
| `GET` | `/api/gryphline/accounts/login/geetest-frame` | Renders the isolated iframe for the GeeTest v4 widget |
| `GET` | `/api/gryphline/accounts` / `POST /api/gryphline/accounts` | Lists accounts or adds/updates an account via manual `ACCOUNT_TOKEN` |
| `POST` | `/api/gryphline/accounts/{id}/sync` | Syncs game profiles (`GryphlineGameProfile`) via SKPort |
| `GET` | `/api/gryphline/accounts/{account}/gameProfiles/{gameProfile}/pulls/overview` | Pity/banner overview; sets Mercure cookie and returns `available_backup_importers`, `mercure_topic`, and `active_operation` |
| `GET` | `/api/gryphline/accounts/{account}/gameProfiles/{gameProfile}/pulls` | Paginated pulls for a banner with pity calculation and item icons |
| `POST` | `/api/gryphline/accounts/{account}/gameProfiles/{gameProfile}/pulls/sync` | Queues automatic pull sync via `u8_token` (returns `operation`) |
| `POST` | `/api/gryphline/accounts/{account}/gameProfiles/{gameProfile}/pulls/import-backup` | Validates a backup file via a registered importer and queues import on `gryphline_pulls` (`202` / `400` / `409`) |
| `GET` | `/api/gryphline/accounts/{account}/gameProfiles/{gameProfile}/pulls/operations/{operationId}` | Returns the latest snapshot of a pull operation (fallback polling when Mercure is unavailable) |
