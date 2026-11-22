# HoYoverse Bundle (`HoyoverseBundle`) — Feature Guide

The **`HoyoverseBundle`** manages full integration with the **HoYoLAB / HoYoverse** ecosystem for **Genshin Impact**, **Honkai: Star Rail**, **Zenless Zone Zero**, **Honkai Impact 3rd**, and **Tears of Themis**.

It supports direct credentials or cookie-based authentication, automatic session refresh, daily check-ins, automated promo code redemption, real-time monitoring of in-game resources (Resin/Trailblaze Power/Battery, expeditions, Realm Currency, shops, endgames), **Diary** synchronization (monthly premium currency income), and a **Pulls Tracker** (automatic sync via `stoken_v2` or, for Zenless Zone Zero, the HoYoLAB cookies alone, PowerShell script import, third-party backup file import, and real-time Mercure progress tracking).

---

## 1. Supported Games & Feature Matrix

| Feature | Genshin Impact (`hk4e_global`) | Honkai: Star Rail (`hkrpg_global`) | Zenless Zone Zero (`nap_global`) | Honkai Impact 3rd (`bh3_global`) | Tears of Themis (`nxx_global`) |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **Auto Check-In & Missed Check-In** | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Promo Codes (Auto Redeem API)** | ✅ (Automatic) | ✅ (Automatic) | ✅ (Automatic) | 🔔 (Manual notification) | 🔔 (Manual notification) |
| **Stamina Reminder** | ✅ (Original Resin, max 200) | ✅ (Trailblaze Power, max 300) | ✅ (Battery Charge, max 300) | ❌ | ❌ |
| **Dailies Reminder** | ✅ (Daily Tasks 4/4) | ✅ (Activity Points 500/500) | ✅ (Engagement, Scratch Card, Cafe) | ❌ | ❌ |
| **Weeklies Reminder** | ✅ (Resin Discounts 3/3) | ✅ (Echo of War 3/3, Sim. Universe) | ✅ (Bounty Commissions, Ridu Points) | ❌ | ❌ |
| **Expeditions Reminder** | ✅ | ✅ (Assignments) | ❌ | ❌ | ❌ |
| **Realm Currency Reminder** | ✅ (Serenitea Pot) | ❌ | ❌ | ❌ | ❌ |
| **Shop Status Reminder** | ❌ | ❌ | ✅ (Video Store / VHS) | ❌ | ❌ |
| **Endgames Reminder** | ❌ | ❌ | ✅ (Shiyu Defense, Deadly Assault and its Hard mode) | ❌ | ❌ |
| **Diary Sync (Monthly Currencies)** | ✅ (Primogems, Mora) | ✅ (Stellar Jades, Passes) | ✅ (Polychromes, Tapes) | ❌ | ❌ |
| **Automatic Pulls Sync (`stoken_v2`)** | ✅ | ❌ *(Unsupported by HoYoverse)* | ✅ | ❌ | ❌ |
| **PowerShell Pulls Sync** | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Backup File Pulls Import** | ✅ (`paimon.moe` JSON) | ✅ (`starrailstation.com` CSV) | ✅ (`zzz.rng.moe` JSON) | ❌ | ❌ |

### 1.1 Game Service Architecture: Capability Interfaces, Traits & Dynamic Normalization

Each game is implemented as a service extending `App\HoyoverseBundle\Model\Game\GameService` (registered in the Service Locator via the `hoyoverse.game.id` and `hoyoverse.game.biz` tags).  
Supported features for each game are declared through **Capability Interfaces**: both background **MessageHandlers** and `HoyoverseGameProfileNormalizer` (for the React frontend) inspect implemented interfaces via `instanceof` to automatically enable/hide scheduled jobs and toggles in `GameProfileSettingsDrawer`.

#### Shared Interfaces (`App\GamesBundle\Model\Game\*`)
| Interface | Associated Trait | Fields Exposed by Normalizer | Purpose / Enabled Feature |
| :--- | :--- | :--- | :--- |
| `HasNotesInterface` | `NotesTrait` | — | The game exposes a *Real-Time Notes* endpoint (`dailyNote` / `note`) on HoYoLAB to fetch live profile data. |
| `HasStaminaInterface` | `NotesTrait` | `staminaCheck`, `staminaThreshold` | The game exposes current/max Stamina and full recovery time, enabling **Stamina Reminder**. |
| `HasDailiesInterface` | `NotesTrait` | `dailiesCheck` | The game exposes daily activity progress (e.g. Commissions, Activity Points, Engagement/Scratch Card/Cafe), enabling **Dailies Reminder**. |
| `HasWeekliesInterface` | `NotesTrait` | `weekliesCheck` | The game exposes weekly activity progress (e.g. Resin Discounts, Echo of War/Sim. Universe, Bounty/Ridu Points), enabling **Weeklies Reminder**. |
| `HasEndgamesInterface` | `ZenlessZoneZeroEndgamesTrait` | `endgamesCheck`, `endgamesFullClear` | `getEndgamesData()` returns one `GameEndgame` (`GamesBundle\Model\Endgame`) per endgame mode of the current period: end of the period, played, progress towards the maximum, score of each stage. Enables the **Endgames Reminder** (*ZZZ*). |

#### HoYoverse-Specific Interfaces (`App\HoyoverseBundle\Model\Game\*`)
| Interface | Associated Trait | Fields / Flags Exposed by Normalizer | Purpose / Enabled Feature |
| :--- | :--- | :--- | :--- |
| `HasHoyolabCheckInInterface` | `HoyolabCheckInTrait` *(in `GameService`)* | `hoyolabCheckIn`, `hoyolabMissedCheckIn` | The game supports HoYoLAB daily check-in (`info`, `home`, `sign`), enabling **Auto Check-In** and **Missed Check-In Reminder**. |
| `HasCodeRedemptionInterface` | `CodeRedemptionTrait` | `codeRedeem` | The game has a public promo code source (`api.ennead.cc`). When implemented alone (*HI3*, *ToT*), sends notifications for **manual in-game redemption**. |
| `HasAutoCodeRedemptionInterface` | `AutoCodeRedemptionTrait` | `codeRedeem` | Extends `HasCodeRedemptionInterface`: the game exposes `webExchangeCdkeyRisk` (*GI*, *HSR*, *ZZZ*), enabling **automatic API code redemption**. |
| `HasExpeditionsInterface` | `NotesTrait` | `expeditionsCheck` | The game includes expeditions/assignments in Real-Time Notes (*GI*, *HSR*), enabling **Expeditions Reminder**. |
| `HasRealmCurrencyInterface` | `NotesTrait` | `realmCurrencyCheck`, `realmCurrencyThreshold` | The game includes Serenitea Pot currency in Real-Time Notes (*GI*), enabling **Realm Currency Reminder**. |
| `HasShopStatusInterface` | `NotesTrait` | `shopStatusCheck` | The game includes Video Store / VHS management status in Real-Time Notes (*ZZZ*), enabling **Shop Status Reminder**. |
| `HasDiaryInterface` | `DiaryTrait` | `syncDiary` | The game exposes monthly `month_info` and `month_detail` endpoints (*GI*, *HSR*, *ZZZ*), enabling the **Diary** page and monthly currency sync. |
| `HasPullTrackerInterface` | `HoyoversePullTrait` | `supports_pulls: true` | The game supports Gacha banner tracking (`getGachaConfig()`, `getRarityConfig()`, `fetchPullsPage()`), enabling the **Pulls Tracker** page. |
| `HasStokenPullImportInterface` | `HoyoversePullTrait` | `syncPulls`; `supports_pull_sync: true`, `pull_sync_source: "stoken"` when the account cookie has `stoken_v2` | Extends `HasPullTrackerInterface`: the game can generate a gacha `authkey` directly from `stoken_v2` via Takumi API (*GI*, *ZZZ*), enabling the **Auto Sync Pulls** toggle and 1-click **Sync Pulls** button. |
| `HasChroniclePullImportInterface` | `ChroniclePullTrait` | `supports_pull_sync: true`, `pull_sync_source: "chronicle"` when the account cookie has no `stoken_v2` | Extends `HasPullTrackerInterface`: the game lists the single pulls in the HoYoLAB battle chronicle, readable with the HoYoLAB web cookies only (*ZZZ*), so the same toggle and button work without `stoken_v2` (see §7.2). |
| `HasPowershellPullImportInterface` | `HoyoversePullTrait` | `supports_powershell_pulls: true` | Extends `HasPullTrackerInterface`: the game defines a `PowershellPullConfig` (*GI*, *HSR*, *ZZZ*), enabling **Import via PowerShell** to extract the Gacha URL from the local Windows webview cache. |
| `HasMimoInterface` | `MimoTrait` | `mimoCheck`, `mimoRedeem`, `mimoRedeemDraw`, `mimoLottery`, `mimoReservePoints` | Scaffolding for automating *Traveling Mimo* web events on HoYoLAB. |
| `HasHilichurlInterface` | — | `hilichurlCheck`, `hilichurlRedeem` | Scaffolding for automating *Hilichurl's Treasure Trove* web events. |

---

## 2. Authentication: Credentials Login, Cookies & Auto-Refresh

Each HoYoverse account (`HoyoverseAccount`) belongs to a platform user and is uniquely identified by the indexed `account_uid` column (`ltuid_v2` / `aid`), preventing duplicates when re-adding an existing account and blocking accidental mismatches with a different account during **Re-login**. The cookie string (`cookie` column, `stoken_v2` included) is encrypted in the database when `DATABASE_ENCRYPTION_KEY` is set (see [Credentials encryption at rest](../GamesBundle/README.md#credentials-encryption-at-rest)).

### 2.1 Direct Credentials Login (Email / Username + Password)
The direct login flow (`HoyoverseAuthService`) mirrors the official HoYoLAB mobile protocol (`hk4e-sdk-os.hoyoverse.com`):
1. **RSA-2048 Encryption**: `account` and `password` are encrypted separately with the OS RSA public key (`LOGIN_KEY_TYPE_1`) before being sent to `/account/ma-passport/api/appLoginByPassword`.
2. **`DS` v2 Signing & Device Identification**:
   - Every request is signed with the `DS` header (`t,r,md5(salt=IZPgfb0dRPtBeLuFkdDznSZ6f4wWt6y2&t=...&r=...&b=<json>&q=)`).
   - A 16-hex-character `x-rpc-device_id` is generated (and persisted on `HoyoverseAccount::deviceId`), paired with `x-rpc-device_name: Hoyoverse Integration` and `x-rpc-device_model: WebsiteValdi` (visible under *Trusted Devices* in the HoYoverse Account Center).
3. **GeeTest Aigis Captcha Handling (`retcode: -3101`)**:
   - If HoYoverse requires anti-bot verification, the backend extracts the `x-rpc-aigis` header, stores the encrypted temporary session (`AES-256-GCM` via `AuthSessionStore`, 10-minute TTL), and returns the challenge to the frontend.
   - The `GeetestCaptchaFrame` React component renders the GeeTest v3 or v4 widget inside an isolated iframe using SDK scripts served through the local `GeetestService` proxy.
4. **Email OTP Verification for New Devices (`retcode: -3239`)**:
   - If the `device_id` is not yet registered as a trusted device on HoYoverse, the server requires an email OTP code (`createEmailCaptchaByActionTicket` → `verifyActionTicketPartly`).
   - Once the 6-digit OTP is verified, the `device_id` becomes *trusted* and subsequent re-logins on the same account reuse that `device_id` without prompting for OTP again.
5. **Token Exchange & Profile Synchronization**:
   - After obtaining `stoken_v2`, the service calls `getLTokenBySToken` and `getCookieAccountInfoBySToken` to generate `ltoken_v2` and `cookie_token_v2`, persists the full cookie string, and immediately syncs game profiles (`HoyoverseGameProfile`).
6. **Accounts without a HoYoverse password**: accounts created on a console or with a third-party login (Google, Apple, ...) are regular HoYoverse accounts. The **Add Account** dialog tells their owners to link an email and set a password in the HoYoverse Account Center (or the in-game User Center) to use this login, or to fall back to **Paste Cookie** (§2.2).

### 2.2 Login / Update via Cookie String ("Paste Cookie")
As an alternative to credentials login, the **Paste Cookie** tab allows pasting a HoYoLAB cookie string manually:
- **Minimum requirements**: `ltoken_v2`, `ltuid_v2`, `ltmid_v2`. They are enough for check-in, notes and reminders, and for the automatic pulls sync of Zenless Zone Zero (HoYoLAB battle chronicle, §7.2.1), until the cookie expires.
- **Optional but recommended tokens**:
  - `stoken_v2`: enables continuous automatic cookie refresh and the automatic pulls sync of Genshin Impact (Zenless Zone Zero then syncs through `getGachaLog`, as before).
  - `cookie_token_v2` and `account_id_v2`: required for automatic promo code redemption when `stoken_v2` is not present.
- **Account identity**: the account UID is read from `ltuid_v2`/`account_id_v2` (or the legacy `ltuid`/`account_id`). A new cookie is deduplicated by that UID; on re-login of an existing account (`account_id` in the payload) a cookie of another HoYoverse account is refused with the same `422 ... does not match existing Account ...` error as the credentials login, and nothing is saved.

### 2.3 Automatic Cookie Refresh (`UpdateCookieMessage`)
- **Schedule**: `0 */4 * * *` (every 4 hours).
- **Behavior**: `UpdateCookieMessageHandler` iterates over all accounts with `stoken_v2` and refreshes `ltoken_v2` and `cookie_token_v2`.
- **Status Notifications**: notifies the user only on state transitions (first failure or recovery back to healthy).
- **Outages are not failures**: when HoYoverse cannot be reached (no network, DNS, timeout, HTTP 5xx/429, a body that is not JSON) `HoyolabCookieUtilsService::renew()` throws `HoyolabUnavailableException`. The task then keeps the account state, sends no notification and tries again at the next run; only a refusal from HoYoverse (`retcode` ≠ 0, missing tokens) sets `cookie_refresh_failed`. During an email/password login the same outage stops the login with `502 HoYoverse could not be reached to complete the login. Please try again later.` and nothing is saved.
- **Account badges**: "Session Active" (tooltip: `last_cookie_refreshed_at`) or "Session Refresh Failed" when `cookie_refresh_failed` is set; "Auto-Renew" and "Code Redemption" only tell whether the stored cookie contains `stoken_v2` and the redemption tokens, not whether they are still valid.

---

## 3. Daily Auto Check-In

Two scheduled tasks ensure no HoYoLAB daily check-in is missed:

1. **Auto Check-In (`HoyolabCheckInBatchMessage`)** — Schedule: `0 20 * * *`
   - Queries the `info` endpoint to check whether today's check-in has already been claimed for each profile with `hoyolabCheckIn = true`.
   - If unclaimed, sends the `sign` request, fetches today's reward from `home` (e.g. *Primogems x20*), and sends a notification with the reward icon/quantity, monthly check-in total, and any missed days.
2. **Missed Check-In Reminder (`HoyolabMissedCheckInBatchMessage`)** — Schedule: `0 14 * * *`
   - For profiles with `hoyolabMissedCheckIn = true` (when auto check-in is disabled or failed), checks midday whether today's check-in is still pending and sends a reminder with today's claimable reward.

---

## 4. Promo Code Auto Redemption

- **Schedule**: `*/15 * * * *` (every 15 minutes) via `CodeRedeemBatchMessage`.
- **Code Source**: queries the public API `https://api.ennead.cc/mihoyo/{game}/codes` to fetch active promo codes and their rewards.
- **Eligible profiles**: active profiles with the `code_redeem` setting enabled (on by default). The setting can be changed even when the account cannot redeem automatically: it then controls the manual-redemption notifications.
- **Anti-Duplicate Cache** (`hoyoverse.redemption_history.cache`, no expiry):
  - `redeemed_{profile}_{code}`: code processed for a profile (redeemed, or failed with a terminal error);
  - `pending_{profile}_{code}`: redemption queued (30-minute lock, released on errors);
  - `manual_notified_{profile}_{code}`: manual redemption already notified to a profile without redemption tokens;
  - `manual_redeemed_{game}_{code}`: code already notified for a manual-only game.
- **Redemption Modes**:
  1. **Automatic API Redemption** (*Genshin Impact, Honkai: Star Rail, Zenless Zone Zero*) for accounts whose cookie has `cookie_token_v2`, `account_mid_v2` and `account_id_v2` (the "Code Redemption Ready" tag):
     - One `CodeRedeemProfileMessage` per profile and code on the `hoyoverse_redeem` transport, calling the game's `webExchangeCdkeyRisk` endpoint with the cookie stored in the database. The cookie is not refreshed right before redeeming: with `stoken_v2`, `cookie_token_v2` is kept fresh by the 4-hour `update_cookie` task (§2.3).
     - At least 6 seconds between two calls for the same game, to stay below HoYoverse rate limits.
     - **Success**: notification with the reward list.
     - **Terminal errors** (already claimed, expired, invalid, max uses, in-game only, wrong region): the code is marked as processed and a failure notification links the redemption page.
     - **Other errors**: failure notification with the redemption link, then Messenger retries the message (2 retries, 20 s and 40 s apart). Each failed attempt sends its notification.
  2. **Manual Redemption Notification**, once per code:
     - *Honkai Impact 3rd, Tears of Themis*: no web redemption endpoint, so the user is told to redeem the new code in game (rewards and instructions).
     - Auto-redeemable games whose account cookie lacks `cookie_token_v2`, `account_mid_v2` or `account_id_v2` (or is not parseable): no API call is attempted (it could only fail). The user gets the code with the redemption link and a note that automatic redemption is not configured for the account (the "Code Redemption Unavailable" tag explains which tokens are missing).

---

## 5. Reminders & Real-Time Monitoring (Game Notes & Endgames)

Reminders are split into **Global Tasks** (run at fixed intervals regardless of server) and **Regional Tasks** (run according to the game server's timezone: `SEA` → `Asia/Shanghai`, `EU` → `Europe/Paris`, `NA` → `America/New_York`).

### 5.1 Stamina Reminder (`StaminaCheckBatchMessage`)
- **Schedule**: `*/30 * * * *` (every 30 minutes).
- **Per-Profile Configuration**: `staminaCheck` toggle and customizable `staminaThreshold` (`-1` alerts only when reaching max cap: `200` on GI, `300` on HSR and ZZZ).
- **Cache Anti-Spam**: once a threshold notification is sent, the notified amount is cached until the estimated full recovery timestamp; if the player spends stamina in-game and drops below that value, the lock resets automatically.

### 5.2 Expeditions Reminder (`ExpeditionsCheckBatchMessage`)
- **Schedule**: `*/30 * * * *` (every 30 minutes) — *Genshin Impact, Honkai: Star Rail*.
- **Behavior**: checks expedition/assignment status; when **all** active expeditions are `Finished`, sends a single notification per completion cycle using state caching.

### 5.3 Realm Currency Reminder (`RealmCurrencyCheckBatchMessage`)
- **Schedule**: `0 * * * *` (hourly) — *Genshin Impact (Serenitea Pot)*.
- **Behavior**: same logic as Stamina Reminder; alerts when Teapot currency exceeds `realmCurrencyThreshold` (or reaches max cap when `-1`).

### 5.4 Shop Status Reminder (`ShopStatusCheckBatchMessage`)
- **Schedule**: `0 * * * *` (hourly) — *Zenless Zone Zero*.
- **Behavior**: monitors Video Store status (`VhsSaleState`) and alerts if the store needs attention (`SaleStateNo` / no VHS promoted).

### 5.5 Dailies & Weeklies Reminders (`DailiesReminderBatchMessage` / `WeekliesReminderBatchMessage`)
- **Regional Schedule**:
  - **Dailies**: `0 21 * * *` (21:00 local server time for `SEA` / `EU` / `NA`).
  - **Weeklies**: `0 21 * * 0` (every Sunday at 21:00 local server time).
- **Behavior**: checks all daily or weekly metrics for the game (e.g. 4/4 Commissions on GI, 500 Activity Points or Echo of War on HSR, Engagement/Scratch Card/Coff Cafe/Bounty Commissions on ZZZ) and sends a reminder **only if at least one activity is incomplete**.

### 5.6 Endgames Reminder (`EndgamesReminderBatchMessage`)
- **Regional Schedule**: `0 15 * * *` (15:00 local server time) — *Zenless Zone Zero*.
- **Settings**: `endgamesCheck` (*Endgames Reminder*) turns it on; `endgamesFullClear` (*Remind Until Full Clear*, off by default) chooses what is reminded: off = only the modes not played yet, on = also the modes played but below the maximum.
- **Alert Window**: every day while the period ends within `hoyoverse.endgames_reminder.days_before_end` days (default `7`, counted in 24-hour days from the run). With the 15:00 run and a period ending at 03:59:59 server time, the first reminder comes on the 7th day before the end and the last one the day before. No notification when nothing is left to do; no deduplication (one run per day).
- **Data** (`ZenlessZoneZeroEndgamesTrait`, no `DS` header, `schedule_type=1` = current period, `2` = previous one):
  - *Shiyu Defense*: `game_record_zzz/api/zzz/hadal_info_v2` (`role_id`, `server`), with the per-stage detail (no `without_v2_detail`) → `ShiyuDefenseData`, `ShiyuDefenseStage`.
  - *Deadly Assault*: `game_record_zzz/api/zzz/hadal_mem_detail_v2` (`uid`, `region`) → `DeadlyAssaultData`, `DeadlyAssaultChallenge` (normal mode `list`, Hard mode `hard_list`).
- **Rules** (constants and methods of `ShiyuDefenseData` and `DeadlyAssaultData`, the place to change them):

  | Mode | Played | At the maximum |
  | :--- | :--- | :--- |
  | Shiyu Defense | an attempt with a score or a rating (total or stage score above 0, or a stage rated) | the three fifth-frontier stages at S rank (rating `S`, or at least `S_RANK_SCORE` = 25,000 of 50,000 points) |
  | Deadly Assault | an attempt with a score or stars | 9★ (3★ on each of the three bosses, as reported by HoYoLAB) |
  | Deadly Assault (Hard) | reminded only once unlocked, i.e. with 9★ in normal mode; then an attempt with a score or stars | 3★ (one boss) |
- **Notification**: one per profile, one field per mode: `Not played yet`, or the progress (`2/3 stages at S rank`, `8/9 ★`) followed by the stages or bosses below the maximum with their scores; last line `Ends in … (end in server time)`.
- **Dates**: Shiyu Defense uses its epoch `end_time`. Deadly Assault only has `{year, month, day, …}` objects, which are server wall-clock times: `HoyoverseServerTimezoneResolver::serverWallClockToInstant()` reads them in the fixed offset of the profile's server (§7.5).
- **Unexpected responses**: missing or `null` fields, empty lists and `has_data: false` count as not played; a mode without an end date gets no reminder (`info` log); empty date objects (all `null`, zeros) count as no date; a Shiyu Defense response whose `hadal_ver` is not `v2` is left out with a `warning` in the `hoyoverse.zzz` log. Each mode is read on its own: one whose request or shape fails is left out with a `warning` (exception class and retcode only) and the other is still reminded; when neither can be read the first error is thrown, so an expired cookie fails the task like in the other reminders. No response for a period that was not played has been observed yet: these shapes are assumptions.
- **Other games**: `GenshinImpactEndgamesTrait` (requests only) and `HonkaiStarRailEndgamesTrait` (empty) do not implement `HasEndgamesInterface` yet. A game gets the reminder by implementing `getEndgamesData()`.

---

## 6. Diary Synchronization (Monthly Currencies)

The **Diary** feature archives and displays the detailed history of in-game currency income (which HoYoLAB only retains for the last 3 months):
- **Tracked Currencies**:
  - **Genshin Impact** (*Traveler's Diary*): `PRIMOGEM` and `MORA`.
  - **Honkai: Star Rail** (*Trailblaze Monthly Calendar*): `STELLAR_JADE` and `STAR_RAIL_PASS`.
  - **Zenless Zone Zero** (*Inter-Knot Monthly Report*): `POLYCHROME` and `MASTER_TAPE`.
- **Automatic Synchronization (`SyncDiaryBatchMessage`)**:
  - **Schedule**: `0 0 2 * *` (day 2 of every month at midnight).
  - For all profiles with `syncDiary = true`, queues a full sync of both the **previous month** and the **current month**.
- **Manual UI Synchronization**:
  - From the **Diary** page, users can pick any of the last 3 months available on HoYoLAB and trigger an asynchronous import (`POST /api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/diary/sync`).
- **Import Architecture (`SyncDiaryPageProfileHandler`)**:
  - Downloads paginated logs (`page_size = 100`) per currency and persists each entry in `hoyoverse_diary_entry` (`action_id`, `action_name`, `amount`, `awarded_at`), automatically skipping duplicates already stored in the database.
  - The frontend table supports filtering by currency, source/action, date range, and sorting.

---

## 7. Pulls Synchronization & Importing

The **Pulls Tracker** stores full Gacha banner history in the `hoyoverse_pull` table (`HoyoversePull`), calculates pity statistics (`5★`/`S-Rank` and `4★`/`A-Rank`), renders monthly distribution charts (`PullsMonthlyChart`), and provides per-banner breakdowns (`PullsBannerCard` / `PullsBannerDetail`).

Pity and banner statistics come from `Service/Pull/HoyoversePullStatsCalculator`, a pure service (same contract as `PerfectworldPullStatsCalculator`): `HoyoversePullRepository` only runs the queries, and the overview/list actions compose `groupRowsByBanner()` (gacha types missing from the config become dynamic 90/10 groups), `compute()` and `filterAndPaginate()`.

### 7.1 Architecture, DTOs, Async Queue (`hoyoverse_pulls`) & Locking
- **Interfaces & Traits**:
  - `HasPullTrackerInterface` + `HoyoversePullTrait`: handle requests to `getGachaLog` / `getLdGachaLog`, denormalization into `HoyoversePullItem` DTOs, and mapping onto `HoyoversePull` via `Symfony\Component\ObjectMapper`.
  - `HasStokenPullImportInterface`: implemented by **Genshin Impact** and **Zenless Zone Zero** for automatic server-side syncing.
  - `HasChroniclePullImportInterface` + `ChroniclePullTrait`: implemented by **Zenless Zone Zero** for automatic server-side syncing without `stoken_v2` (HoYoLAB battle chronicle).
  - `HasPowershellPullImportInterface`: implemented by **Genshin Impact**, **Honkai: Star Rail**, and **Zenless Zone Zero** for PowerShell-extracted URL imports.
- **Idempotency & Database Constraints (`hoyoverse_pull`)**:
  - Unique constraint `IDX_hoyoverse_game_profile_id_pull_id` on `(hoyoverse_game_profile_id, pull_id)` (HoYoverse's 19-digit sequential ID, or synthetic `paimon_*` IDs for legacy backups lacking official IDs).
  - Reverse pagination stops immediately upon encountering an official `pull_id` already present in the database.
- **Dedicated Messenger Transport & Rate Limiting**:
  - All pull sync and import jobs (including backup files) run on the dedicated **`hoyoverse_pulls`** transport.
  - `SyncPullsBatchHandler` creates a tracked operation (`scheduled_sync`) per profile and staggers messages with `DelayStamp`.
  - `SyncPullsPageProfileHandler` fetches one page (`size = 20`) and, if more pages remain, re-dispatches `SyncPullsPageProfileMessage` with the updated `end_id` and a **randomized jitter `DelayStamp` (`1500ms – 2500ms`)** to avoid HoYoverse API rate limits.
- **Per-Profile Mutual Exclusion (`symfony/lock`)**:
  - All pull write operations for a given profile (Automatic Sync, Manual Sync, PowerShell Import, and Backup Import) share the `hoyoverse_profile_pulls_{profileId}` lock configured in `config/packages/lock.yaml`.
  - On lock contention, `ImportPullsBackupHandler` and `SyncPullsPageProfileHandler` re-queue the message with a `DelayStamp` without dropping data or prematurely deleting temporary backup files.

### 7.2 Automatic Sync via `stoken_v2` or HoYoLAB Cookies (Genshin Impact & Zenless Zone Zero)
`Service/Pull/PullSyncSourceResolver` decides from the game **and** the account cookie whether a profile can sync automatically, and where the pages come from (`Model/Pull/PullSyncSource`). The **Sync Pulls** endpoint, `SyncPullsBatchHandler`, `SyncPullsPageProfileHandler` and the `supports_pull_sync` / `pull_sync_source` normalizer fields all ask it:
- **`stoken_v2` present** and the game implements `HasStokenPullImportInterface` (**Genshin Impact**, **Zenless Zone Zero**): authkey + `getGachaLog` (steps below).
- **No `stoken_v2`** and the game implements `HasChroniclePullImportInterface` (**Zenless Zone Zero**): HoYoLAB battle chronicle (§7.2.1).
- **Otherwise** (Genshin Impact without `stoken_v2`, Honkai: Star Rail): no automatic sync. **Sync Pulls** answers `400`, the nightly batch skips the profile and the settings drawer explains why; PowerShell and backup imports stay available.

A message that already carries an authkey (PowerShell import) always uses `getGachaLog`.

With `stoken_v2` (obtained automatically via Email/Password login):
1. `HoyolabCookieUtilsService::generateAuthKey()` generates a temporary `webview_gacha` `authkey` on the fly signed with `DS` LK2 (`salt=ulInCDohgEs557j0VsPDYnQaaz6KJcv5`) via `https://api-account-os.hoyoverse.com/binding/api/genAuthKey`.
2. **Configured Banners**:
   - **Genshin Impact**: *Character Event Wish* (`301` + `400`, pity 90/10), *Weapon Event Wish* (`302`, pity 80/10), *Standard Wish* (`200`, pity 90/10), *Beginners' Wish* (`100`, pity 20/10), *Chronicled Wish* (`500`, pity 90/10).
   - **Zenless Zone Zero**: *Exclusive Channel* (`2`, pity 90/10), *W-Engine Channel* (`3`, pity 80/10), *Standard Channel* (`1`, pity 90/10), *Bangboo Channel* (`5`, pity 80/10), *Exclusive Rescreening* (`12`, pity 90/10), *W-Engine Reverberation* (`13`, pity 80/10).
3. **Execution Modes**:
   - **Nightly Auto Sync (`SyncPullsBatchMessage`)**: runs daily at `01:00` (`0 1 * * *`) for all profiles with `syncPulls = true` enabled in `GameProfileSettingsDrawer`.
   - **1-Click Manual UI Sync**: click **Sync Pulls** on `PullsPage`.

#### 7.2.1 Without `stoken_v2`: HoYoLAB Battle Chronicle (Zenless Zone Zero)
An account whose cookie has no `stoken_v2` (**Paste Cookie** without it, typically when the owner has no HoYoverse password, §2.1) cannot get an authkey. For Zenless Zone Zero, `SyncPullsPageProfileHandler` then reads each page with `ChroniclePullTrait::fetchChroniclePullsPage()`; everything else is the flow above (same execution modes, page size 20, newest first, stop on the first known `pull_id`, re-dispatch with `end_id` and jitter, operation tracker, final realign):
- **Request**: `GET https://sg-act-public-api.hoyolab.com/event/game_record_zzz/api/zzz/gacha_record` with `uid`, `region`, `gacha_type` and `end_id` (`0` for the first page, then the `id` of the last record), sent through `requestHoyolab()` with the stored HoYoLAB web cookies (`ltoken_v2`, `ltuid_v2`, `ltmid_v2`): no authkey, no `DS` header.
- **Banner types** (`getChronicleGachaTypes()`): `1` → `GACHA_TYPE_PERMANENT`, `2` → `GACHA_TYPE_CHARACTER_UP`, `3` → `GACHA_TYPE_WEAPON_UP`, `5` → `GACHA_TYPE_BANGBOO`, `12` → `GACHA_TYPE_CHARACTER_RETURN`, `13` → `GACHA_TYPE_WEAPON_RETURN`.
- **Same rows as `getGachaLog`**: the record `id` is the `pull_id` returned by `getGachaLog`, so both sources share the deduplication and can follow each other. The banner key becomes `gacha_type`, `item_name` the name, `item_id` the item id; `ITEM_TYPE_AVATAR` / `ITEM_TYPE_WEAPON` / `ITEM_TYPE_BANGBOO` become `Agents` / `W-Engines` / `Bangboo` (`getChronicleItemTypes()`, unknown types are kept as received); rarity `S` / `A` / `B` becomes the rarity of the game tiers (`4` / `3` / `2`); `date` (`{year, month, day, hour, minute, second}`) is the same server wall-clock time as the `time` of `getGachaLog` and is tagged as UTC without conversion. Only `raw_payload` differs: it keeps the chronicle record.
- **Errors**: a refusal from HoYoLAB (e.g. expired cookie) throws `RetrievePullsDataException` with the retcode, marks the banner as failed in the operation tracker and lets Messenger retry the page, like a `getGachaLog` error. Unexpected records (no `id`, unknown rarity, invalid date) fail the same way.
- **Limits**: the battle chronicle only returns recent pulls (an older `end_id` gives an empty list; the window looks like the one of `getGachaLog`, older history comes from backup imports). Without `stoken_v2` the cookies are never refreshed: the sync works until they expire, then a new cookie must be pasted (or the account logged in with email and password).

### 7.3 PowerShell Script / Gacha URL Import (All Games Including HSR)
Because **Honkai: Star Rail** (`hkrpg_global`) does not allow generating a gacha `authkey` via `stoken_v2` (returns `retcode -106`), and as a fallback for accounts without `stoken_v2`, assisted PowerShell importing (`PowershellPullImportModal`) is available from the **`Import ▾`** dropdown on `PullsPage`:
1. The user runs the one-liner shown in the modal inside Windows PowerShell:
   ```powershell
   irm "https://games.<domain>/api/hoyoverse/pulls/script/powershell?game_biz=<game_biz>" | iex
   ```
2. `GET /api/hoyoverse/pulls/script/powershell` dynamically builds the `.ps1` script from the game's `PowershellPullConfig`:
   - Reads local game logs (`Player.log` / `output_log.txt` under `%USERPROFILE%\AppData\LocalLow\...`), locates `webCaches\...\Cache\Cache_Data\data_2`, extracts the latest `getGachaLog` URL containing `authkey=...`, and **copies it to the clipboard** (`Set-Clipboard`).
3. The user pastes the URL into the modal and starts the import (`POST /api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/import-powershell`).
4. The backend validates the URL and dispatches `SyncPullsPageProfileMessage` jobs onto `hoyoverse_pulls` for all game banners, including for **Honkai: Star Rail**:
   - *Character Event Warp* (`11`, pity 90/10), *Light Cone Event Warp* (`12`, pity 80/10), *Collaboration Character Warp* (`21` on `getLdGachaLog`, pity 90/10), *Collaboration Light Cone Warp* (`22` on `getLdGachaLog`, pity 90/10), *Regular Warp* (`1`, pity 90/10), and *Departure Warp* (`2`, pity 50/10).

### 7.4 Item Icons & Shared Catalog (`NanokaCatalogService` & `LiipImagine`)
Character, weapon, Light Cone, W-Engine, and Bangboo thumbnails in cards (`PullsRecentBadge`) and detail tables (`PullItemThumbnail`) use the shared architecture documented in **[Pull Item Icons & Image Caching](../../docs/PULL_ITEM_ICONS.md)**:
- **Shared Catalog (`NanokaCatalogService`)**: fetches and caches (`hoyoverse.item_icons.cache`, 24h TTL with stampede protection) the `static.nanoka.cc` manifest and catalogs for GI, HSR (stripping `<unbreak>` rich-text tags), and ZZZ. Used by both `NanokaIconResolver` and backup importers to resolve item names, rarities, and types.
- **External Provider (`NanokaIconResolver`)**: implements `PullItemIconResolverInterface` for `GenshinImpactService`, `HonkaiStarRailService`, and `ZenlessZoneZeroService` with priority `100`:
  - **Genshin Impact** (`hk4e_global`): normalized-name lookup in `gi/{version}/{character|weapon}.json` → `https://static.nanoka.cc/assets/gi/{icon}.webp`.
  - **Honkai: Star Rail** (`hkrpg_global`): direct `itemId` resolution → `avatarroundicon/{itemId}.webp` (characters) and `lightconemediumicon/{itemId}.webp` (Light Cones).
  - **Zenless Zone Zero** (`nap_global`): lookup in `zzz/{version}/{character|weapon|bangboo}.json`, converting `IconRole*` → `IconRoleCircle*` for agents.
- **API Enrichment & Local Cache**: `ApiGameProfilesController` injects `'icon_url'` via `PullItemIconUrlBuilder::enrichPullRows()`; on first request, `LiipImagineBundle` downloads the asset via `PullItemIconBinaryLoader` and caches the resized image (`128×128`, `webp`, quality `100`) under `public/media/cache/games_pull_item_icon/`.

### 7.5 Third-Party Backup Importing (Paimon.moe, Star Rail Station, ZZZ.rng.moe)
To preserve pull history older than HoYoverse's 6–12 month API window, the bundle provides an extensible **Backup Importer** architecture accessible from both the web UI and the CLI.

#### Supported Importers
| Key (`importer`) | Service | Game | Format | `pull_id` & Timezone Handling |
| :--- | :--- | :--- | :---: | :--- |
| `paimon_moe` | `PaimonMoePullImporter` | Genshin Impact | `.json` | Timestamps are already in server wall-clock time. Paimon.moe backups omit real `pull_id` values: generates deterministic synthetic IDs (`paimon_{gachaType}_{YYYYMMDDHHMMSS}_{seq}`) with automatic live-sync reconciliation. |
| `star_rail_station_csv` | `StarRailStationCsvPullImporter` | Honkai: Star Rail | `.csv` | Preserves HoYoverse's real `pull_id` (`uid`). Converts UTC ISO timestamps to the profile's fixed server offset. |
| `zzz_rng_moe` | `ZzzRngMoePullImporter` | Zenless Zone Zero | `.json` | Preserves HoYoverse's real `pull_id` (`uid`) and maps channels `1001/2001/3001/5001/12001/13001` to HoYoverse `gachaType` values (`1/2/3/5/12/13`). Converts UTC millisecond timestamps to the profile's fixed server offset. |

#### Architecture, Deduplication & Timezone Conversion
- **Interface & Auto-Registration**: each source implements `App\HoyoverseBundle\Model\Pull\PullBackupImporterInterface` and declares supported games via `getSupportedGameServices()`. An `_instanceof` rule in `src/HoyoverseBundle/config/services.yaml` automatically tags all implementations with `hoyoverse.pull_backup_importer`, exposing them immediately in `PullBackupImporterManager`, CLI commands, and the frontend dropdown.
- **Timezone Conversion (`HoyoverseServerTimezoneResolver`)**: HoYoverse game servers use fixed UTC offsets **without DST**: `EU` `+01:00`, `NA` `-05:00`, `Asia/TW/HK/MO/CN` `+08:00`. The resolver converts UTC-based backups (HSR, ZZZ) into the exact same server wall-clock time returned by HoYoverse's live API.
- **Core Service (`PullBackupImportService`)**: explicitly accepts a target `HoyoverseGameProfile` (never infers the account from the file), acquires the per-profile lock, validates the file, persists records in batches of 250, and applies two layers of deduplication:
  1. **Exact `pull_id` match** (`HoyoversePullRepository::findExistingPullIdSet()`): makes repeated re-imports and overlaps with existing pulls completely idempotent.
  2. **Logical multiset match** (`HoyoversePullRepository::findExistingLogicalPullCounts()`): for synthetic-ID records (e.g. `paimon_*`), counts existing DB occurrences per `(banner_group, pulled_at, name)` so pulls already synced live are skipped even inside same-second multi-pulls.
- **Automatic Synthetic Pull Reconciliation (`SyntheticPullReconcilerInterface`)**:
  - If a Genshin profile imports a `paimon.moe` backup first and runs a live HoYoverse sync afterward, `SyncPullsPageProfileHandler` delegates to `PullSyntheticReconcilerManager` (collecting services tagged `hoyoverse.synthetic_pull_reconciler`).
  - `PaimonMoePullImporter::reconcile()` finds the matching synthetic `paimon_*` row on the same `(banner_group, pulled_at, name)` and **promotes it in-place**, updating `pull_id`, `gacha_type`, `item_type`, and `raw_payload` with the real HoYoverse values — making import/sync order completely irrelevant.

#### Web UI & CLI Usage
- **UI (`PullsPage` → `Import ▾` → `BackupPullImportModal`)**:
  - The unified **`Import ▾`** button lists *Import via PowerShell* followed by a divider and all backup importers compatible with the current profile's game.
  - On upload (`POST .../pulls/import-backup`, max 25 MB), the controller validates extension and schema **synchronously** (`$importer->validate()`): invalid files immediately return `400` without queuing anything; valid files are saved to `var/tmp/hoyoverse_pull_imports/`, registered in `PullOperationTracker`, and queued via `ImportPullsBackupMessage` on `hoyoverse_pulls` (`202 Accepted`). `ImportPullsBackupHandler` always deletes the temporary file in `finally`.
- **CLI (`HoyoverseImportPullsCommand`)**:
  ```bash
  # Dry-run preview without writing to the database
  php bin/console hoyoverse:import-pulls <profileId> <paimon_moe|star_rail_station_csv|zzz_rng_moe> /path/to/file --dry-run

  # Synchronous import with progress bar
  php bin/console hoyoverse:import-pulls <profileId> paimon_moe /path/to/paimon-moe.json

  # Asynchronous dispatch onto Messenger (hoyoverse_pulls)
  php bin/console hoyoverse:import-pulls <profileId> star_rail_station_csv /path/to/srs.csv --async
  ```

### 7.6 Real-Time Progress Tracking (Mercure + Polling Fallback)
All asynchronous pull operations — **Backup Import**, **PowerShell Import**, **Manual SToken Sync**, and **Scheduled Sync** (`GamesBundle\Model\Pull\PullOperationKind`) — are tracked uniformly by `HoyoverseBundle\Service\Pull\PullOperationTracker` (extending `GamesBundle\Service\AbstractPullOperationTracker`):
- **Cache Snapshot + Mercure Updates**:
  - Operation state is stored in `hoyoverse.pull_import_status.cache` (1-hour TTL, configured in `src/HoyoverseBundle/config/packages/cache.yaml`) and published as a **private** Mercure update on `https://{games.domain_name}/hoyoverse/profiles/{profileId}/pulls/progress` (payload `{ action: 'pulls:operation_progress', operation: {...} }`).
  - `GET .../pulls/overview` sets the `mercureAuthorization` cookie for the profile's topic and returns `mercure_topic`, `available_backup_importers`, and any currently running `active_operation`.
- **Shared Live UI (`@GamesBundle/components/pulls/*` on `PullsPage`)**:
  - `PullsPage` uses the shared `usePullOperationProgress` hook, `PullOperationProgressBanner`, and `BackupPullImportModal` to open an authenticated `EventSource` against `MERCURE_HUB_URL`, updating the progress bar, counters (`processed / total`, `imported`, `skipped`, `promoted/linked`), and per-banner sync status in real time.
  - If Mercure is unreachable, it automatically falls back to **1-second HTTP polling** against `GET .../pulls/operations/{operationId}` while the operation is active.
  - Upon transitioning to `completed`, the page automatically refreshes the overview and selected banner view.

### 7.7 Missing Pulls (Manual S Pulls, Declared Pity, Filler Rows)
- **Rows**: `hoyoverse_pull.origin` (`PullOrigin`: `recorded` / `manual` / `filler`) and `declared_pity` (real pity declared on an S). A manual S has `pull_id = manual_<uuid>`; a filler (unknown pull) has `pull_id = filler_<uuid>`, rarity `0`, name `Unknown`, type `Unknown`. Both use the group's first gacha type (`301` for the Character Event Wish).
- **Counting** (`HoyoversePullStatsCalculator`): fillers count as pulls (pity S, `pull_number`, totals, currency, monthly) but go to `unknown_count` instead of `b_count`, show `pity: null`, and reset the A pity. Rows expose `origin` and `declared_pity`; S rows also `recorded_pity`, `insert_after`, `insert_before`, `suggested_at`, `has_room`; summaries `unknown_count` and `open_window`. The list accepts rarity `0`.
- **Writes** (`ManualPullService`, profile lock taken non-blocking, `409` when busy): add an S (`createManualS`), declare a pity (`declarePity`, stored as `null` when equal to the recorded pity on a recorded S), delete a manual S. Each write runs `realign()` for its banner (`PullGapPlanner`: merges a manual S into the recorded S that arrives later, then creates, moves or deletes fillers).
- **Automatic realign**: at the end of a backup import (`realignAll()`, not on dry runs) and on the last page of each banner sync. `findExistingLogicalPullCounts()` ignores non-recorded rows.
- **Dates**: server wall-clock time tagged as UTC (`HoyoverseServerTimezoneResolver`); "now" is the profile's server time (`getServerWallClockNow()`). `pulled_at` uses the shared `wall_clock_datetime_immutable` type (`App\CoreBundle\Doctrine\WallClockDateTimeImmutableType`), read back as UTC, so times in the hour skipped when DST starts in Europe/Rome are never shifted.
- **Suggestions** (`ManualPullSuggestionProvider`): S items of the Nanoka catalog plus the recorded S pulls of the game; free text allowed (with a required item type).

---

## 8. Bundle API Endpoint Summary

| Method | Path | Description |
| :--- | :--- | :--- |
| `POST` | `/api/hoyoverse/accounts/login/start` | Starts direct Email/Username + Password login |
| `POST` | `/api/hoyoverse/accounts/{id}/relogin/start` | Starts re-login on an existing `HoyoverseAccount` (reuses `device_id` and validates `account_uid`) |
| `POST` | `/api/hoyoverse/accounts/login/captcha` | Submits the GeeTest v3/v4 Aigis captcha solution |
| `POST` | `/api/hoyoverse/accounts/login/verify-email` | Verifies the 6-digit email OTP code (`-3239`) |
| `POST` | `/api/hoyoverse/accounts/login/resend-email` | Requests a new email OTP code |
| `GET` | `/api/hoyoverse/accounts/login/geetest-frame` | Renders the isolated iframe for the GeeTest v3/v4 widget |
| `GET` | `/api/hoyoverse/accounts` / `POST /api/hoyoverse/accounts` | Lists accounts or adds/updates an account via Cookie String |
| `POST` | `/api/hoyoverse/accounts/{id}/sync` | Syncs game profiles (`HoyoverseGameProfile`) from the HoYoLAB account |
| `GET` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/diary/meta` | Metadata, currencies, and available months for the Diary |
| `GET` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/diary/summary` | Monthly totals and action breakdown for the Diary |
| `GET` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/diary/entries` | Paginated list of Diary entries |
| `GET` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/overview` | Pity/banner overview; sets Mercure cookie and returns `available_backup_importers`, `mercure_topic`, and `active_operation` |
| `GET` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls` | Paginated pulls for a banner with pity calculation and item icons |
| `POST` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/sync` | Queues automatic pull sync via `stoken_v2`, or the HoYoLAB cookies for Zenless Zone Zero (returns `operation`; `400` when the profile cannot sync) |
| `POST` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/import-powershell` | Validates a PowerShell-extracted Gacha URL and queues import on `hoyoverse_pulls` (returns `operation`) |
| `POST` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/import-backup` | Validates a backup file (`paimon_moe`, `star_rail_station_csv`, `zzz_rng_moe`) and queues import on `hoyoverse_pulls` (`202` / `400` / `409`) |
| `GET` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/operations/{operationId}` | Returns the latest snapshot of a pull operation (fallback polling when Mercure is unavailable) |
| `GET` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/manual/suggestions?banner=` | S items for the manual S form (`items`, `allow_free_text`, `item_types`) |
| `POST` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/manual` | Adds a missing S pull (`201`; `422` with a `code` on a rule violation, `409` when the profile is busy) |
| `PATCH` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/{pull}/declared-pity` | Declares (or clears with `null`) the real pity of an S pull |
| `DELETE` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/{pull}` | Deletes a manual S pull (`400` for other rows) |
| `GET` | `/api/hoyoverse/pulls/script/powershell` | Returns the `.ps1` PowerShell script to extract the Gacha URL from local cache |
