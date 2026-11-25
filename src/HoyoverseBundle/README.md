# HoYoverse Bundle (`HoyoverseBundle`) — Feature Guide

The **`HoyoverseBundle`** manages full integration with the **HoYoLAB / HoYoverse** ecosystem for **Genshin Impact**, **Honkai: Star Rail**, **Zenless Zone Zero**, **Honkai Impact 3rd**, and **Tears of Themis**.

It supports direct credentials or cookie-based authentication, automatic session refresh, daily check-ins, automated promo code redemption, real-time monitoring of in-game resources (Resin/Trailblaze Power/Battery, expeditions, Realm Currency, shops, endgames), **Diary** synchronization (monthly premium currency income), and a **Pulls Tracker** (automatic sync via `stoken_v2`, PowerShell script import, third-party backup file import, and real-time Mercure progress tracking).

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
| **Endgames Reminder** | ✅ (Spiral Abyss, Imaginarium Theater, Stygian Onslaught) | ✅ (Memory of Chaos, Pure Fiction, Apocalyptic Shadow with Starward Mode; Anomaly Arbitration with Plight) | ✅ (Shiyu Defense, Deadly Assault and its Hard mode) | ❌ | ❌ |
| **Diary Sync (Monthly Currencies)** | ✅ (Primogems, Mora) | ✅ (Stellar Jades, Passes) | ✅ (Polychromes, Tapes) | ❌ | ❌ |
| **Automatic Pulls Sync (`stoken_v2`)** | ✅ | ❌ *(Unsupported by HoYoverse)* | ✅ | ❌ | ❌ |
| **PowerShell Pulls Sync** | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Backup File Pulls Import** | ✅ (`paimon.moe` JSON) | ✅ (`starrailstation.com` CSV) | ✅ (`zzz.rng.moe` JSON) | ❌ | ❌ |
| **Official S-Rank History Import** | ❌ | ❌ | ✅ (HoYoLAB Signal Search) | ❌ | ❌ |

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
| `HasSRankHistoryImportInterface` | `ZenlessZoneZeroSRankHistoryTrait` | `supports_s_rank_history_import` (also needs a usable HoYoLAB cookie) | `getSRankHistory()` reads the official history of the S pulls of every banner (pull number, pity, date, `is_up`), with no 6-month limit, enabling the **Official S-Rank History Import** (*ZZZ*). |
| `HasEndgamesInterface` | `GenshinImpactEndgamesTrait`, `HonkaiStarRailEndgamesTrait`, `ZenlessZoneZeroEndgamesTrait` | `endgamesCheck`, `endgamesSettings` | Static `getEndgamesConfigKey()` and `getEndgameDefinitions()` (`EndgameDefinition`, `GamesBundle\Model\Endgame`) declare the modes; `fetchEndgame()` returns one `GameEndgame` per variant of a mode: end of the period, played, maximum, highest level completed (variants with levels), progress lines. Enables the **Endgames Reminder** (*GI, HSR, ZZZ*). |

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
| `HasStokenPullImportInterface` | `HoyoversePullTrait` | `syncPulls`, `supports_pull_sync: true` | Extends `HasPullTrackerInterface`: the game can generate a gacha `authkey` directly from `stoken_v2` via Takumi API (*GI*, *ZZZ*), enabling the **Auto Sync Pulls** toggle and 1-click **Sync Pulls** button. |
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

### 2.2 Login / Update via Cookie String ("Paste Cookie")
As an alternative to credentials login, the **Paste Cookie** tab allows pasting a HoYoLAB cookie string manually:
- **Minimum requirements**: `ltoken_v2`, `ltuid_v2`, `ltmid_v2`.
- **Optional but recommended tokens**:
  - `stoken_v2`: enables continuous automatic cookie refresh and automatic pulls sync for Genshin Impact and Zenless Zone Zero.
  - `cookie_token_v2` and `account_id_v2`: required for automatic promo code redemption when `stoken_v2` is not present.
- **Account identity**: the account UID is read from `ltuid_v2`/`account_id_v2` (or the legacy `ltuid`/`account_id`). A new cookie is deduplicated by that UID; on re-login of an existing account (`account_id` in the payload) a cookie of another HoYoverse account is refused with the same `422 ... does not match existing Account ...` error as the credentials login, and nothing is saved.
- **Game profiles**: once the cookie is saved, the game profiles are synced right away, like after a credentials login (`HoyoverseAuthService::syncGameProfilesAfterLogin()`). A failed sync is only logged: the cookie stays saved and the profiles can be synced later from the account card.
- **Game profiles from the CLI**: `php bin/console hoyoverse:fetch-game-profiles [<accountId>]` syncs them again from the game record cards, for one account or, without an id, for every account (shared flow: [`AbstractFetchGameProfilesCommand`](../GamesBundle/README.md#1-backend)).

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
   - If unclaimed, sends the `sign` request and fetches today's reward from `home` (e.g. *Primogems x20*).
   - Always notifies the result (reward and monthly check-in total), also when the reward was already claimed.
2. **Missed Check-In (`HoyolabMissedCheckInBatchMessage`)** — Schedule: `0 14 * * *`
   - For profiles with `hoyolabMissedCheckIn = true`, runs the same check-in at midday: a reward still unclaimed (auto check-in disabled or failed) is **claimed** and notified; an already claimed one sends nothing. It is not a reminder: nothing is left for the player to do, except after a captcha (below).

**Captcha on `sign`**: HoYoLAB can answer the claim with `retcode 0` / `OK` and a GeeTest challenge instead of the reward: `data.gt_result` with `is_risk: true` and a non-zero `risk_code` (`5001`, `5003`), or the older flat `data.risk_code` (`375`). Nothing was claimed: `signIn()` throws `SignInRiskException`, and both tasks notify a red **Daily Check-In Failed** asking to check in by hand on HoYoLAB, with no reward fields. The handler returns normally: a Messenger retry would call `sign` again within a minute and meet the captcha again. When enabled, the missed check-in task tries once more at its own time (and notifies again if the captcha comes back).

**Status read**: `HasCheckInStatusInterface::getCheckInStatus()` (all five games, through `GameService`) reads the `info` endpoint only, never `sign`, for the status views; the answer is cached 10 minutes per profile (`hoyoverse.check_in_status.cache`) and deleted by `checkIn()` once the reward is claimed or found claimed. A failed or captcha-blocked claim keeps the entry, which still reads "not signed".

**Run a task now**: `php bin/console hoyoverse:task [<task>]` runs any scheduled task by hand (check-in, codes, reminders, diary and pulls sync, cookie renewal), as the scheduler does for every eligible profile or, with `--profile-id`, for given profiles whatever their settings. `--list` shows the tasks, `--region` limits a regional task, `--sync` handles the messages in the console instead of queuing them (shared flow: [`AbstractTaskCommand`](../GamesBundle/README.md#1-backend)).

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

**Embeds**: each handler checks whether to notify (threshold, all done...) and passes the data to a presenter (`GamesBundle\Presenter\{Stamina,Dailies,Weeklies,Endgames}Presenter`, `HoyoverseBundle\Presenter\{Expeditions,RealmCurrency,ShopStatus}Presenter`), which builds the embed without any check, so other callers can show the same data (see the [GamesBundle design notes](../GamesBundle/README.md#4-design-notes)).

**Notes cache**: `NotesTrait::getNotes()` keeps the raw `dailyNote` payload of each game profile in `hoyoverse.notes.cache` for 5 minutes, so the stamina, expeditions, realm, shop, dailies and weeklies checks of one profile share one HoYoLAB call (see the [GamesBundle design notes](../GamesBundle/README.md#4-design-notes)). Errors and `-501000` answers are not cached; `getNotes($account, fresh: true)` bypasses the cache and stores the new answer. The notes carry the fetch time of their entry, and the getters turn the relative times of the payload (stamina, realm currency, expeditions) into instants, so the embeds show the time left when they are sent, not when the notes were fetched.

### 5.1 Stamina Reminder (`StaminaCheckBatchMessage`)
- **Schedule**: `*/30 * * * *` (every 30 minutes).
- **Per-Profile Configuration**: `staminaCheck` toggle and customizable `staminaThreshold` (`-1` alerts only when reaching max cap: `200` on GI, `300` on HSR and ZZZ).
- **No anti-spam**: every run notifies while the stamina is at or above the threshold (or full, with `-1`), so the reminder repeats every 30 minutes until the stamina is spent. The 5-minute notes cache only saves HoYoLAB calls.

### 5.2 Expeditions Reminder (`ExpeditionsCheckBatchMessage`)
- **Schedule**: `*/30 * * * *` (every 30 minutes) — *Genshin Impact, Honkai: Star Rail*.
- **Behavior**: when **all** dispatched expeditions/assignments are `Finished`, notifies with `🧭 Expeditions` (`4/4 completed`; the embed also shows `🕒 Completion Time` while some are still out, for other callers). No deduplication: every run notifies until the expeditions are dispatched again.

### 5.3 Realm Currency Reminder (`RealmCurrencyCheckBatchMessage`)
- **Schedule**: `0 * * * *` (hourly) — *Genshin Impact (Serenitea Pot)*.
- **Behavior**: same logic as Stamina Reminder; alerts when Teapot currency exceeds `realmCurrencyThreshold` (or reaches max cap when `-1`).

### 5.4 Shop Status Reminder (`ShopStatusCheckBatchMessage`)
- **Schedule**: `0 * * * *` (hourly) — *Zenless Zone Zero*.
- **Behavior**: monitors the Video Store status (`VhsSaleState`) and notifies when the store has finished selling (`SaleStateDone`), with `📼 Shop Status` (`Closed`, `Open` or `Finished`).

### 5.5 Dailies & Weeklies Reminders (`DailiesReminderBatchMessage` / `WeekliesReminderBatchMessage`)
- **Regional Schedule**:
  - **Dailies**: `0 21 * * *` (21:00 local server time for `SEA` / `EU` / `NA`).
  - **Weeklies**: `0 21 * * 0` (every Sunday at 21:00 local server time).
- **Behavior**: checks all daily or weekly metrics for the game (e.g. 4/4 Commissions on GI, 500 Activity Points or Echo of War on HSR, Engagement/Scratch Card/Coff Cafe/Bounty Commissions on ZZZ) and sends a reminder **only if at least one activity is incomplete**.

### 5.6 Endgames Reminder (`EndgamesReminderBatchMessage`)
- **Regional Schedule**: `0 15 * * *` (15:00 local server time) — *Genshin Impact, Honkai: Star Rail, Zenless Zone Zero*.
- **Cache and status**: every battle record request is cached 30 minutes per profile and request (`hoyoverse.endgames.cache`, `CachedEndgameRequestTrait`, raw payload with its fetch time, `GameEndgame::getFetchedAt()`); `fetchEndgame($definition, $account, fresh: true)` reads again. The reminder always reads fresh, through `GamesBundle`'s `EndgameStatusBuilder`, which the endgames status section shares.
- **Modes** (`HasEndgamesInterface`, static methods in the game trait, declared `final` so that test doubles keep them): a configuration key per game (`gi`, `hsr`, `zzz`) and an `EndgameDefinition` per mode (key, name, variants). A variant played at a difficulty the player picks declares its `EndgameLevel`s (key, name, easiest first). Genshin Impact: `spiral_abyss`; `imaginarium_theater` with the levels `easy`, `normal`, `hard`, `visionary`, `lunar`; `stygian_onslaught` with `normal`, `advancing`, `hard`, `menacing`, `fearless`, `dire`. Zenless Zone Zero: `shiyu_defense`; `deadly_assault` with two variants, `reminder` (normal mode) and `hard_reminder` (Hard mode: same request and period, its own setting). Honkai: Star Rail: `memory_of_chaos`, `pure_fiction`, `apocalyptic_shadow` (variants `reminder` and `starward_reminder`, Starward Mode being an extra challenge on the final stage, `off` by default) and `anomaly_arbitration` (variants `reminder` and `hard_reminder`, Plight; both `off` by default).
- **Settings**: `endgamesCheck` (*Endgames Reminder*) turns it on; `endgamesSettings` (JSON, keyed like the modes) chooses per variant an `EndgameReminder`: `off`, `not_played` (until the mode is played) or `full_clear` (until the maximum), e.g. `{"shiyu_defense": {"reminder": "full_clear"}, "deadly_assault": {"reminder": "not_played", "hard_reminder": "off"}}`. A variant with levels takes a level key in place of `full_clear` (until that difficulty or a harder one is completed), e.g. `{"imaginarium_theater": {"reminder": "visionary"}}`; `full_clear` stays valid there and is stored as the top level. Stored as the top level's key, it is a fixed choice: if HoYoverse adds a harder level, the profiles that chose *Full Clear* stay on the previous top level (`lunar`, `dire`) until the setting is changed. `EndgameSettings::resolve()` gives an `EndgameTarget` per variant (reminder and chosen level). Missing or invalid entries, levels of another variant included, take the variant's default (`not_played`; `off` for the Hard mode, Starward Mode, Anomaly Arbitration and Plight). `HoyoverseGameProfileNormalizer` keeps only the declared modes, variants and values, merges a partial update over the stored settings, and serializes the effective settings plus the list of modes (`endgames`) the settings drawer is built from; a variant with levels has them in `levels` (`key`, `name`) and the drawer shows `Off / Not Played / <levels>` for it.
- **Alert Window**: per mode, `hoyoverse.endgames_reminder.<game>.<mode>.days_before_end` (default `7`, counted in 24-hour days from the run). The configuration tree is generated by `HoyoverseBundle::configure()` from the definitions of the game services found in `src/Service/*Service.php`, so an unknown game or mode in `config/packages/hoyoverse.yaml` is an error. With the 15:00 run and a period ending at 03:59:59 server time, the first reminder comes on the 7th day before the end and the last one the day before. No notification when nothing is left to do; no deduplication (one run per day).
- **Data, Genshin Impact** (`GenshinImpactEndgamesTrait`, no `DS` header):
  - *Spiral Abyss*: `game_record/genshin/api/spiralAbyss` (`role_id`, `server`, `schedule_type=1` = current period, `2` = previous one) → `SpiralAbyssData`, `SpiralAbyssFloor` (`floors[]` lists only the floors played).
  - *Imaginarium Theater*: `game_record/genshin/api/role_combat` (`need_detail=false`; the `stat` block is there anyway) → `TheaterLobbyData`, `TheaterLobbyType`, `TheaterStat`. `data[]` holds the current period (`schedule.schedule_type = 1`) and the previous one (`2`).
  - *Stygian Onslaught*: `game_record/genshin/api/hard_challenge` → `StygianOnslaughtData`, `StygianOnslaughtType`, `StygianOnslaughtRecord` (`single` = solo, `mp` = co-op), `StygianOnslaughtBest`. `data[]` holds the current period and the previous one, without `schedule_type`, and periods have a gap between them: the current period is the one whose schedule contains now (a clock injected with `setEndgamesClock()`).
- **Data, Zenless Zone Zero** (`ZenlessZoneZeroEndgamesTrait`, no `DS` header, `schedule_type=1` = current period, `2` = previous one):
  - *Shiyu Defense*: `game_record_zzz/api/zzz/hadal_info_v2` (`role_id`, `server`), with the per-stage detail (no `without_v2_detail`) → `ShiyuDefenseData`, `ShiyuDefenseStage`.
  - *Deadly Assault*: `game_record_zzz/api/zzz/hadal_mem_detail_v2` (`uid`, `region`) → `DeadlyAssaultData`, `DeadlyAssaultChallenge` (normal mode `list`, Hard mode `hard_list`).
- **Rules, Genshin Impact and Zenless Zone Zero** (constants and methods of `SpiralAbyssData`, `TheaterStat`, `TheaterLobbyType`, `StygianOnslaughtType`, `ShiyuDefenseData` and `DeadlyAssaultData`, the place to change them). A mode is locked only when the response says so (`is_unlock: false`, seen for the Spiral Abyss of an account that has not reached it):

  | Mode | Played | At the maximum |
  | :--- | :--- | :--- |
  | Spiral Abyss | at least one battle (`total_battle_times > 0`): the floors skipped at the start of a period (9 and 10) give 18 stars without a battle | 36★ (`total_star`) |
  | Imaginarium Theater | a record in the current period (`has_data`, assumed to include a run not finished yet) | Lunar completed (the top level). Level completed: the highest L with `difficulty_id ≥ L` and `max_round_id ≥` the acts of L (Easy 3, Normal 6, Hard 8, Visionary 10, Lunar 10), Lunar also needing both Arcana (`tarot_finished_cnt ≥ 2`): Lunar up to act 8 = Hard. Assumes `max_round_id` is the last act completed. The stars decide nothing |
  | Stygian Onslaught | a record solo or in co-op (`single.has_data` or `mp.has_data`), with or without a best | Dire completed (the top level). Level completed: the difficulty of the best (`best.difficulty` 1–6 = Normal, Advancing, Hard, Menacing, Fearless, Dire), the higher of solo and co-op. Assumes a best is a win |
  | Shiyu Defense | floor 4 attempted (rated or with teams), or a score or a rating on floor 5 (floors 1–3 are completed automatically) | floor 4 at S rank and the three floor-5 stages at S rank, as rated by HoYoLAB (`S…` prefix, no score threshold) |
  | Deadly Assault | an attempt with a score or stars | 9★ (3★ on each of the three bosses, or `total_star` while the per-boss list is still empty) |
  | Deadly Assault (Hard) | reminded only once unlocked: 9★ in normal mode, or `has_hard` (which only says the period has Hard mode data: false at 9★ before trying it); then an attempt with a score or stars | 3★ (one boss) |
- **Data, Honkai: Star Rail** (`HonkaiStarRailEndgamesTrait`, host of the notes `bbs-api-os.hoyolab.com/game_record/hkrpg/api/`, `role_id` and `server`, **`DS` header required**: without it the answer is `-10001`; `schedule_type=1` = current period). One request per mode with a reminder on:
  - *Memory of Chaos*, *Pure Fiction*, *Apocalyptic Shadow*: `challenge`, `challenge_story`, `challenge_boss` with `need_all=true` → `HonkaiStarRailChallengeData` (one shape for the three), `HonkaiStarRailChallengeFloor` per stage reached (`all_floor_detail`, highest first), `HonkaiStarRailEndgamePeriod` per period (`groups`).
  - *Anomaly Arbitration*: `challenge_peak` → `AnomalyArbitrationData`, `AnomalyArbitrationRecord` per run, `AnomalyArbitrationBossRecord`.
- **Rules, Honkai: Star Rail** (constants and methods of the DTOs, the place to change them):

  | Mode | Variant | Played | At the maximum |
  | :--- | :--- | :--- | :--- |
  | Memory of Chaos | `reminder` | `battle_num > 0` (quick-cleared stages carry stars without a battle) | `star_num` ≥ 36 |
  | Pure Fiction, Apocalyptic Shadow | `reminder` | `battle_num > 0` | `star_num` ≥ 12 |
  | the three above | `starward_reminder` | a stage with `is_tierce`, or the Prismatic Star | `extra_star_num` ≥ 1 |
  | Anomaly Arbitration | `reminder` | a record of the run (`has_challenge_record`), or Plight cleared | Knights 9★ and King 3★ (`mob_stars`, `boss_stars`), or Plight cleared |
  | Anomaly Arbitration | `hard_reminder` | `boss_record.hard_mode` (Plight has no stars: played and maximum are the same) | the same |
- **Notification**: one per profile, one row per mode with three inline fields: each variant (`Not played yet`, or its progress lines: `7/9 ★` (the star last everywhere: `33/36 ★`, `Prismatic 0/1 ★`, `Knights 9/9 ★`, `King 2/3 ★`, `Cleared` for Plight), the stages of Memory of Chaos, Pure Fiction and Apocalyptic Shadow below 3★ that the response lists (`↳ <stage>: 2/3 ★`, the Prismatic Star not counted, no line for a stage that is not listed), or for Shiyu Defense one line per floor with its own icon, `⚔️ Floor 4: A`, `⚔️ Floor 5: 2/3 S`, then the stages below S, `↳ Stage 2: A (21,000)`; Spiral Abyss `30/36 ★` then `↳ Floor 12: 6/9 ★` for each floor played below 9★; Imaginarium Theater `Lunar: Act 10/10, Arcana 2/2` (Arcana in Lunar only) and `8/12 ★` (stars available: the acts of the difficulty, plus the two Arcana in Lunar); Stygian Onslaught `Best: Menacing (3:03)`, the faster run at equal difficulty, or `No difficulty completed yet`; ✅ when at the maximum, empty when off or locked), then `🕒 Ends in` (`DurationFormatter`, two units). Empty fields (`"\u{200b}"`) keep the rows aligned on Discord and are skipped by Telegram. The handler picks the modes to remind and passes them as `EndgameModeStatus` (variants turned off left empty) to `GamesBundle`'s `EndgamesPresenter`, which lays out the rows.
- **Dates**: The Genshin Impact modes use their epoch `end_time` (top level for the Spiral Abyss, in `schedule` for the other two). Shiyu Defense uses its epoch `end_time`. Deadly Assault and the Star Rail modes only have `{year, month, day, …}` objects, which are server wall-clock times: `HoyoverseServerTimezoneResolver::serverWallClockToInstant()` reads them in the fixed offset of the profile's server (§7.5); the helpers shared by Zenless Zone Zero and Star Rail are in `HoyolabEndgameDatesTrait`. In Star Rail the current period is picked by date (`begin ≤ now < end`, among `groups` or among the runs of `challenge_peak`), never by `status`, which cannot be trusted; none contains now (empty groups or dates, the hour between two Anomaly Arbitration runs) = no end date, so no reminder. The clock comes from `setEndgamesClock()`.
- **Unexpected responses**: missing or `null` fields, empty lists and `has_data: false` count as not played; a mode without an end date gets no reminder (`info` log); empty date objects (all `null`, zeros) count as no date; a Shiyu Defense response whose `hadal_ver` is not `v2` is left out with a `warning` in the `hoyoverse.zzz` log. Genshin Impact: an epoch that is empty or `0` counts as no date; a Theater response without a `schedule_type = 1` period and Stygian Onslaught between two periods give a result without an end (`info` log of the handler, no reminder); a difficulty above the known ones (Theater above Lunar, Stygian above Dire) leaves the mode out with a `warning` in the `hoyoverse.gi` log. HoYoLAB updates the totals before the per-floor and per-boss detail (seen right after a run): a Shiyu Defense score without the floor detail is left out of that run (`info` log), Deadly Assault falls back to `total_star`. `EndgamesReminderProfileHandler` reads each mode on its own and skips the modes whose variants are all `off`: one whose request or shape fails is left out with a `warning` (exception class and retcode only) and the others are still reminded; when every requested mode fails the first error is thrown, so an expired cookie fails the task like in the other reminders.
- **Other games**: a game gets the reminder (configuration, settings, notification) by implementing the interface; at most two variants per mode fit in a notification row.

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

### 7.2 Automatic Sync via `stoken_v2` (Genshin Impact & Zenless Zone Zero)
For games implementing `HasStokenPullImportInterface` (**Genshin Impact** and **Zenless Zone Zero**), when the account has `stoken_v2` (obtained automatically via Email/Password login):
1. `HoyolabCookieUtilsService::generateAuthKey()` generates a temporary `webview_gacha` `authkey` on the fly signed with `DS` LK2 (`salt=ulInCDohgEs557j0VsPDYnQaaz6KJcv5`) via `https://api-account-os.hoyoverse.com/binding/api/genAuthKey`.
2. **Configured Banners**:
   - **Genshin Impact**: *Character Event Wish* (`301` + `400`, pity 90/10), *Weapon Event Wish* (`302`, pity 80/10), *Standard Wish* (`200`, pity 90/10), *Beginners' Wish* (`100`, pity 20/10), *Chronicled Wish* (`500`, pity 90/10).
   - **Zenless Zone Zero**: *Exclusive Channel* (`2`, pity 90/10), *W-Engine Channel* (`3`, pity 80/10), *Standard Channel* (`1`, pity 90/10), *Bangboo Channel* (`5`, pity 80/10), *Exclusive Rescreening* (`12`, pity 90/10), *W-Engine Reverberation* (`13`, pity 80/10).
3. **Execution Modes**:
   - **Nightly Auto Sync (`SyncPullsBatchMessage`)**: runs daily at `01:00` (`0 1 * * *`) for all profiles with `syncPulls = true` enabled in `GameProfileSettingsDrawer`.
   - **1-Click Manual UI Sync**: click **Sync Pulls** on `PullsPage`.

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
All asynchronous pull operations — **Backup Import**, **PowerShell Import**, **Manual SToken Sync**, **Scheduled Sync** and **Official S-Rank History Import** (`GamesBundle\Model\Pull\PullOperationKind`) — are tracked uniformly by `HoyoverseBundle\Service\Pull\PullOperationTracker` (extending `GamesBundle\Service\AbstractPullOperationTracker`):
- **Cache Snapshot + Mercure Updates**:
  - Operation state is stored in `hoyoverse.pull_import_status.cache` (1-hour TTL, configured in `src/HoyoverseBundle/config/packages/cache.yaml`) and published as a **private** Mercure update on `https://{games.domain_name}/hoyoverse/profiles/{profileId}/pulls/progress` (payload `{ action: 'pulls:operation_progress', operation: {...} }`).
  - `GET .../pulls/overview` sets the `mercureAuthorization` cookie for the profile's topic and returns `mercure_topic`, `available_backup_importers`, and any currently running `active_operation`.
- **Shared Live UI (`@GamesBundle/components/pulls/*` on `PullsPage`)**:
  - `PullsPage` uses the shared `usePullOperationProgress` hook, `PullOperationProgressBanner`, and `BackupPullImportModal` to open an authenticated `EventSource` against `MERCURE_HUB_URL`, updating the progress bar, counters (`processed / total`, `imported`, `skipped`, `promoted/linked`), and per-banner sync status in real time.
  - If Mercure is unreachable, it automatically falls back to **1-second HTTP polling** against `GET .../pulls/operations/{operationId}` while the operation is active.
  - Upon transitioning to `completed` or `failed`, the page automatically refreshes the overview and selected banner view (a failed operation may have written part of its pulls).

### 7.7 Missing Pulls (Manual S Pulls, Declared Pity, Filler Rows)
- **Rows**: `hoyoverse_pull.origin` (`PullOrigin`: `recorded` / `manual` / `official` / `filler`; `official` rows come from the S-rank history import, § 7.8) and `declared_pity` (real pity declared on an S). A manual S has `pull_id = manual_<uuid>`, an official S `official_<uuid>`; a filler (unknown pull) has `pull_id = filler_<uuid>`, rarity `0`, name `Unknown`, type `Unknown`. All use the group's first gacha type (`301` for the Character Event Wish). `is_up` (nullable) says whether an S was the rate-up item when a source gives it; `pull_number` (nullable) is the pull number in the banner, only on official S rows and on the fillers between two of them in one second, never on recorded rows: it only orders the rows of one second.
- **Order** (`HoyoversePullRepository::findBannerRows()`, used by the stats, the lists and the realign): `pulled_at`, then `pull_number` (`NULL` first), then `pull_id` as a string, so inside one second: game ids < `filler_*` < `manual_*`, then the numbered rows by number. `HoyoversePullRepository::compareRows()` is the same order in PHP.
- **Counting** (`HoyoversePullStatsCalculator`): fillers count as pulls (pity S, `pull_number`, totals, currency, monthly) but go to `unknown_count` instead of `b_count`, show `pity: null`, and reset the A pity. Rows expose `origin`, `declared_pity`, `is_up` and the stored pull number as `official_pull_number` (`HoyoversePullStatsCalculator::STORED_PULL_NUMBER_FIELD`; `pull_number` in the API stays the computed position); S rows also `recorded_pity`, `insert_after`, `insert_before`, `suggested_at`, `has_room`; summaries `unknown_count` and `open_window`. The list accepts rarity `0`.
- **Writes** (`ManualPullService`, profile lock taken non-blocking, `409` when busy): add an S (`createManualS`), declare a pity (`declarePity`, stored as `null` when equal to the recorded pity on a recorded S), delete a manual S. Each write runs `realign()` for its banner (`PullGapPlanner`: merges a manual or official S into the recorded S that arrives later, then creates, moves or deletes fillers).
- **Automatic realign**: at the end of a backup import (`realignAll()`, not on dry runs) and on the last page of each banner sync. `findExistingLogicalPullCounts()` ignores non-recorded rows.
- **Dates**: server wall-clock time tagged as UTC (`HoyoverseServerTimezoneResolver`); "now" is the profile's server time (`getServerWallClockNow()`). `pulled_at` uses the shared `wall_clock_datetime_immutable` type (`App\CoreBundle\Doctrine\WallClockDateTimeImmutableType`), read back as UTC, so times in the hour skipped when DST starts in Europe/Rome are never shifted.
- **Suggestions** (`ManualPullSuggestionProvider`): S items of the Nanoka catalog plus the recorded S pulls of the game; free text allowed (with a required item type).

### 7.8 Official S-Rank History Import (Zenless Zone Zero: HoYoLAB Signal Search)
The "Signal Search" statistics of the HoYoLAB battle record list every S-rank of every banner since the start of the account, without the 6-month limit of `getGachaLog`, each with its pull number in the banner (`sort`), its pity (`count`), its date (`record_time`, epoch) and whether it was the rate-up item (`is_up`). A manual import fills the missing pulls with them (shared rules: GamesBundle § 4.1 and § 4.2).

- **Reading** (`ZenlessZoneZeroSRankHistoryTrait`, `https://sg-act-public-api.hoyolab.com/event/nap_gacha_record/api/zzz/`, `uid`, `region`, `lang=en-us`, HoYoLAB cookie only: no `stoken_v2`, no `DS`): `index` (one `type_stats` entry per banner: `total_count`, `s_count`, `has_data_vers`, `next_s_cur_times`; plus `cur_ver`), then per banner `hist_pool_stat` for the versions of `has_data_vers` except `cur_ver`, at most 10 per request (more, or an unknown version, give `retcode -400005`), and `cur_pool_stat`. About 15 requests for a whole account, one second apart. Banner types `GACHA_TYPE_*` map to the configured banners (requests use `102`/`103` for the reruns, stored pulls `12`/`13`); item types `ItemType_Avatar`/`Weapon`/`Bangboo` to `Agents`/`W-Engines`/`Bangboo`; epochs to the server wall clock. A failed request, an unreadable record, a missing `cur_ver` or a banner type listed twice fails the whole import before anything is written.
- **Writing** (`Service/Pull/SRankHistoryImportService`, profile lock taken before the first request): per configured banner, `SRankHistoryReconciler` plans the banner; a refused banner is left unchanged (`skipped`, with the reason); a banner with nothing to change and its fillers in line is not written at all, so a second import writes nothing; otherwise, in one transaction, the matched S are updated, the official S created (`official_<uuid>`, `pull_number`, `declared_pity`, exact `pulled_at`, `is_up`), `ManualPullService::realign()` adds the fillers, and every record must then be at its pull number in the stored order, or the banner is rolled back (`failed`). Every replaced user value (declared pity, date or pity of a manual S) is listed first in the warnings of the operation, prefixed with the banner name, and never cut by the 50-warning limit of the others (dry run included); totals and S counts that differ from `index` are warnings, unless the banner would end with more pulls than both the stored rows and `total_count` (conflict, GamesBundle § 4.2).
- **Start**: `POST .../pulls/import-s-rank-history` and the `--async` option of the command go through `SRankHistoryImportLauncher` (`400` for a game without the capability or an account whose HoYoLAB cookie cannot be parsed, `409` while one is queued or running, `202` with the operation otherwise), which queues `ImportSRankHistoryMessage` on `hoyoverse_pulls`; `ImportSRankHistoryHandler` re-queues it while the profile lock is busy, like a backup import. The operation (`s_rank_history_import`) is tracked per banner.
- **UI**: `Import ▾` → *Import S-rank history from HoYoLAB* (`SRankHistoryImportModal`). Official S show a `HoYoLAB` tag and no row actions.
- **CLI**:
  ```bash
  # Read, check and simulate every banner, print what would change, write nothing
  php bin/console hoyoverse:import-s-rank-history <profileId> --dry-run

  # Synchronous import, or queued on hoyoverse_pulls
  php bin/console hoyoverse:import-s-rank-history <profileId>
  php bin/console hoyoverse:import-s-rank-history <profileId> --async
  ```
  The command fails (exit code 1) when a banner is, or in a dry run would be, skipped or rolled back.
- Out of scope: `next_s_cur_times` (current pity after the last S) is read but not used; Genshin Impact and Honkai: Star Rail have no equivalent endpoint implemented.

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
| `POST` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/sync` | Queues automatic pull sync via `stoken_v2` (returns `operation`) |
| `POST` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/import-powershell` | Validates a PowerShell-extracted Gacha URL and queues import on `hoyoverse_pulls` (returns `operation`) |
| `POST` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/import-backup` | Validates a backup file (`paimon_moe`, `star_rail_station_csv`, `zzz_rng_moe`) and queues import on `hoyoverse_pulls` (`202` / `400` / `409`) |
| `POST` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/import-s-rank-history` | Queues the official S-rank history import (Zenless Signal Search) on `hoyoverse_pulls` (`202` / `400` / `409`) |
| `GET` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/operations/{operationId}` | Returns the latest snapshot of a pull operation (fallback polling when Mercure is unavailable) |
| `GET` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/manual/suggestions?banner=` | S items for the manual S form (`items`, `allow_free_text`, `item_types`) |
| `POST` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/manual` | Adds a missing S pull (`201`; `422` with a `code` on a rule violation, `409` when the profile is busy) |
| `PATCH` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/{pull}/declared-pity` | Declares (or clears with `null`) the real pity of an S pull |
| `DELETE` | `/api/hoyoverse/accounts/{account}/gameProfiles/{gameProfile}/pulls/{pull}` | Deletes a manual S pull (`400` for other rows) |
| `GET` | `/api/hoyoverse/pulls/script/powershell` | Returns the `.ps1` PowerShell script to extract the Gacha URL from local cache |
