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
| **Game model** | `Model/Game/*` (capability interfaces such as `HasStaminaInterface`, `HasDailiesInterface`, `GachaConfig`, `RarityConfig`, `GameAccountInterface`, `GameProfileInterface`), `Model/GameNotes/*`, `Model/Pull/*` |
| **Task messages** | `Message/{TaskMessageInterface, RegionalTaskMessageInterface, ProfileTaskMessageInterface, FeatureFlagMessageInterface}` |
| **Task configuration** | `DependencyInjection/GameTasksConfiguration` (`addTaskNode()`, `registerTaskParameters()`), `Scheduler/AbstractTaskScheduleProvider` |
| **Pull tracking** | `Service/AbstractPullOperationTracker` (cache + private Mercure updates; backup imports may report per-banner progress in `banners`), `Model/Pull/{PullImportProgress, PullImportSummary}` (optional `updated` and `banners`), `Exception/PullProfileLockedException` |
| **Pull item icons** | `Service/PullItemIcon*`, `Command/PullIconResolversCommand`, Liip filter `games_pull_item_icon` (see [`PULL_ITEM_ICONS.md`](../../docs/PULL_ITEM_ICONS.md)) |
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
- Shared pages and components: `account/AccountsPage`, `account/AccountCard`, `account/SessionStatusTag` (session badge driven by the renewal failure flag, used by HoYoverse and Gryphline), `account/useLoginWizard` + `account/CaptchaStep`, `game-profile/GameProfileCard`, `game-profile/GameProfileSettingsDrawer`, `pulls/*`, `SettingRow`, `ThresholdInput`, `GeetestCaptchaFrame`.

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
    extraRoutes: [                       // optional: provider-only pages under /{key}/...
        { path: "accounts/:accountId/profiles/:gameProfileId/diary", element: <DiaryPage/> },
    ],
};
```

Shared components are driven by the fields the backend exposes for each profile (`"field" in profile`) and by common capability flags: `supports_pulls` (pull tracker available), `supports_pull_sync` (server-side automatic sync).

Optional fields understood by the shared pulls pages:

| Field | Where | Effect |
| :--- | :--- | :--- |
| `multiple`, `max_files` | `available_backup_importers[]` (overview) | `BackupPullImportModal` accepts several files, sent as `files[]` in one request (otherwise a single `file`) |
| `banners` | backup import operation | per-banner tags in `PullOperationProgressBanner` and in the import modal (`processed/total`, `imported`, `updated`) |
| `updated` | backup import operation | records rewritten by the import (e.g. a newer exporter fixing older data) |
| `pull_label`, `pull_singular_label` | banner summary | unit counted by that banner (e.g. NTE "Rolls" vs "Arcs"), falls back to `rarity_config.pull_label` |
| `roll_label` | pull rows | extra "Roll" column in `PullsBannerDetail` (NTE: dice value, Points Gift, Slumberland) |
| `pity` / `pull_number` = `null` | pull rows | shown as "—" (bonus rows, rewards that do not reset pity) |

---

## 3. Adding a game provider

1. Create the provider bundle (see [`CREATING_A_BUNDLE.md`](../../docs/CREATING_A_BUNDLE.md)) depending on `GamesBundle` models and interfaces.
2. Routing: `host: '%games.domain_name%'`, `prefix: /api/<key>`, controllers protected by the provider role.
3. Tasks (only if the provider has scheduled automations): define them with `GameTasksConfiguration` in the bundle `configure()`/`loadExtension()` and add a `ScheduleProvider` extending `AbstractTaskScheduleProvider` with `#[AsSchedule('<key>')]`.
4. Pull tracking: extend `AbstractPullOperationTracker` returning `<key>` from `getProviderKey()`.
5. Roles: data migration adding `ROLE_ADMIN_<KEY>` and `ROLE_USER_<KEY>` with `ROLE_ADMIN_<KEY> → ROLE_USER_<KEY> → ROLE_USER_GAMES` in the role hierarchy, then `cache:pool:clear core.security_hierarchy.cache`.
6. Register the provider in `config/packages/games.yaml`, its descriptor in `assets/providers.js`, and the `@<Key>Bundle` alias in `webpack.config.js` and `phpstorm.config.js`.

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
| New shared fields are optional and new DTO parameters trailing | `PullImportSummary`/`PullImportProgress` are built positionally by existing providers and tests; providers that do not send `banners`, `multiple` or `roll_label` keep the previous UI. |

### Not shared on purpose

- **Message classes**: each one routes to its provider's Messenger transport through `#[AsMessage]`.
- **`AbstractBatchTaskMessageHandler`**: sharing it saves almost no lines, risks re-declared promoted `readonly` properties, and `CodeRedeemBatchHandler` has its own constructor.
- **Pull backup importers / synthetic reconcilers** (interfaces and managers): typed on each provider's entities; sharing them would mean losing those types.
- **Login modal**: only the flow is shared (`useLoginWizard` + `CaptchaStep`); `Modal` + `Tabs` and the credential fields stay in each provider's `AddAccountModal`.
- **Controllers, pull import/sync services, entities, auth services, normalizers**: similar structure, different details (normalizers already share `AbstractGameProfileNormalizer`).

### Possible future unifications

- `AbstractProfileTaskMessageHandler` and the reminder handlers (Dailies/Weeklies/Stamina/Check-in): their logic is identical but would need 4 extension points (game service lookup by `gameId`/`gameBiz`, runtime account data with cookie parsing, exception context, fallback icon).
- Pull endpoints of `ApiGameProfilesController`, `PullBackupImportService`, sync/import handlers.
- Check-in setting names (`hoyolab_check_in` / `skport_check_in` → `check_in`): requires renaming database columns.

---

## 5. Testing

- `tests/GamesBundle/Routing/GamesRoutingTest` boots the kernel and matches real routes on the games host (provider APIs, GeeTest script/frames, SPA catch-all, legacy `/api/accounts` not routed, provider routes bound to the games host only).
- `tests/GamesBundle/Security/GameAccountOwnerRoutesTest` boots the kernel, collects every provider route with `{account}` and runs Symfony's `IsGrantedAttributeListener` on each action: the owner passes, another user gets a 403. `GameAccountOwnerVoterTest` covers the voter for the three account entities.
- `tests/GamesBundle/Doctrine/EncryptedCredentialMappingTest` boots the kernel and checks that exactly the five credential columns of the game accounts use the `encrypted_text` type and are found by `core:database:encrypt` (metadata only, no database connection). The type itself is tested in `CoreBundle` (see the [Encrypted Columns guide](../CoreBundle/src/Service/Encryption/README.md#6-testing)).
- Unit tests cover `GameProviderAccess`, provider-namespaced Mercure topics, `GameTasksConfiguration`, `AbstractTaskScheduleProvider`, `GeetestService::createFrameResponse()` and the shared normalizer flags (`supports_pulls`, `supports_pull_sync`).
- Security (games page protected by `ROLE_USER_GAMES`, provider APIs by their roles, public PowerShell script) is not covered by automated tests: a `WebTestCase` would need a test database for the session (`PdoSessionHandler`). Check it manually with a user holding a single provider role.
- There is no JavaScript test setup: after frontend changes run `npm run build` and check both providers in the browser (tabs, accounts, settings drawer, login modal, pulls page with live Mercure progress, diary).
