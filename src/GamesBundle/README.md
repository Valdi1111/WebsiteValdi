# GamesBundle

Shared foundation of the game provider bundles ([`HoyoverseBundle`](../HoyoverseBundle/README.md), [`GryphlineBundle`](../GryphlineBundle/README.md), [`PerfectworldBundle`](../PerfectworldBundle/README.md)) and host of the unified games frontend on **`games.<domain>`** (one tab per provider).

```
CoreBundle      users, roles/permissions, security, notifications, React layout, theme, tables, file manager
   ▲
GamesBundle     shared game models/interfaces, pull tracking & icons, GeeTest/auth helpers, task scheduling,
   ▲            games page + shared SPA
   │
HoyoverseBundle, GryphlineBundle, PerfectworldBundle   API clients, entities, game services, messages/handlers, provider-specific UI
```

Design decisions are summarized in [§4](#4-design-notes).

---

## 1. Backend

| Area | Classes |
| :--- | :--- |
| **Game model** | `Model/Game/*` (capability interfaces such as `HasStaminaInterface`, `HasDailiesInterface`, `GachaConfig`, `RarityConfig`, `GameAccountInterface`, `GameProfileInterface`; `CachedNotesTrait`, the per-profile notes cache behind `HasNotesInterface::getNotes()`), `Model/GameNotes/*` (the notes carry their fetch time, `GameNotesFetchedAtTrait`; `GameNotesStamina::getFullAt()` is the instant the stamina is full), `Model/Endgame/*` (`EndgameDefinition`, `EndgameVariant` and its optional `EndgameLevel`s, `EndgameReminder`, `EndgameSettings` and the `EndgameTarget` it resolves per variant, and the `GameEndgame` progress returned by `HasEndgamesInterface`), `Model/Pull/*` |
| **Notes embeds** | `Presenter/GameProfileEmbedFactory` (shared header: `<game> - <title>`, footer with the game icon or `GameProfileInterface::getProviderIconUrl()`, player/region/rank fields), `Presenter/{StaminaPresenter, DailiesPresenter, WeekliesPresenter, EndgamesPresenter}` (data object → `UniversalEmbed`, no API call and no threshold; title and description come from the caller), `Model/Endgame/EndgameModeStatus` (one endgames row), `Service/DurationFormatter` (behind `GameUtilsService::formatTime()`). Provider-specific presenters live in the provider bundle (`HoyoverseBundle/src/Presenter`). See [§4](#4-design-notes) |
| **Profile status** | Cross-provider read of the live data of game profiles, for the chat commands and the daily summary (see [§4](#4-design-notes)). `Model/Profile/*`: `GameProfileRef` (`<provider>:<id>`, profile ids are unique only within a provider), `GameDescriptor` (provider, game biz, slug `gi`/`hsr`/`zzz`/`hi3`/`tot`/`ake`/`nte` and aliases), `GameProfileContext` (profile, game service, runtime account data, `fresh`), `AccountHealth`, `GameProfileProviderInterface` (tag `games.profile_provider`, one per provider bundle, usually extending `Service/Profile/AbstractGameProfileProvider`). `Service/Profile/GameProfileRegistry` (providers in `games.providers` order, granted by the user's roles without a security token, profiles of a user, `resolve()` of a reference with the role and ownership checks, games by slug or alias). `Model/Status/*`: `ProfileSectionInterface` (tag `games.profile_section`, ordered by `#[AsTaggedItem(priority)]`: one kind of data, `collect()` / `present()` / `summarize()`), `SectionResult`, `GameProfileStatus`, `SummaryLine`. `Status/AbstractNotesSection` and `Status/Section/{Stamina,Dailies,Weeklies}Section` (provider sections live in the provider bundle: `HoyoverseBundle/src/Status/Section`). `Status/Section/{CheckIn,Endgames}Section` (data in `Model/Status/{CheckInData,EndgamesData}`; endgames left out of the compact view, `inSummary()`). `Service/Status/GameProfileStatusCollector` (notes read once per profile, failures kept per section and per profile, the oldest fetch time of the sections, `getSummarySectionKeys()`), `Presenter/ProfileSummaryPresenter` (one compact embed per profile, generic line for a failed section), `Command/GamesStatusCommand`. Settings: `Model/Profile/ProfileSetting` (+ `ProfileSettingKind`, `ProfileSettingValue`), defined once per provider (`<Provider>ProfileSettings::all()`, returned by `GameProfileProviderInterface::getSettings()`), read by `Service/Profile/ProfileSettingsReader`. Shared reads: `Model/Game/CachedProfileData` (the per-profile cache entry of the notes, the endgames and the check-in status), `Model/Game/{HasCheckInStatusInterface,CheckInStatus}`, `Model/Endgame/{EndgameReadout,EndgameModeReadout}` and `Service/Endgame/EndgameStatusBuilder` (every mode read on its own, shared by the endgames reminder and the section) |
| **Task messages** | `Message/{TaskMessageInterface, RegionalTaskMessageInterface, ProfileTaskMessageInterface, FeatureFlagMessageInterface}` |
| **Task configuration** | `DependencyInjection/GameTasksConfiguration` (`addTaskNode()`, `registerTaskParameters()`), `Scheduler/AbstractTaskScheduleProvider` |
| **Pull tracking** | `Service/AbstractPullOperationTracker` (cache + private Mercure updates; backup imports may report per-banner progress in `banners`; `startSRankHistoryImport()` tracks the S-rank history import per banner), `Model/Pull/{PullImportProgress, PullImportSummary}` (optional `updated` and `banners`), `Exception/PullProfileLockedException` |
| **Missing pulls** | `Model/Pull/{PullOrigin, PullTimelineEntry}`, `Model/Pull/Manual/*` (`ManualPull` constants, `SWindow`, `FillerPlan`, `FillerSlot`, request DTOs `CreateManualPullRequest`/`DeclarePityRequest`), `Service/Pull/PullGapPlanner` (pure: merges, filler slots, window info), `Service/Pull/ManualPullGuard` (validation), `Exception/ManualPullViolationException`, `EventSubscriber/PullApiExceptionSubscriber` (422 `{detail, code}` / 409 `profile_locked` on `/api/`), `Model/Game/HasManualPullsInterface`. See [§4.1](#41-missing-pulls-manual-s-and-declared-pity) |
| **Official S-rank history** | `Model/Game/HasSRankHistoryImportInterface`, `Model/Pull/SRankHistory/*` (readonly DTOs: `SRankHistory`, `SRankHistoryBanner`, `SRankHistoryRecord`, and the `SRankHistoryBannerPlan` / `SRankHistoryRowUpdate` of the reconciler), `Service/Pull/SRankHistoryReconciler` (pure: checks, alignment, simulation), `ManualPull::OFFICIAL_ID_PREFIX`. See [§4.2](#42-official-s-rank-history-import) |
| **Banner schedules (irminsul.gg)** | `Service/Irminsul/IrminsulClient` (`fetch(game, file)` on `api.irminsul.gg/v2/{game}/{file}.json`, client `games.irminsul.client`; a failed request or an empty / invalid file throws `IrminsulException`), `IrminsulTimeRules` (how a game writes its times: `hoyoverse(<second-half start>)`, `endfield()`, `nte()`), `IrminsulPhaseNormalizer` (phases with `{at, offset}` limits, rewritten per the rules; a phase ending before its start gets the next year once, and is dropped with a warning when still invalid or longer than 120 days; `toInstant()`). The catalogs that use them live in the provider bundles |
| **Pull item icons** | `Service/PullItemIcon*`, `Service/PullItemPreviewBinaryLoader` (filler rows are skipped), `Command/PullIconResolversCommand`, Liip filters `games_pull_item_icon` (thumbnail) and `games_pull_item_preview` (full artwork, trimmed by CoreBundle's `trim` filter) (see [`PULL_ITEM_ICONS.md`](../../docs/PULL_ITEM_ICONS.md)) |
| **Game profiles command** | `Command/AbstractFetchGameProfilesCommand`, base of `<key>:fetch-game-profiles [<accountId>]`: fetches the profiles of one account, or of every account without an id, and prints the profiles of each account; a failing account does not stop the others and makes the exit code 1. Providers supply the account repository, the fetch call and optional `getExtraColumns()` |
| **Profile status command** | `Command/GamesStatusCommand`, `games:status <user id or email> [--game=<slug>] [--ref=<provider>:<id> ...] [--section=<key> ...] [--fresh] [--full] [-v]`: the live data of the active profiles of a user, read by `GameProfileStatusCollector` and laid out as the chat commands will (`ProfileSummaryPresenter`, or each section in full with `--full`). It calls the game APIs through the notes cache unless `--fresh`; `-v` prints the errors, which the embeds never show; the exit code is 1 when a profile or a section could not be read |
| **Chat commands** | Game commands of the chat bot (CoreBundle chat framework): `/status` (compact view, `ProfileSummaryPresenter`), `/stamina`, `/dailies`, `/weeklies`, `/endgames` (full view of one section per profile, `Chat/Command/AbstractSectionChatCommand`) and `/profiles` (database only: settings, endgame targets, notifications, account health, `Presenter/ProfileOverviewPresenter`), all on `Chat/Command/AbstractProfileChatCommand` (role `ROLE_USER_GAMES`, options `game` and `profile` with autocomplete). `Chat/ChatProfileSelector` resolves the options through the registry (slug or alias; reference, UID or nickname, an ambiguous nickname refused with the candidates) into a canonical `Model/Chat/ChatProfileSelection`, and suggests games and profiles (database only, an unreadable profile kept). Answers are paged by `ChatPaginator` (CoreBundle); `Chat/Action/GamesChatAction` (`games:<command>:<page>:<refresh>:<slug>:<provider>:<id>`) serves ◀ ▶ and 🔄 Refresh, rechecking every part on each press and counting a Refresh against `ProfileStatusRefreshLimiter::consumeAll()` before it is queued. Descriptions in `translations/chat.it.yaml` |
| **Profile status on the site** | `Controller/ApiProfileStatusController`: `GET /api/{provider}/gameProfiles/{id}/status[?fresh=1]`, under the API prefix of each provider bundle but one controller for all, through `GameProfileRegistry::resolve()` (a profile of another user or of a provider without the role is a 404). `Presenter/ProfileStatusViewFactory` → `Model/Status/View/{ProfileStatusView, ProfileStatusSectionView, ProfileStatusFieldView}`: per section the compact line and the full view of the bot without the profile header (`GameProfileEmbedFactory::HEADER_FIELD_COUNT`) and the Discord fillers. `fresh` is limited per user and profile by `Service/Status/ProfileStatusRefreshLimiter` (`games_status_refresh`, 3 every 10 minutes, `TooManyStatusRefreshesException` → 429). Every normalized profile has `status_sections` (`GameProfileStatusCollector::getSupportedSectionKeys()`, no API call); the card shows "View Status" only when it is not empty and `game-profile/GameProfileStatusDrawer` reads the status when opened |
| **Manual task runs** | `Command/AbstractTaskCommand` + `Model/Task/ManualTask`, base of `<key>:task [<task>] [--profile-id=ID ...] [--region=R ...] [--sync] [--list]`: runs a task of the `<key>.tasks` schedule now, as its batch message (one per region for regional tasks) or, with `--profile-id`, as the per-profile messages of the given profiles whatever their feature flags. `--sync` handles the messages in the console process (`ReceivedStamp`) instead of queuing them; without a task it asks which one to run |
| **Login helpers** | `Service/AuthSessionStore`, `Service/GeetestService` (script cache + iframe response), `Model/Auth/{LoginStepResult, SubmitCaptchaRequest}` |
| **Account ownership** | `Security/GameAccountOwnerVoter` (`GAME_ACCOUNT_OWNER`, subject `GameAccountInterface`, compares user ids) |
| **Serialization** | `Normalizer/AbstractGameProfileNormalizer` |
| **Games page** | `Controller/IndexController` (SPA catch-all), `Controller/GeetestController` (`/api/geetest-script/{version}`), `Service/GameProviderAccess` |

### Configuration

```yaml
# config/packages/games.yaml
games:
    domain_name: 'games.%domain_name%'
    providers:              # tab order
        hoyoverse:
            role: ROLE_USER_HOYOVERSE
        gryphline:
            role: ROLE_USER_GRYPHLINE
        perfectworld:
            role: ROLE_USER_PERFECTWORLD
```

- The games page requires `ROLE_USER_GAMES`, implied by every provider user role through the database role hierarchy. Each tab is shown only when the user has the provider role; provider APIs keep their own `#[IsGranted]`.
- The role hierarchy is cached in `core.security_hierarchy.cache` (under `var/share/`, not cleared by `cache:clear`): after changing roles run `bin/console cache:pool:clear core.security_hierarchy.cache`.

### Routing on `games.<domain>`

| Path | Owner |
| :--- | :--- |
| `/api/{provider}/...` | provider bundle controllers (`prefix: /api/{provider}` in the provider `config/routing.yaml`) |
| `/api/geetest-script/{version}` | `GeetestController` |
| any other path except `/api/...` | `IndexController` → games SPA |

Pull progress Mercure topics are namespaced by provider: `https://games.<domain>/{provider}/profiles/{id}/pulls/progress` (profile IDs come from per-provider tables).

### Credentials encryption at rest

The account credentials use the `CoreBundle` encrypted column type (`encrypted_text`): with `DATABASE_ENCRYPTION_KEY` set they are stored encrypted, without it in plain text as before. Format, behaviour, conversion command (`core:database:encrypt`) and what is not covered: [Encrypted Columns guide](../CoreBundle/src/Service/Encryption/README.md); enabling it on a server: [`SERVER_SETUP_AND_DEPLOY.md` §3.4](../../docs/SERVER_SETUP_AND_DEPLOY.md#34-database-encryption-optional).

| Entity | Encrypted columns |
| :--- | :--- |
| `HoyoverseAccount` | `cookie` (the full HoYoLAB cookie string, `stoken_v2` included) |
| `GryphlineAccount` | `account_token`, `session_cred`, `session_salt` |
| `PerfectworldAccount` | `token` |

Identifiers and device ids (`account_uid`, `pw_uid`, `device_id`, `ndid`, `email`) stay in plain text: accounts are matched and deduplicated on them. `gryphline_account.session_cred` and `session_salt` were `VARCHAR(255)`: migration `Version20261121183000` makes them `LONGTEXT`, since encrypted values are longer.

---

## 2. Frontend (`assets/`)

- `app.js` — Encore entry `games`; reads the granted provider keys from `#root[data-providers]`.
- `providers.js` — composition root: the **only** module importing the provider bundles. Shared components read the current provider through `useGameProvider()`.
- `components/App.jsx` — layout route (`MainLayout`, one tab per provider), `/` redirects to the first provider, SPA 404 pages.
- `components/ProviderScope.jsx` — everything under `/{provider}/*`: one `BackendApi` instance (`/api/{provider}`) and the provider descriptor (`GameProviderContext`, with `basePath`). Rendered with `key={provider.key}` so switching tab remounts the subtree.
- Shared pages and components: `account/AccountsPage`, `account/AccountCard`, `account/SessionStatusTag` (session badge driven by the renewal failure flag, used by HoYoverse and Gryphline), `account/useLoginWizard` + `account/CaptchaStep`, `game-profile/GameProfileCard`, `game-profile/GameProfileSettingsDrawer`, `game-profile/GameProfileStatusDrawer`, `pulls/*`, `SettingRow`, `ThresholdInput`, `GeetestCaptchaFrame`.

### Provider descriptor (`<ProviderBundle>/assets/provider.js`)

```js
export default {
    key: "hoyoverse",                    // route segment, API prefix (/api/hoyoverse) and backend config key
    label: "HoYoverse",                  // tab and page titles
    icon: <UserSwitchOutlined/>,
    AddAccountModal,                     // provider login/import modal (uses useLoginWizard + CaptchaStep)
    AccountStatusTags,                   // credential status badges in AccountCard
    getReloginState: (account) => ({ highlight, danger }),
    profileSettings: {
        defaults: { /* setting: fallback value */ },
        Sections: ProfileSettingsSections, // rendered inside the shared settings drawer form
    },
    pullsExtraImporters: [               // optional: extra entries of the pulls Import menu
        { key, label, icon, isAvailable: (profile) => boolean, Modal },
    ],
    officialPullLabel: "HoYoLAB",        // optional: tag of the official S rows (default "official")
    extraRoutes: [                       // optional: provider-only pages under /{key}/...
        { path: "accounts/:accountId/profiles/:gameProfileId/diary", element: <DiaryPage/> },
    ],
};
```

Shared components are driven by the fields the backend exposes for each profile (`"field" in profile`) and by common capability flags: `supports_pulls` (pull tracker available), `supports_pull_sync` (server-side automatic sync), `supports_manual_pulls` (missing pulls: correct pity / add earlier S).

Optional fields understood by the shared pulls pages:

| Field | Where | Effect |
| :--- | :--- | :--- |
| `multiple`, `max_files` | `available_backup_importers[]` (overview) | `BackupPullImportModal` accepts several files, sent as `files[]` in one request (otherwise a single `file`) |
| `banners` | backup import operation, S-rank history import | per-banner tags in `PullOperationProgressBanner` and in the import modal (`processed/total`, `imported`, `updated`); status `skipped` (banner left unchanged, reason in `error`) is a warning tag |
| `updated` | backup import operation | records rewritten by the import (e.g. a newer exporter fixing older data) |
| `pull_label`, `pull_singular_label` | banner summary | unit counted by that banner (e.g. NTE "Rolls" vs "Arcs"), falls back to `rarity_config.pull_label` |
| `icon_url`, `preview_url` | pull rows, `recent_s_pulls`, `oldest_s`, `pools[].featured`, manual pull suggestions | round thumbnail of the item (initials when missing or broken) and, on click, its full artwork in the antd `Image` preview (downloaded only when opened; falls back to `icon_url`); both `null` for filler rows. See [`PULL_ITEM_ICONS.md`](../../docs/PULL_ITEM_ICONS.md) |
| `roll_label` | pull rows | extra "Roll" column in `PullsBannerDetail` (NTE: dice value, Points Gift, Slumberland) |
| `pity` / `pull_number` = `null` | pull rows | shown as "—" (bonus rows, rewards that do not reset pity) |
| `origin`, `declared_pity` | pull rows, `recent_s_pulls` | `filler` rows render as "?" (thumbnail, tag, "Unknown pull", estimated date); `manual` S get a tag and a dashed badge; a declared pity gets an indicator, and a warning when the computed pity differs. `official` S get the provider's `officialPullLabel` tag, an "official pity" indicator and no row actions; "Add missing S" is disabled when the oldest S is official |
| `is_up` | pull rows, `recent_s_pulls` | "UP" tag on a rate-up S (the stored value, or the provider's catalog: Endfield, NTE Arcs); `null` = unknown or not applicable, never a lost 50/50 |
| `pools` | banner summary | "Banners (n)" table, one row per rotation, newest first, 10 per page (`pool_name`, `pool_version` as "#N", `total_pulls`, `s_count`, per-pool `s_pity`/`a_pity` when not `null`; the Period column shows `start_at` → `end_at` when both are set, else the first and last pull; `featured`, a list of the banner's rate-up items with `name` and `icon_url`, shows their icons before the name): Endfield, NTE Arc banner and limited boards, HoYoverse limited banners per phase |
| `recorded_pity`, `insert_after`, `insert_before`, `suggested_at`, `has_room` | S rows | bounds and defaults of the "Correct pity" / "Add earlier S" modals; rows without `has_room` get no actions |
| `unknown_count`, `open_window`, `oldest_s` | banner summary | number of filler rows; window used by "Add missing S" when the banner has no S; the chronologically first S (with its window fields and `icon_url`), anchor of "Add missing S" even when `recent_s_pulls` is capped (NTE: 50) |
| rarity `0` | `rarities` filter of `GET .../pulls` | the "?" pill (filler rows), off by default like B |

---

## 3. Adding a game provider

1. Create the provider bundle (see [`CREATING_A_BUNDLE.md`](../../docs/CREATING_A_BUNDLE.md)) depending on `GamesBundle` models and interfaces.
2. Routing: `host: '%games.domain_name%'`, `prefix: /api/<key>`, controllers protected by the provider role.
3. Tasks (only if the provider has scheduled automations): define them with `GameTasksConfiguration` in the bundle `configure()`/`loadExtension()` and add a `ScheduleProvider` extending `AbstractTaskScheduleProvider` with `#[AsSchedule('<key>')]`.
4. Pull tracking: extend `AbstractPullOperationTracker` returning `<key>` from `getProviderKey()`.
5. Roles: data migration adding `ROLE_ADMIN_<KEY>` and `ROLE_USER_<KEY>` with `ROLE_ADMIN_<KEY> → ROLE_USER_<KEY> → ROLE_USER_GAMES` in the role hierarchy, then `cache:pool:clear core.security_hierarchy.cache`.
6. Register the provider in `config/packages/games.yaml`, its descriptor in `assets/providers.js`, and the `@<Key>Bundle` alias in `webpack.config.js` and `phpstorm.config.js`.
7. Game profiles command: extend `AbstractFetchGameProfilesCommand` with `#[AsCommand('<key>:fetch-game-profiles')]`, returning the account repository and calling the provider sync in `fetchGameProfiles()`.
8. Task command: extend `AbstractTaskCommand` with `#[AsCommand('<key>:task')]`, injecting the `<key>.tasks` and `<key>.timezones` parameters and describing each task with a `ManualTask` (its per-profile messages, if any).
9. Profile status: extend `AbstractGameProfileProvider` (in `src/Status/`), returning the provider key and profile class, a descriptor per game service with a short slug and aliases (unique across providers), the user's profiles, the game service of a profile and its runtime account data (`null` when the provider has no API data), and the settings of its profiles: define them once in a dependency-free `<Provider>ProfileSettings::all()` (same key as the other providers for the same feature), return them from `getSettings()` and derive the normalizer's `getConditionalSettings()` from them with `ProfileSetting::fieldsByCapability()`. The shared sections then show every capability the game services implement; a kind of data only the provider has gets its own section (extend `AbstractNotesSection` for notes data).

---

## 4. Design notes

Why things are the way they are (useful before changing them):

| Decision | Reason |
| :--- | :--- |
| `CoreBundle ← GamesBundle ← provider bundles` | Core stays generic; game-only code has a single home and new providers depend only on `GamesBundle`. |
| Provider APIs under `/api/{provider}/...` on one host | Both providers expose the same paths (`/accounts/...`); the prefix avoids collisions and keeps the SPA catch-all simple (`^(?!api/).*`). |
| Mercure topics include the provider | Profile IDs come from per-provider tables: without the provider segment, HoYoverse profile 5 and Gryphline profile 5 would share a topic, and a user authorized for one would receive the other's updates. |
| `ROLE_USER_GAMES` in the database role hierarchy | Same mechanism as the other roles; the hierarchy is transitive, so `ROLE_ADMIN_*` roles reach it too. Tabs are filtered by provider roles, APIs keep their own `#[IsGranted]`. |
| Explicit provider list in `config/packages/games.yaml` | Clear tab order in one place, instead of each bundle prepending config (order would depend on `bundles.php`). |
| `providers.js` is the only module importing provider bundles | Single composition root: no circular imports between `GamesBundle` and provider assets. Shared components read the provider via `useGameProvider()`. |
| Minimal static provider descriptor | Just enough for shared pages to render provider-specific parts: no texts (only the provider name changes, so `label` is enough), no API URL (derived as `/api/{key}`), no plugin registry. |
| `ProviderScope` rendered with `key={provider.key}` | Without the key React reuses the same component instances when switching tab, leaving the previous provider's accounts, modals and drawers on screen. |
| Shared components driven by profile fields (`"field" in profile`) | Settings, tags and actions appear only when the backend exposes the field, so one component serves every provider without per-provider branches. |
| Gryphline pulls expose both `item_type` and `item_type_label` | `item_type` (raw pool category) is also used by `PullItemIconQuery::fromPullRow()` to resolve icons; the label is only for display. |
| Account ownership through `GameAccountOwnerVoter` | Every route with `{account}` declares `#[IsGranted(GameAccountOwnerVoter::OWNER, subject: 'account')]` (class level on `ApiGameProfilesController`, method level on the accounts/auth controllers) and receives the account as a `#[MapEntity]` argument, so the check runs before the action and cannot be forgotten in a new action (`GameAccountOwnerRoutesTest` fails). Users are compared by id, not by instance. Body parameters such as `account_id` on `POST /accounts` call `denyAccessUnlessGranted()` with the same voter; the auth services keep their own check for accounts reloaded from a login session. The "profile belongs to the account" check stays in the controllers (`assertProfileOfAccount()`). |
| Re-login never changes the account identity | Credentials login and pasted token/cookie compare the provider UID (HoYoverse `account_id`, Gryphline `hgId`, Perfect World `uid`) with the stored one and refuse a different account (`422 ... does not match ...`) instead of overwriting it; the Gryphline background token refresh does the same and flags the account. New accounts are deduplicated by that UID. |
| Game notes cached per game profile for 5 minutes (`CachedNotesTrait`, one pool per provider: `hoyoverse.notes.cache`, `gryphline.notes.cache`) | Every data getter (`getStaminaData()`, `getDailiesData()`, ...) calls `getNotes()`, and the reminders of one profile run in the same tick: one API call instead of one per getter, so fewer captchas and rate limits. Reminders read the cache too, so their data can be up to 5 minutes old (accepted: the reminder windows are much longer). An entry is the raw `data` array with its fetch time (`{fetchedAt, data}`), denormalized on every read: the filesystem pools survive deploys, and an array keeps reading where a serialized object of an older class shape could fail. Errors and partial answers (HoYoLAB `-501000`) are not cached, profiles without an id never use the cache, and `getNotes($account, fresh: true)` calls the API and replaces the entry (with `get(..., INF)`, which keeps the stampede protection). |
| Notes embeds built by presenters, checks left in the reminder handlers | The reminders and the future query commands (Discord bot) show the same data, with or without a threshold or an "all done" check: the handler decides whether to notify, the presenter only lays the data out. The reminder wording ("Don't Forget to Do Your Dailies!") stays with the caller, as the title and description passed to `present()`. The presenters reach the handlers through `#[Required]` setters, like the other dependencies of a single handler, so the positional constructors of the handler bases do not change. The action handlers (check-in, code redemption) take `GameProfileEmbedFactory` the same way and add their fields to its header. |
| Profile status layer: providers, sections, collector | The chat commands (Discord now, Telegram later) and the daily summary show the same data for every game, so nothing in them is per game: a provider bundle registers a `GameProfileProviderInterface` (its games, the profiles of a user, the game service and runtime account data of a profile), and each kind of data is a `ProfileSectionInterface` supported through the capability interfaces of the game service. A new game shows up in every command once its service implements the capabilities; a new kind of data is one section. The registry checks the provider roles itself (`UserRoleResolver`), because a chat request has a user but no security token, and every reference goes through the role and ownership checks: a reference coming back from a chat button is never trusted. |
| Notes read once per profile by the collector, not memoised in the notes trait | `GameProfileStatusCollector` calls `getNotes($account, $fresh)` once before the sections, whose getters then read the entry it stored; when that read fails, every notes section of the profile fails with it, so an expired cookie costs one request, not one per section. The notes trait does not memoise answers on the account object: the notes cache tests require an answer that is not cached (`-501000`, no data, no profile id) to be requested again, and those extra requests are rare. |
| Notes carry their fetch time; durations are counted from absolute instants | A cached payload is up to 5 minutes old and HoYoLAB sends relative times ("seconds until full"): reading them as they are would show times up to 5 minutes too long. `getNotes()` gives the notes the fetch time of their entry, and the getters turn the relative times into instants (`GameNotesStamina::getFullAt()`, `GameNotesRealm::getFullAt()`, `GameNotesExpedition::getFinishAt()`); Arknights: Endfield sends the instant itself (`maxTs`). The DTOs never read the clock: presenters count the time left from `GameProfileEmbedFactory::now()`, so an embed uses one clock, and DTOs without an instant (built by hand) keep the relative time. The live reminder embeds therefore show the time left at sending, not at fetching. |
| Errors stay out of the embeds and the logs | Provider exceptions can carry URLs, account data or response bodies: the collector keeps them in the results and logs only their class and code (as the endgames reminder logs class and retcode), the views show a generic line, and only `games:status -v` prints the messages. |
| One cache entry for the notes, the endgames and the check-in status (`CachedProfileData`) | The same rules for every API payload cached per profile: raw arrays with their fetch time, errors and partial answers not stored, no key without a profile id, `fresh` replacing the entry. The endgames cache each battle record request for 30 minutes (`hoyoverse.endgames.cache`, `CachedEndgameRequestTrait`), the check-in status the read-only info for 10 minutes (`hoyoverse.check_in_status.cache`, `gryphline.check_in_status.cache`), deleted by a successful check-in so the claim shows at once. Sections whose data comes from such an entry implement `FetchedDataInterface`, and the collector keeps the oldest fetch time of a profile. |
| The endgames reminder reads fresh | A reminder for a mode played since the last read would be wrong, so `EndgamesReminderProfileHandler` reads every requested mode with `fresh: true` (the same calls as before, and it warms the cache for the status views). It reads through `EndgameStatusBuilder`, which reads each mode on its own and neither logs nor throws: the handler keeps its rules (class and retcode logged per mode, the first error thrown only when every mode failed). |
| A status read never claims | `HasCheckInStatusInterface::getCheckInStatus()` reads the HoYoLAB `info` / SKPort attendance only; claiming stays in `checkIn()` and the scheduled tasks. The check-in section asks for attention only when auto check-in is off: with it on, an unclaimed reward is expected until 20:00. |
| Settings defined once per provider | `<Provider>ProfileSettings::all()` lists every setting (key shared across providers, label, kind, capability, fields). The normalizers derive from it the fields a game exposes and accepts (the former `CONDITIONAL_SETTINGS`, unchanged: `ProfileSettingsSchemaTest`), and `ProfileSettingsReader` reads the values for the status layer. The definitions have no dependencies, because the HoYoverse normalizer cannot depend on the profile provider (provider → cookie utils → serializer → normalizer). |
| The compact view leaves out the costly sections | `ProfileSectionInterface::inSummary()`: the notes sections share one read per profile and the check-in is one call, while the endgames are one battle record call per mode. `/status`, the daily summary and `games:status` without `--section` use `GameProfileStatusCollector::getSummarySectionKeys()`. |
| Chat commands read through the status layer | The commands are thin: the selection goes through the registry (roles, ownership), the data through `GameProfileStatusCollector` and the views are the presenters of the reminders and of the site, so `/status` and the site show the same thing. A profile whose context cannot be built is never hidden (its answer says to log in again); profiles without the command's sections are skipped after reading, as `games:status --section` does. A Refresh is all or nothing across the profiles the command may show, checked before it is queued so a refusal leaves the message untouched; pages read the caches. A section that cannot be laid out shows the generic line for that profile only, in the chat answers and in the compact view. |
| Status on the site only on request | The games page loads no live data: the card offers "View Status" and the drawer makes one request when opened, with the same sections and views as the bot, plus a Refresh that skips the caches. The route sits under each provider prefix so the per-provider client of the page calls it as its own, but the controller is shared and goes through the registry like the bot. Fresh reads call the game APIs again (notes, check-in, one call per endgame mode), so they are limited per user and profile; cached reads are not. `status_sections` is computed without API calls by building the context of the provider, so the profile normalizers get the collector lazily (provider → cookie utils → serializer → normalizer). A section whose views throw is shown as unavailable, never the whole response. |
| Compact and full views agree on cached notes | Cached notes keep the counts of their read: once the full or return instant has passed, `GameNotesStamina::hasFilledUpBy()`, `GameNotesRealm::hasFilledUpBy()` and `GameNotesExpeditions::haveAllReturnedBy()` make both the summaries and the presenters (also the reminder embeds) show them full or back, as they are in the game. |
| New shared fields are optional and new DTO parameters trailing | `PullImportSummary`/`PullImportProgress` are built positionally by existing providers and tests; providers that do not send `banners`, `multiple` or `roll_label` keep the previous UI. |

### 4.1 Missing pulls (manual S and declared pity)

Histories start where the source starts (API window, first backup), so the first recorded S has an underestimated pity and older S are missing. Users can **declare the real pity** of an S (`declared_pity`) or **add an earlier S** (`origin = manual`). An official S-rank history can add S too (`origin = official`, with their pity as declared pity and their pull number, see [§ 4.2](#42-official-s-rank-history-import)). The missing pulls become **real rows** (`origin = filler`, rarity `0`, name `Unknown`), so every stats calculator counts them without special cases, except that they are not B (`unknown_count`), have no pity and reset the A-pity counter.

| Decision | Reason |
| :--- | :--- |
| Fillers are rows, the declaration is the source of truth | Counting stays standard. `declared_pity` is never read by the counting; it only drives how many fillers a window needs (`declared − recorded`). |
| One idempotent realign step (`PullGapPlanner::plan()` applied by each provider's `ManualPullService::realign()`) | Runs inside the profile lock after manual edits, imports and the last sync page, so later real data shrinks the fillers instead of double counting. Its second run is always empty. |
| A manual S merges only into a recorded S that arrived later (higher id) and has no declaration | Two copies of the same character minutes apart (constellations) stay separate, and a recorded S is never claimed twice. |
| Official S rows (`origin = official`) are counted and merged like manual ones, but are not the user's | Their pity and date come from the official history, not from an estimate: the user cannot correct their pity, delete them or add an S right before one of them (`official_pull`). Like a manual S, they never take part in the dedupe of imports, the merge targets or the suggestions, which read recorded rows only; the duplicate warning of the "Add an S" form does count them, like recorded S. |
| A recorded S stays `recorded`, also when the official history confirms it or an official S merges into it | No extra column. Such an S stays editable; when its window still has missing pulls, its `declared_pity` comes from the official history, and the next import writes the official value back (accepted limit). |
| Fillers sit strictly between non-filler neighbours (largest time gap of the window, or 1 s before the history start) | Ordering never depends on each provider's tie-break (`pullId` string, `(int) seqId`, `group_position`). Their dates are estimates and move on realign. |
| Except between two **numbered** S of one second (`PullTimelineEntry::$pullNumber`, HoYoverse `pull_number`) | The S of one 10-pull share its second, so a window between them is 0 s wide. When both S carry their pull number in the banner (official S rows), its fillers take the second of the earlier S and the numbers in between: S, fillers, S. A provider that stores numbers (today only HoYoverse) orders a second by number after its unnumbered rows (MySQL puts `NULL` first; `PullTimelineEntry::pullNumberOrderKey()` in PHP). Without numbers the plan is unchanged. |
| A second never mixes numbered and unnumbered non-filler rows | Their relative order would be unknown. If the planner meets one anyway, it warns and does not use a numbered gap touching that second. |
| Nullable `is_up` on the three pull tables | Whether an S was the rate-up item, only when a source says so: `null` (every existing row) is unknown, never a lost 50/50. No counting reads it. |
| `isS` / `countsAsPull` come from each provider's stats calculator (`toTimelineEntries()`) | The realign step counts exactly like the pity shown in the UI (NTE boards count only rolls). |
| Provider-specific rows and ids | HoYoverse/Gryphline use `filler_`/`manual_` ids; Perfect World uses negative `group_position` slots, so imports (identity on `pulled_at` + position) never collide. |
| Pull dates on `wall_clock_datetime_immutable` (`App\CoreBundle\Doctrine\WallClockDateTimeImmutableType`) | The three pull tables store wall-clock times read back as UTC: with the default timezone (Europe/Rome) the hour skipped when DST starts would shift on read, and realign would move the fillers placed there on every run (`tests/GamesBundle/Doctrine/PullWallClockTest`). |

**Rules** (`Service/Pull/ManualPullGuard`, on the entries of one banner group). A violation throws `ManualPullViolationException`, which `PullApiExceptionSubscriber` renders as `422` with its `code`; a write while an import or sync holds the profile lock gets `409` with code `profile_locked`.

- **Declare a pity** of an S: between the pulls recorded in its window and the banner's S max pity (`pity_out_of_range`). A value above the recorded one needs a usable gap in the window (`no_room`). Clearing it (`null`) is refused on a manual S, which is deleted instead (`pity_required`). Providers store the recorded value as `null` on a recorded S. The pity of an official S can be neither changed nor cleared (`official_pull`).
- **Add an S** at the start of an anchor S's window, or of the open window after the last S:
  - its pity is between 1 and the S max pity (`pity_out_of_range`);
  - its date is strictly after the previous S and before the first recorded pull of that window (`date_out_of_range`), and not in the future (`date_in_future`);
  - with a pity above 1, its date is at least `PullGapPlanner::MIN_GAP_SECONDS` (2 s) after the previous S, so that its fillers fit (`no_room`).

  - the anchor is not an official S (`official_pull`): the official history lists every S, so none is missing right before one of them.

  So a manual S never splits the window of a recorded S.
- **Duplicates**: a recorded or official S of the same item within `ManualPull::MERGE_WINDOW_SECONDS` (24 h) gives `possible_duplicate`. The form asks whether it is another copy and resends with `allow_duplicate: true`. Same item means the same `item_id` when both rows have one, otherwise the same normalized name (`PullGapPlanner::isSameItem()`).
- **Merge** (in `plan()`): a manual or official S merges into the nearest recorded S of the same item within 24 h that was inserted after it and has no declared pity, and its declared pity moves to that row, with its `is_up` when the recorded row has none. On equal distance the earliest candidate in the timeline wins, not the lowest row id: a sync inserts the newest pulls first, so two copies of one item in one 10-pull would otherwise pair up the wrong way round.
- **Delete**: only a manual S (each provider's `ManualPullService::deleteManual()`, `400` for any other row, official ones included).
- **Dates** are sent and stored as wall-clock `Y-m-d H:i:s` without offset (`ManualPull::DATE_FORMAT`), like the recorded rows of the provider.

### 4.2 Official S-rank history import

Some games publish the whole history of the S pulls of each banner, older than their pull logs, but only the S: pull number in the banner, pity, date, rate-up flag (Zenless Zone Zero: the HoYoLAB "Signal Search" statistics). A game service exposes it with `Model\Game\HasSRankHistoryImportInterface::getSRankHistory()`, as the readonly DTOs `SRankHistory` → `SRankHistoryBanner` (banner key, total pulls, S count, current pity) → `SRankHistoryRecord` (item id, name, item type, pull number, pity, server wall-clock date, `is_up`). `Service/Pull/SRankHistoryReconciler::reconcile()` compares one banner with its stored rows and returns an `SRankHistoryBannerPlan` (creates, updates, replaced user values, warnings, conflicts) without writing; the provider writes it (HoYoverse: `SRankHistoryImportService`).

| Decision | Reason |
| :--- | :--- |
| Chain checks before anything else: unique pull numbers (an exact duplicate, compared field by field, is only a warning), pity = distance from the previous S, pity within the banner limit, dates in pull order, two S of one second at most 9 pulls apart, last pull number within the banner total | A history that does not add up is not used: the banner is skipped. The pulls of a 10-pull share its second, so two S of one second belong to one 10-pull. |
| Stored S found by **ordered alignment**, not by date | Same item, and a date within `MERGE_WINDOW_SECONDS` for a recorded S, the same pull number for an official S, the item only for a manual S (its date is an estimate). The alignment keeps the most pairs, then the closest dates. Stored dates are never compared to the second: a real backup row was one hour off. |
| A stored S the history does not list is a **conflict**, unless it is a **recorded** S newer than the whole history | Nothing is written on that banner (additive only: a row is never deleted because the history lacks it). A recorded S pulled after the history was last updated is fine. A manual S is never exempted, whatever its date: its date is an estimate, and unpaired it may stand for an S the history lists under another name (the banner would end with one S too many). Its conflict names it and asks to delete it or add it again with the right item. |
| Official data wins | Missing records become official S (pity as declared pity, pull number, exact date, `is_up`); a matched manual S becomes official the same way; a matched recorded S keeps its date and item and takes `is_up`, and the official pity as declared pity only while pulls are missing before it (`null` otherwise). Every replaced user value (declared pity, date or pity of a manual S) is reported; a declared pity equal to the official one (written by an earlier import through a merged official S) is not a user value. |
| A second never mixes numbered and unnumbered rows | The order of such a second would be unknown: conflict (e.g. a partially stored 10-pull without its S). |
| In-memory simulation before writing | The stored rows with the new values, then `PullGapPlanner::plan()` applied: no planner warning allowed, and every record must land on its pull number. `findPositionMismatches()` checks the same again on the stored rows after writing. |
| More pulls than **both** the stored rows and the official total is a conflict | Stored pulls pushed after an S (two sources one hour apart) leave its window short: the S still lands on its pull number, but made-up pulls replace them before it and the moved ones are counted again after it. More pulls than the official total alone is fine (pulls made since the history was updated); a history that lags while the stored history is incomplete is refused too, safely, until it is updated. |
| Other totals that differ are warnings | Pulls made after the last sync, or missing after the last S, legitimately differ from the official totals. |
| Fillers out of line are realigned even when no S changes (`SRankHistoryBannerPlan::$realignNeeded`) | A banner left unaligned (e.g. a sync that stopped before its last page) gets its realign and the pull number check; an aligned banner with nothing to change is not written at all. |

### Not shared on purpose

- **Message classes**: each one routes to its provider's Messenger transport through `#[AsMessage]`.
- **`AbstractBatchTaskMessageHandler`**: sharing it saves almost no lines, risks re-declared promoted `readonly` properties, and `CodeRedeemBatchHandler` has its own constructor.
- **Pull backup importers / synthetic reconcilers** (interfaces and managers): typed on each provider's entities; sharing them would mean losing those types.
- **Login modal**: only the flow is shared (`useLoginWizard` + `CaptchaStep`); `Modal` + `Tabs` and the credential fields stay in each provider's `AddAccountModal`.
- **Controllers, pull import/sync services, entities, auth services, normalizers**: similar structure, different details (normalizers already share `AbstractGameProfileNormalizer`).

### Possible future unifications

- `AbstractProfileTaskMessageHandler` and the reminder handlers (Dailies/Weeklies/Stamina/Check-in): their logic is identical but would need 3 extension points (game service lookup by `gameId`/`gameBiz`, runtime account data with cookie parsing, exception context). Their embeds are already shared (presenters), and the first two extension points now exist in the profile providers (`GameProfileProviderInterface::createContext()`), which the handlers do not use yet.
- Pull endpoints of `ApiGameProfilesController`, `PullBackupImportService`, sync/import handlers, `ManualPullService`.
- The three pity calculators (`HoyoversePullStatsCalculator`, `GryphlinePullStatsCalculator`, `PerfectworldPullStatsCalculator`): same contract (`compute()`, `filterAndPaginate()`, `isS()`, `countsAsPull()`, `toTimelineEntries()`), different rules (NTE counts only rolls on boards).
- Check-in setting names (`hoyolab_check_in` / `skport_check_in` → `check_in`): requires renaming database columns.

---

## 5. Testing

- `tests/GamesBundle/Routing/GamesRoutingTest` boots the kernel and matches real routes on the games host (provider APIs, GeeTest script/frames, SPA catch-all, legacy `/api/accounts` not routed, provider routes bound to the games host only).
- `tests/GamesBundle/Security/GameAccountOwnerRoutesTest` boots the kernel, collects every provider route with `{account}` and runs Symfony's `IsGrantedAttributeListener` on each action: the owner passes, another user gets a 403. `GameAccountOwnerVoterTest` covers the voter for the three account entities.
- `tests/GamesBundle/Doctrine/EncryptedCredentialMappingTest` boots the kernel and checks that exactly the five credential columns of the game accounts use the `encrypted_text` type and are found by `core:database:encrypt` (metadata only, no database connection). The type itself is tested in `CoreBundle` (see the [Encrypted Columns guide](../CoreBundle/src/Service/Encryption/README.md#6-testing)).
- `PullGapPlannerTest` (with an `apply()` helper modelling a provider and a seeded random idempotency loop), `ManualPullGuardTest`, `PullApiExceptionSubscriberTest` and `ManualPullRequestTest` (real serializer/validator) cover the missing-pulls foundations; `SRankHistoryReconcilerTest` the S-rank history import (chain checks, alignment, conflicts, simulation).
- `AbstractTaskCommandTest` covers the shared task command (list, batch and regional dispatch, `--region` and `--profile-id` validation, feature flag hint, `--sync` with `ReceivedStamp` and handler failures, interactive pick and cancel).
- `AbstractFetchGameProfilesCommandTest` covers the shared fetch-game-profiles flow (one account, unknown id, no accounts, a failing account among several, extra columns); each provider has a wiring test of its command.
- `tests/GamesBundle/Status/*` cover the profile status layer with in-memory providers, profiles and game services (`Fixtures/`): references and descriptors (`ProfileModelTest`), the registry (configured order, roles, ownership by user id, games by slug or alias), the collector (one notes read, short-circuit of the notes sections, failures kept per section and per profile, `fresh`, display order), the shared sections and their summaries, `ProfileSummaryPresenter` (attention marker, generic line for a failure, age of the data) and `games:status` (`CommandTester`). `ProfileStatusWiringTest` boots the kernel: the three providers in order, a descriptor for every game service, unique slugs and aliases, the sections in order. Each provider has tests of its provider and sections (`tests/<Provider>Bundle/Status`). `NotesFetchTimeTest` (Hoyoverse) and the Endfield notes cache test cover the fetch time and the instants of every game. `ProfileSettingsSchemaTest` pins the fields derived from the settings (the former normalizer maps) and `ProfileSettingsReaderTest` that every field reads on its entity; `CheckInSectionTest` and `EndgamesSectionTest` the two sections (targets of the profile, modes failing or without a period, attention); `EndgameStatusBuilderTest` the builder. The providers test the caches: `EndgamesCacheTest` (every mode of GI, HSR and ZZZ, and the reminder reading fresh), `HoyolabCheckInStatusTest` and `ArknightsEndfieldCheckInStatusTest` (cache, entry deleted after a check-in, only the read endpoint ever called).
- `ApiProfileStatusControllerTest` covers the site status endpoint through the HTTP stack without a database (fake providers behind the real role resolver and collector): owner, another user, provider without role or unknown, games role, generic errors, fresh limited per profile, not for other users' references. `ProfileStatusViewFactoryTest` the JSON view (header and fillers dropped, generic failures, a section whose views throw), `ProfileStatusSectionsNormalizationTest` `status_sections` of real profiles of the three providers through the serializer of the container, in both build orders of the lazy edge.
- `GameProfileEmbedFactoryTest`, `NotesPresentersTest` and `EndgamesPresenterTest` cover the presenters with fixed data objects (stamina far from the threshold, dailies all done, hidden/locked/missing endgame variants, unknown recovery time); `DurationFormatterTest` the durations. Each provider's `NotesReminderEmbedTest` pins the whole embed of every notes reminder (header, wording, fields and their order) through the handler.
- Unit tests cover `GameProviderAccess`, provider-namespaced Mercure topics, `GameTasksConfiguration`, `AbstractTaskScheduleProvider`, `GeetestService::createFrameResponse()` and the shared normalizer flags (`supports_pulls`, `supports_pull_sync`).
- Security (games page protected by `ROLE_USER_GAMES`, provider APIs by their roles, public PowerShell script) is not covered by automated tests: a `WebTestCase` would need a test database for the session (`PdoSessionHandler`). Check it manually with a user holding a single provider role.
- There is no JavaScript test setup: after frontend changes run `npm run build` and check both providers in the browser (tabs, accounts, settings drawer, login modal, pulls page with live Mercure progress, diary).
