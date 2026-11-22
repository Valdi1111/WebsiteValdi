# Perfect World Bundle (`PerfectworldBundle`) — Feature Guide

The **`PerfectworldBundle`** is the Perfect World provider of the unified games frontend ([`GamesBundle`](../GamesBundle/README.md), tab **Perfect World** on `games.<domain>`). It currently manages **Neverness to Everness (NTE)** (`game_id = 1`, `game_biz = nte`).

It supports Perfect World Games account login (email + one-time code, or a token pasted from the NTE website), game profile sync from the account role list, and a pull tracker fed **only by manual imports** of [nte-exporter](https://github.com/RishiMenon2004/nte-exporter) files: the gacha history travels only inside the game protocol, so there is no server-side sync.

---

## 1. Feature Overview

| Feature | Status | Details |
| :--- | :---: | :--- |
| **Email + code login** | ✅ | `i.pwsdk.com` web SDK, no password and no captcha; the 30-day session token is stored |
| **Token login** | ✅ | Paste the `_websdk_` Local Storage value (`{"uid":…,"token":…}`) from the NTE website; works for Google/Steam/PlayStation accounts |
| **Account deduplication & safe re-login** | ✅ | Keyed by the Perfect World account `uid`; re-login (email or token) must return the same account |
| **Profile sync** | ✅ | Role list: UID, nickname, level, server/region, avatar |
| **Pull tracker (manual import)** | ✅ | Up to 3 nte-exporter files at once (one per banner) with live per-banner Mercure progress |
| **Missing pulls** | ✅ | Add an S missing from the history or declare the real pity of an S; unknown "filler" pulls make the pity add up |
| **Item catalog & icons** | ✅ | Names, ranks, types and icons from the [Waifus-Grace/NTE_Assets](https://github.com/Waifus-Grace/NTE_Assets) datamine, refreshed weekly |
| Check-in, stamina, codes, pull sync | — | No global web API exists for NTE |

### Game service

`NevernessToEvernessService` implements the bundle `Model/Game/GameInterface` (static `getGameBiz()`/`getGameId()`, region and timezone of SDK servers), `Model/Game/HasPullTrackerInterface` (`getGachaConfig()`, `getRarityConfig()`, `getBannerPullLabels()`) and the shared `HasManualPullsInterface` marker (`supports_manual_pulls` in the profile JSON). It is located through the `perfectworld.game.id` / `perfectworld.game.biz` tags, like the game services of the other providers: the bundle is meant to host every Perfect World game, even though NTE is the only one today.

| SDK server id | Region | Display timezone (fixed offset, no DST) |
| :--- | :--- | :--- |
| `23001` | AS (Asia) | `Etc/GMT-8` |
| `23002` | NA_SA (America) | `Etc/GMT+5` |
| `23003` | EU (Europe) | `Etc/GMT-1` |
| `23004` | SEA | `Etc/GMT-8` |

Rarity is S/A/B, stored as 5/4/3 (`RarityConfig`); the game-wide unit label is "Pulls", each banner has its own ("Rolls" or "Arcs").

---

## 2. Authentication (`PerfectworldSdkClient`, `PerfectworldAuthService`)

All SDK calls are GET requests to `https://i.pwsdk.com` with NTE constants (`appId=3000001`, `packageName=com.hottagames.nte`, `osType=6`, `sdkServerVersion=20260702`). Without a `callback` parameter the SDK answers plain JSON.

| Call | Endpoint | Notes |
| :--- | :--- | :--- |
| Send code | `/web/user/email/code/getV0.do` | captcha-free variant used by the NTE website |
| Login with code | `/web/user/email/login/code.do` | returns `uid` (Perfect World account id) and `token` |
| Token expiry | `/web/user/token/info.do` | needs the `ndid` of the same login and `xClientData`; returns `tokenExpireTime` (30 days after login) |
| Role list | `/game/getRoleList/bi.do` | `roleId` (= in-game UID), `roleName`, `serverId`, `serverName`, `lev`, `avatar` |

- Code `20001` means the token is invalid or expired: the account is flagged `token_invalid` and must log in again (`PerfectworldAuthException`, HTTP 409 on sync).
- `ndid` is a browser fingerprint for the SDK: a random 64-hex value is generated per login and stored with the account.
- The `token` column is encrypted in the database when `DATABASE_ENCRYPTION_KEY` is set (see [Credentials encryption at rest](../GamesBundle/README.md#credentials-encryption-at-rest)); `ndid` is not.
- Sessions coexist (a server login does not log the browser out) and there is no refresh token.
- **Credentials are in the query string**, so the HTTP client is declared inline with `#[AutowireInline([HttpClient::class, 'create'], …)]`: no logger, no profiler tracing, no retry (a retry would send a second email). Errors are rethrown without the original exception, whose message contains the URL.

### Flows

1. `POST /accounts/login/start {email}` (or `/accounts/{id}/relogin/start`) sends the code and opens a login session (`AuthSessionStore`, 10 minutes).
2. `POST /accounts/login/verify-email {login_session_id, code}` logs in, creates or updates the account, reads the token expiry (best-effort) and syncs the profiles.
3. `POST /accounts/login/resend-email {login_session_id}` sends a new code (same device id).
4. `POST /accounts {websdk}` adds an account from a pasted token; `POST /accounts/{id}/token {websdk}` re-logs an existing one.
5. `POST /accounts/{id}/sync` refreshes the profiles from the role list.

Account ownership is checked by the shared `GamesBundle\Security\GameAccountOwnerVoter` (`GAME_ACCOUNT_OWNER`, see the [GamesBundle design notes](../GamesBundle/README.md#4-design-notes)); profile routes also check that the profile belongs to the account.

---

## 3. Pull tracker

### Banners and pity rules

| Banner (`pool_group_id`) | Name | Unit | S pity | A pity |
| :--- | :--- | :--- | :--- | :--- |
| `Lottery_LimitedCharacter` | Scarborough Fair (Limited Boards) | roll | 90, reset only by an **S character**, shared by all limited boards | rolls since the last Points Gift (an A every 10 rolls) |
| `Lottery_Permanent` | Strange Encounters (Standard Board) | roll | 90, reset only by an S character | rolls since the last Points Gift |
| `Arc_MiracleBox` | Arc Research Program | Arc | 60, reset by any S Arc | Arcs since the last A or S Arc |

Game rules behind the table:
- **Boards** ("Scarborough Fair" limited boards, "Strange Encounters" standard board): each **roll** moves a piece 1-6 tiles and gives the tile reward. The S chance rises after 70 rolls without an S character and the 90th roll guarantees it; a limited board's S is always its featured character.
- **Points Gift**: a bonus earned with every tenth cumulative roll (A character 20%, A Arc 80%). It is not a roll; limited boards share its progress.
- **Slumberland**: landing on that tile starts a chase whose reward (30 Warp Pieces) is not a roll; the tile itself is reached with a normal 1-6 roll.
- S items (Solid Dice, Warp Pieces, liveries) never reset pity: only an S character does.
- **Arc Research Program** (Miracle Box): Arcs are bought in "Limited Issues" of 10; an S is guaranteed every 60 Arcs (the featured S every 80) and an A or better every 10. Pity is shared by all issues.

Implementation:
- `Service/Pull/PerfectworldPullStatsCalculator` produces the same JSON as the other providers' pull repositories, plus `pull_label`/`pull_singular_label` per banner and `roll_label` per row (see the shared fields in the [GamesBundle guide](../GamesBundle/README.md#2-frontend-assets)).
- On boards only S characters carry a pity value (on the Arc banner S and A Arcs do); other rows show "—". Points Gifts and Slumberland rows have no pull number.
- Board summaries: `s_count` = S characters, `a_count` = A characters/Arcs obtained (gifts included), `avg_a_pity` = rolls per A obtained (unknown rolls excluded).
- The predicates are public (`countsAsPull()`, `isS()`, taking the banner group) and also build the entries of the shared missing pulls logic (`toTimelineEntries()`).

### Export format (`nte-history-export` v1)

Produced by [nte-exporter](https://github.com/RishiMenon2004/nte-exporter), which captures the game traffic while the history boards are scrolled (the original [Golumpa/nte-exporter](https://github.com/Golumpa/nte-exporter) is no longer maintained). One file per banner.

| Part | Content |
| :--- | :--- |
| Header | `exporter.version`, `banner.id`, `user_uid` (= in-game UID), `server_id`, `account_region`, `scan` (`pages_seen`, `exported_records`, `skipped_records`, `warnings`) |
| Board record | `uid`, `pool_id` (rotation, 0.5+), `timestamp`, `timestamp_group_ordinal`, `roll_result`, `result_type` (`dice` / `points_gift` / `chase_reward`), `reward_type` (`character` / `arc` / `item` / `cosmetic`), `reward_id`, `reward_name`, `reward_rank`, `quantity` |
| Arc record | same without `roll_result`/`result_type`/`quantity`, `source_type: miracle_box` |

- `roll_result`: 1-6 = dice roll, 0 = Points Gift, -4 = Slumberland chase (the game value is -1; -4 is an exporter artefact).
- Records are newest first; within the same second the first record is the most recent.
- `uid` = `sha256("nte|monopoly|{pool}|{raw_timestamp_hex}|{ordinal}")[:32]`: it depends on packet bytes that are not in the JSON and changes when an exporter version regroups seconds split across history pages, so it cannot identify a record.
- `timestamp_group_ordinal` restarts inside seconds split across pages (duplicate values), so it is not used either.
- Scan policy: history pages hold 5 records; the exporter keeps the run of pages starting at page 1 and reports `PAGE_GAP_DETECTED` / `DID_NOT_START_AT_PAGE_1` in `scan.warnings`.
- `timestamp` is the game display time: the server time (EU = UTC+1) and the player's local time cannot be told apart yet (see [§5](#5-design-notes)).
- **Exporter 0.3.0 bug**: many real rolls come out as `points_gift` with `roll_result` 0 (S characters included). The opposite never happens, which is what makes the repair below possible. Fixed in 0.3.1.
- Name tables are compiled into each exporter release: items added by later game patches come with an empty name and a null rank.

### Import (`NteHistoryExportImporter`, `PullBackupImportService`)

- Format `nte-history-export` v1, one file per banner, up to 3 files per upload (25 MB each). The banner is read from the file header and `user_uid` must match the profile UID.
- The scan must start at page 1 without gaps or skipped records, otherwise positions inside a second would shift.
- **Identity**: `(profile, banner, display time, position in the second)`, position 0 being the most recent record of that second. The exporter `uid` changes between exporter versions (regrouped page splits) and is stored only as a reference; `timestamp_group_ordinal` is never used.
- **Times** are stored exactly as exported (game display time, likely the server time) in a `wall_clock_datetime_immutable` column (`App\CoreBundle\Doctrine\WallClockDateTimeImmutableType`, shared with the HoYoverse and Gryphline pulls), read back in UTC so DST gaps never shift them.
- **Group check**: once Slumberland records are removed, each second holds 1 roll, a roll + its gift, or a 10-roll (10 rolls + gift); any other size rejects the file. Only the **oldest second** may be incomplete (scan stopped before the end of the history, or the game's ~6-month window): fixed exports import it as is (positions stay stable), old exports label it from the phase of the following seconds or leave it out with a warning when that is ambiguous.
- **Exporters older than 0.3.1** label many rolls as Points Gifts. Labels are rebuilt from the game rule (the gift comes with every tenth cumulative roll): the 10 possible starting phases are tried and only those placing every gift on a record the file labels `points_gift` with an A character/Arc reward are kept; all valid phases must agree, else the file is rejected. Relabelled rolls have no dice value (`roll_label` "?").
- **Upsert**: new identities are inserted; an existing record is rewritten only by a newer exporter version (`version_compare`); a different `reward_id` on the same identity aborts that banner before any write.
- After each banner, inside the profile lock, the banner is realigned (see [Missing pulls](#missing-pulls)); then board histories are checked: two consecutive Points Gifts must be 10 recorded rolls apart (filler and manual rows are ignored), otherwise a "possible gap" warning is reported (the game keeps about 6 months of history).
- Imports run on the `perfectworld_pulls` Messenger transport (`ImportPullsBackupMessage`) with per-banner progress published through `Service/Pull/PullOperationTracker` (Mercure topic `https://games.<domain>/perfectworld/profiles/{id}/pulls/progress`).

### Missing pulls

Shared feature (plan in [`docs/PULLS_MISSING_HISTORY_PLAN.md`](../../docs/PULLS_MISSING_HISTORY_PLAN.md), rules in `GamesBundle` `ManualPullGuard`/`PullGapPlanner`), implemented by `Service/Pull/ManualPullService`:

| Endpoint | Route name suffix | Notes |
| :--- | :--- | :--- |
| `GET …/pulls/manual/suggestions?banner=` | `pulls_manual_suggestions` | S characters (boards) or S Arcs (Arc banner) from `NteCatalog`; no free text |
| `POST …/pulls/manual` | `pulls_manual_create` | Manual S: a catalog `item_id` is required (`invalid_item` otherwise) |
| `PATCH …/pulls/{pull}/declared-pity` | `pulls_declared_pity` | Real pity of an S; the recorded value is stored as `null` on a recorded S |
| `DELETE …/pulls/{pull}` | `pulls_manual_delete` | Manual rows only |

- Rows have an `origin` (`recorded`, `manual`, `filler`) and a `declared_pity`. Fillers are unknown pulls created by the realign step so that each declared pity adds up; they count as pulls (S pity, pull numbers, totals, monthly chart) but go to `unknown_count` instead of B, carry no pity and restart the A pity.
- **System rows never take a real slot**: manual and filler rows get a negative `group_position` (one less than the lowest position used at their second); imported records are always ≥ 0, so a later import never meets them on its identity. A filler that moves takes a fresh slot the same way, so a realign is a single flush that cannot hit the unique key, whatever the statement order.
- Filler rows: `kind` `roll` (boards) or `draw` (Arc), `reward_type` `unknown`, `reward_id` `__filler__` (never a catalog id: `NteCatalogBackfiller` leaves them alone), name `Unknown`, rarity 0, `exporter_version` `manual`. Manual S rows: the catalog id, name and type (`character` on boards, `arc` on the Arc banner), rarity 5.
- Dates use the stored wall-clock convention (`WallClockDateTimeImmutableType::fromString()`). As the game timezone is still unknown, "not in the future" accepts up to 24 hours ahead of the server clock; the suggested dates (`suggested_at`) are capped at the server clock without tolerance.
- Writes take the profile lock without waiting (409 `profile_locked` when an import runs) and realign the banner; imports realign each banner after its last flush. A manual S is merged into the recorded S of the same item that a later import brings within 24 hours.
- Banner summaries add `unknown_count`, `open_window` and `oldest_s` (the oldest S, as `recent_s_pulls` is capped at 50); S items add `recorded_pity`, `insert_after`, `insert_before`, `suggested_at`, `has_room`. The list endpoint accepts rarity `0` (fillers).

### Catalog and icons (`Service/Catalog/*`, `Service/PullIcon/NteAssetsIconResolver`)

- `NteCatalogUpdater` builds a compact catalog (`id → name, rank, type, icon path`, plus profile avatars and rotation pool names) from the NTE_Assets tables at a pinned commit, aborts if the README is not a Global version or a required table is missing, merges over the previous catalog and re-resolves the stored pulls (`NteCatalogBackfiller`). A changed rarity or type can change which rows are S, so every profile with a changed row is then realigned under its profile lock, taken without waiting; a profile whose lock is held is skipped (logged at info level), as the import or manual edit holding it, or the next one, realigns it.
- It runs weekly as the `catalog_update` task of the `perfectworld` schedule (cron in `config/packages/perfectworld.yaml`, default Monday 06:00): `NteCatalogUpdateMessage` on the `perfectworld` transport, like the other providers' tasks. An aborted build throws, so the transport retries it; the previous catalog stays in use.
- `perfectworld:nte-catalog:update [--ref=<sha>]` runs the same update by hand (first deploy, or right after a game patch).
- Sources (at the pinned commit): `DataTable/Character/DT_Character` (characters), `DataTable/Fork/DT_ForkItemData` (Arcs), `DataTable/Inventory/DT_ItemConfig` (items, cosmetics) and optional appearance/vehicle/avatar/pool tables, plus `Localization/en/game.json` for names (looked up by `ItemName.Key`; the name embedded in the tables is stale).
- Rank comes from `ItemQuality` (ORANGE = S, PURPLE = A, BLUE = B; other qualities have no rank and the exporter's rank is kept), type from the source table, icon from `ItemIcon.AssetPathName` (`/Game/UI/<path>.<object>` → `<path>.png`; the file name cannot be derived from the id).
- The catalog lives in the `perfectworld.nte_catalog.cache` pool (no expiry, under `var/share`, kept across `cache:clear`).
- Imports take names, ranks and types from the catalog first, then from the file, otherwise `Unknown #<id>` with a warning: exporter name tables are compiled into each release and miss items added by later game patches.
- Icons are served by the shared pull item icon pipeline (`raw.githubusercontent.com/Waifus-Grace/NTE_Assets/{sha}/…`).

---

## 4. Configuration

| Item | Value |
| :--- | :--- |
| Routes | `config/routing.yaml`: host `%games.domain_name%`, prefix `/api/perfectworld`, name prefix `perfectworld_` |
| Roles | `ROLE_ADMIN_PERFECTWORLD → ROLE_USER_PERFECTWORLD → ROLE_USER_GAMES` (migration `Version20261121140400`) |
| Messenger | transports `perfectworld` (scheduled tasks) and `perfectworld_pulls` (imports) |
| Scheduler | schedule `perfectworld` (`ScheduleProvider`, tasks from `config/packages/perfectworld.yaml`) |
| Cache pools | `perfectworld.auth_session.cache` (10 min), `perfectworld.pull_import_status.cache` (1 h), `perfectworld.nte_catalog.cache` (no expiry) |
| HTTP clients | inline SDK client (no logger), `perfectworld.nte_assets.client` (GitHub API + raw files) |
| Logs | channel `perfectworld` (`var/log/perfectworld.log` in prod) |

### Deployment

1. Run the migrations, then `bin/console cache:pool:clear core.security_hierarchy.cache` (done by `website-deploy.sh`) and grant `ROLE_USER_PERFECTWORLD` to the users who need the tab.
2. Supervisor workers: `perfectworld`, `scheduler_perfectworld` and `perfectworld_pulls` are already in [`scripts/supervisor/programs.list`](../../scripts/supervisor/programs.list); run `sudo scripts/setup-supervisor.sh` again to add them (see [Configure Supervisor Services](../../docs/SERVER_SETUP_AND_DEPLOY.md#27-configure-supervisor-services)). Without a `perfectworld_pulls` consumer an import stays queued and blocks new imports for one hour (in development: `bin/console messenger:consume perfectworld_pulls -vv`).
3. Run `bin/console perfectworld:nte-catalog:update` once to build the catalog; the weekly task keeps it up to date afterwards.

---

## 5. Design notes

| Decision | Reason |
| :--- | :--- |
| Manual import only | The global game has no web API for the gacha history: it travels inside the game protocol, so only client-side packet capture (nte-exporter) can read it. |
| Login with email + code or pasted token, no password | Both were verified against the SDK. The NTE website itself logs in with a code and no captcha; the pasted token also covers Google/Steam/PlayStation accounts. Password login would need the SDK RSA key (`getV2.do`). |
| Profiles only from the role list | The role list returns UID, nickname, server, level and avatar: no manual profile creation is needed. |
| Identity `(banner, display time, position in the second)` | Verified on 1,086 records exported by both 0.3.0 and 0.5.0: same records, same positions, while 4 `uid`s differed. |
| Newer exporter versions rewrite stored records | A 0.5 export over 0.3.0 data fixes the mislabelled rolls without duplicates; re-importing the same file skips everything. |
| Old exports supported and repaired | The game keeps ~6 months of history: the first 0.3.0 exports are the only copy of the oldest pulls. The repair matched the 0.5 labels on all 806 board records exported by both versions, and on every page cut of the 0.3.0 histories it accepted (only cuts of a few pages are rejected as ambiguous). |
| Catalog first, file values as fallback | Exporter name tables lag behind game patches; without a rank a new S character would not reset pity. |
| Dedicated stats calculator | The rules differ from `HoyoversePullStatsCalculator`/`GryphlinePullStatsCalculator` (only rolls count, reset only on S characters); the contract and JSON shape are the same, so the shared pages work unchanged. |
| Wall-clock column read in UTC | Stores the exported time unchanged and keeps it stable in the hour skipped when DST starts (Europe/Rome), with ORM queries only. |
| Own schedule and transports (`perfectworld`, `perfectworld_pulls`) | Same layout as the HoYoverse and Gryphline bundles. |
| Inline SDK HTTP client without logger | Email, code and token are in the query string and Symfony logs full request URLs. In development Doctrine still logs SQL parameters (token included) to `dev.log`, as for the other providers; production logs exclude the `doctrine` channel. |

### Known limitations and open points

- **Timestamp timezone**: a pull made in November showed the real local time (Italy, CET = UTC+1 = EU server time). A pull made after the DST switch (late March) will tell whether exports carry server time or the player's local time. Times are stored as exported either way.
- **Pasted tokens**: `token/info.do` was verified only with the `ndid` used for the login. With a token pasted from the browser the expiry may stay unknown; an expired token is still detected on the next sync (code `20001`).
- **Token lifetime**: 30 days, no refresh: log in again when the account shows "Login Expired". Imports do not need the token.
- **History window**: the game keeps about 6 months. Export at least every few months; the gap warning flags missing records.
- **Very short old exports** (a few pages) can be ambiguous to repair and are rejected: export again with an updated nte-exporter.
- **Undocumented SDK**: constants live in `PerfectworldSdkClient` (`sdkServerVersion` may change). Automated account access is against the game terms of service: the bundle limits itself to login, expiry check and on-demand role sync for the user's own account.
- **NTE_Assets** has no licence (game assets of Hotta Studio / Perfect World), the same trade-off as the other providers' icon sources. If it disappears, stored names and cached icons keep working.

### Possible future work

- Featured S guarantee of the Arc program (every 80 Arcs), using `pool_id`.
- Mystery Box exports (finite prize pool, no pity).
- A second import format (e.g. Anong0u0 `nte-gacha-export` 2.1): a new `PullBackupImporterInterface` implementation is enough.
- Token expiry and periodic export reminders through profile notifications.
- Password and direct Google/Steam/PlayStation login; the CN version (Tajiduo app API).

---

## 6. Testing

`tests/PerfectworldBundle/`: SDK client (`MockHttpClient`), auth flows, profile sync, importer (fixed exports, old-export repair, truncated histories and cut oldest seconds, header checks), import service (upsert, versions, conflicts with manual/filler rows present, gap warnings, realign hook), import endpoint (validation of every file, one file per banner, temporary file cleanup, 409), stats calculator (fillers, declared pities after plan and apply, window fields), manual pull service (against an in-memory table enforcing the unique identity in Doctrine's flush order) and endpoints, catalog builder/backfill/resolver. Shared tracker behaviour is covered in `tests/GamesBundle/Service/PullOperationTrackerBackupBannersTest.php`, routes in `tests/GamesBundle/Routing/GamesRoutingTest.php` and account ownership in `tests/GamesBundle/Security/`.
