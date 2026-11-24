# WebsiteValdi

A modular, multi-subdomain web platform built with **Symfony 8.1**, **Doctrine ORM 3**, **React 19**, and **Ant Design 6** (bundled via **Webpack Encore**), served behind **Caddy + Mercure Hub** with asynchronous background workers (**Symfony Messenger** & **Scheduler**) and a supporting **Node.js / Playwright** microservice.

Each functional domain is isolated into its own **Symfony Bundle** under [`src/`](src/) with dedicated entities, REST APIs, React SPAs, and subdomain routing (`<bundle>.%domain_name%`), built on top of shared infrastructure provided by `CoreBundle`.

---

## Multi-Domain Architecture & Stack

| Subdomain | Bundle | Primary Purpose |
| :--- | :--- | :--- |
| *(shared `/login`, `/logout`)* | [`CoreBundle`](src/CoreBundle) | Authentication, permissions, File Manager, Standard Table, notifications, UI kit |
| `anime.<domain>` | [`AnimeBundle`](src/AnimeBundle) | Anime/Manga tracker (MAL/AniList), automated episode downloader & file manager |
| `books.<domain>` | [`BooksBundle`](src/BooksBundle) | E-Book library, shelves, in-browser EPUB reader (`foliate-js`) & file manager |
| `games.<domain>` | [`GamesBundle`](src/GamesBundle) | Unified games frontend (one tab per provider) and code shared by game provider bundles |
| *(`games.<domain>` → HoYoverse tab)* | [`HoyoverseBundle`](src/HoyoverseBundle) | HoYoLAB automation, check-in, promo code redemption, notes/endgame reminders, Diary & Pulls Tracker |
| *(`games.<domain>` → Gryphline tab)* | [`GryphlineBundle`](src/GryphlineBundle) | Gryphline/SKPort automation (Arknights: Endfield), check-in, reminders & Headhunting Pulls |
| *(`games.<domain>` → Perfect World tab)* | [`PerfectworldBundle`](src/PerfectworldBundle) | Perfect World account login & profiles (Neverness to Everness) with a Pulls Tracker fed by nte-exporter imports |
| `videos.<domain>` | [`VideosBundle`](src/VideosBundle) | Personal video archive with web file manager & in-browser streaming player |
| `mercure.<domain>` | **Caddy + Mercure** | Real-time SSE hub pushing live updates to React clients |

- **Backend:** PHP 8.5, Symfony 8.1, Doctrine ORM 3, MySQL/MariaDB, League Flysystem, LiipImagine, `kiwilan/php-ebook`, `norkunas/youtube-dl-php`.
- **Frontend:** React 19, React Router 7, Ant Design 6 (`cssVar`), SWR, Axios, CodeMirror, Plyr, `foliate-js`.
- **Background & External Tools:** Caddy + Mercure, FFmpeg/FFprobe, `yt-dlp`, Supervisor (Messenger & Scheduler workers), and [`node-services/`](node-services/) (Express + Playwright Chromium).

---

## Bundle Architecture & Core Features

### 1. [`CoreBundle`](src/CoreBundle) — Shared Foundation & UI Kit
Provides the cross-cutting infrastructure shared across all application bundles:
- **Database-Backed Security & RBAC:** centralized authentication (`/login`, `/logout`), `User`, `Role`, `Permission`, `Token`, `RoleHierarchy`, and `RolePermission` entities, custom voters (`PermissionVoter`, `DatabaseRoleHierarchyVoter`), and API bearer/access token authentication (`AccessTokenHandler`).
- **Profile & Current User:** `/profile` page (same on every subdomain, `core` Encore entry) showing the logged-in user's profile image, email (read-only) and roles (display name, grouped by bundle, assigned vs inherited through the role hierarchy, via `GET /api/me/roles`), and `GET /api/me` returning `id`, `email`, the reachable `roles` and `avatar_url`. The profile image is uploaded with `POST /api/me/avatar` (JPEG/PNG/WebP, `#[MapUploadedFile]` + `#[Assert\Image]`) and removed with `DELETE /api/me/avatar` (`UserAvatarManager`): it is normalized on upload (one frame, EXIF orientation applied, no metadata, at most 512 px, webp; animated or undecodable images are rejected before replacing the current one) and stored in the database (`user_avatar`, an extension of the user row: its primary key is a foreign key on `user.id`, deleted with the user; the database backup includes it), then shown through the `core_avatar` LiipImagine filter set (`UserAvatarLoader`, 256×256 centre crop, webp) under a random name that changes on every upload; the email's initial is the default (`UserAvatar`); `AppRoot` loads it once into `CurrentUserContext` (`useCurrentUser()`), and the `PageLayout` header dropdown shows the email and links to the profile page.
- **Connected Accounts:** "Connected accounts" card of the profile page (`ConnectedAccountsCard`), one row per external account. **Discord** is linked with the OAuth2 authorization code grant, `identify` scope only, no login with Discord (`DiscordLinkController`, `DiscordOAuthClient` on the `discord.client` scoped HTTP client): `/profile/discord/connect` keeps a one-time `state` and the starting subdomain in the session and redirects to Discord; the callback `/profile/discord/callback` is always on `DEFAULT_URI` (one redirect URI to register per environment; the session cookie is shared on `%domain_name%`), checks the `state`, exchanges the code, reads `GET /users/@me`, revokes the token and saves the Discord id (unique index), username, display name and avatar hash on `User` (`DiscordAccountLinker`: an id linked to another user is refused), then goes back to `/profile?discord=<result>` on the starting subdomain. `GET /api/me/discord` gives the linked account, with the avatar URL built from the hash or Discord's default avatar (`DiscordCdn`), and `DELETE /api/me/discord` unlinks it. Names and avatar are refreshed when the account is linked again. Disabled while `DISCORD_CLIENT_ID` / `DISCORD_CLIENT_SECRET` are empty. **Telegram** is linked the same way with "Log In With Telegram", the OpenID Connect authorization code flow with PKCE, scopes `openid profile telegram:bot_access`, no login with Telegram (`TelegramLinkController` on `/profile/telegram/connect` and `/profile/telegram/callback`, `TelegramOAuthClient` on the `telegram.oauth.client` scoped HTTP client): the code is exchanged for an id token, whose RS256 signature is checked against Telegram's keys (`web-token/jwt-library`) with its issuer, audience and times, and whose `id` claim (not `sub`) is the Telegram user id, also the chat id of the private chat with the bot. Since the user can refuse "Allow Messages" and the token does not tell, `TelegramAccountLinker` has the bot send a welcome message at once (`TelegramBot`, through the chatter transports and not Messenger) before saving the id (unique index), username, name and profile picture URL (the `picture` claim, a `t.me/i/userpic` address that redirects to Telegram's CDN, present only when the user shows a picture) on `User`: a refused message goes back with `?telegram=messages_not_allowed`. `GET /api/me/telegram` and `DELETE /api/me/telegram` read and unlink it. The picture address is the user's and Telegram redirects it to their current picture, so a new picture shows without linking again; since browsers keep that redirect for an hour (`max-age=3600`), the row adds a parameter of its own at each load, which Telegram ignores. The row shows the initial when there is no picture or it does not load; it loads the picture first and keeps the initial for the 1×1 GIF that Telegram serves, with status 404, once the user has removed or hidden their picture. Names are refreshed when the account is linked again, and a picture removed or hidden on Telegram is dropped then. Disabled while `TELEGRAM_CLIENT_ID` / `TELEGRAM_CLIENT_SECRET` are empty, or when the client id is not the bot of `TELEGRAM_DSN`.
- **File Manager & Video Streaming (`FileManagerTrait` + `FileManager`):** full filesystem management via Flysystem (directory tree navigation, file upload, create/rename/move/copy/delete, CodeMirror code editor, file preview, download, and video streaming via HTTP Range requests, with the files served by Caddy through `X-Accel-Redirect`). Includes the shared [`VideoPlayer`](src/CoreBundle/assets/components/VideoPlayer.jsx) powered by **Plyr**.
- **Standard Table Architecture:** declarative table engine supporting both server-side (`RemoteTable`) and client-side (`LocalTable`) pagination, sorting, and multi-condition filtering:
  - **Backend** ([guide](src/CoreBundle/src/Model/StandardTable/README.md)): `TableParameters`, `TableConfiguration`, `TableJoin`, `TableRepositoryTrait` (flat scalar projections or entity hydration via `OffsetPaginator`, DQL filter operators, composite PK distinct counting).
  - **Frontend** ([guide](src/CoreBundle/assets/components/standard-table/README.md)): `BaseTable`, `MultiFilterDropdown`, `TableFilterRibbon`, `TableToolbar`, `TableMobileFilterDrawer`, search text highlighting, tag catalogs, and dynamic tooltips.
- **Encrypted Columns** ([guide](src/CoreBundle/src/Service/Encryption/README.md)): `encrypted_text` Doctrine type storing secrets encrypted at rest with AES-256-GCM (`DatabaseCipher`, key `DATABASE_ENCRYPTION_KEY`, plain text while it is unset) and the `core:database:encrypt` command converting the existing values (`--dry-run`, `--decrypt`); used today by the game account credentials.
- **Unified Notifications (`UnifiedNotificationService`):** delivers rich `UniversalEmbed` alerts to **Discord** (formatted embeds) and **Telegram** (HTML rendering via `TelegramHtmlRenderer` and `TelegramParsedown`).
- **Shared Models & Utilities:** common Gacha game interfaces and DTOs (`GameNotes*`, `GachaConfig`, `RarityConfig`, `GameAccountInterface`, `GameProfileInterface`), **GeeTest** integration (`GeetestService`, `GeetestCaptchaFrame`), light/dark theme management (`ThemeContext`), `#[ResolveEntityFromRoute]` attribute, and centralized user-facing HTTP exception handling.

---

### 2. [`AnimeBundle`](src/AnimeBundle) — Anime/Manga Tracker & Episode Downloader
Manages personal watch/read lists and automated episode downloading:
- **Tracker Synchronization (`MyAnimeList` & `AniList`):** syncs and caches user anime/manga lists locally (`ListAnime`, `ListManga`) with `StandardTable`-powered browsing and filtering, abstracted through `AnimeTrackerLocator`.
- **Asynchronous Download Manager:** queues and executes episode downloads on a dedicated Messenger transport (`anime_episode_download`) using `yt-dlp` and `FFmpeg`, tracking attempts and states (`EpisodeDownload`, `EpisodeDownloadAttempt`, `EpisodeDownloadTracker`).
- **Providers & Release Monitoring:** supports **AnimeWorld** (including socket listener and `node-services` Playwright integration) and **AnimeUnity** for scraping episodes and notifying about new releases (`AnimeCheckNewEpisodesCommand`).
- **Season Folders & File Manager:** maps tracker entries to local directories (`SeasonFolder` with `episode_offset` support) and provides direct access to `FileManager` and `VideoPlayer` over the shared media directory (`ANIME_BASE_FOLDER`).

---

### 3. [`BooksBundle`](src/BooksBundle) — E-Book Library & In-Browser EPUB Reader
Digital book library manager with a built-in web reader:
- **Libraries & Shelves:** manages multiple libraries (`Library` with isolated `basePath`), hierarchical shelves (`Shelf`), and an unassigned books view (`Not in Shelves`), with live updates via **Mercure**.
- **Metadata & Covers:** extracts EPUB metadata via `kiwilan/php-ebook` (`EbookLoader`, `BookMetadata`) and generates/caches cover thumbnails with `LiipImagineBundle` (`BookCoverLoader`).
- **EPUB Reader (`foliate-js`):** in-browser reading powered by **HTTP Range** streaming (`httpRangeReader.js`), customizable themes/fonts/spacing/layouts, automatic reading progress sync (`BookProgress`), bookmarks (`BookBookmark`), and highlights/notes (`BookNote`).
- **File Manager:** manages files and folders inside the active library using `FileManager`.

---

### 4. [`HoyoverseBundle`](src/HoyoverseBundle) — HoYoverse / HoYoLAB Ecosystem
Automation and tracking suite for **Genshin Impact**, **Honkai: Star Rail**, **Zenless Zone Zero**, **Honkai Impact 3rd**, and **Tears of Themis** ([full guide](src/HoyoverseBundle/README.md)):
- **Authentication & Accounts:** direct email/password login (with GeeTest Aigis captcha and Email OTP) or cookie import, `account_uid` deduplication, and automatic session/cookie refresh.
- **Scheduled Automations (`Messenger` + `Scheduler`):**
  - **Auto Check-In** on HoYoLAB, with a midday missed check-in that claims a reward still unclaimed.
  - **Code Redemption:** automatic API redemption for *GI*, *HSR*, and *ZZZ* (plus manual notifications for *HI3* / *ToT*).
  - **Real-Time Reminders:** Stamina (Resin / Trailblaze Power / Battery), Dailies, Weeklies, Expeditions/Assignments, Serenitea Pot, Video Store (*ZZZ*), and Endgame cycle alerts.
- **Diary & Pulls Tracker:** monthly currency income sync (`HoyoverseDiaryEntry`) and full Gacha pull history tracking (1-click / scheduled via `stoken_v2` for *GI*/*ZZZ*, or via PowerShell script import for *GI*/*HSR*/*ZZZ*).

---

### 5. [`GryphlineBundle`](src/GryphlineBundle) — Gryphline / SKPort Ecosystem
Automation and tracking suite for **Arknights: Endfield** on Gryphline / SKPort ([full guide](src/GryphlineBundle/README.md)):
- **Authentication & Request Signing:** direct email/password login (GeeTest v4) or `ACCOUNT_TOKEN`, 3-step OAuth token exchange, and `HMAC-SHA256` request signing (`SkportSignerService`).
- **Automations & Reminders:** daily SKPort auto check-in, Stamina (Sanity) monitoring with configurable thresholds, and regional Dailies/Weeklies reminders.
- **Headhunting Pulls Tracker:** 1-click manual and scheduled nightly pull history synchronization via `u8_token` for Character and Weapon Headhunting banners.

---

### 6. [`PerfectworldBundle`](src/PerfectworldBundle) — Perfect World Games
Account and pull tracking for **Neverness to Everness** ([full guide](src/PerfectworldBundle/README.md)):
- **Authentication:** Perfect World Games login with email + one-time code (no password, no captcha) or a token pasted from the NTE website, with token expiry tracking.
- **Profiles:** game roles (UID, nickname, level, server, avatar) synced from the account.
- **Pulls Tracker:** multi-file import of [nte-exporter](https://github.com/RishiMenon2004/nte-exporter) exports with automatic repair of old exporter files, board/Arc pity rules and an item catalog/icons built from the NTE_Assets datamine.

---

### 7. [`VideosBundle`](src/VideosBundle) — Personal Video Archive & Streaming
Lightweight application for browsing and streaming videos stored in `VIDEOS_BASE_FOLDER`:
- Exposes a dedicated web interface built around `CoreBundle`'s [`FileManager`](src/CoreBundle/assets/components/file-manager/FileManager.jsx) and [`VideoPlayer`](src/CoreBundle/assets/components/VideoPlayer.jsx).
- Supports folder management, file operations, and optimized direct streaming via `FileManagerTrait` (`X-Accel-Redirect` to Caddy and HTTP Range support).

---

### 8. Supporting Services (`node-services/`)
A **Node.js / Express** microservice ([`node-services/`](node-services/)) powered by **Playwright (Chromium)** and `p-queue`:
- Handles dynamic browser scraping/extraction for the **AnimeWorld** provider (`/animeworld`) with bounded concurrency and a `/health` endpoint monitored by Supervisor.

---

## Documentation

All technical documentation is located in [`docs/`](docs/README.md):

- 🖥️ **[Infrastructure, First Installation, Updates & Shared Folder](docs/SERVER_SETUP_AND_DEPLOY.md)** — Stack overview (Caddy + Mercure, PHP 8.5, Node/Playwright, Supervisor), server provisioning with `scripts/setup-server.sh`, deployment via `scripts/website-deploy.sh`, and shared folder setup via `scripts/setup-shared-folder.sh`.
- 🐳 **[Docker Development Setup](docs/DOCKER_DEV_SETUP.md)** — Optional local environment with Docker Compose (Caddy + Mercure, PHP 8.5-FPM, MySQL, Webpack): steps from a clean clone to the login page.
- 🧩 **[Creating a New Bundle](docs/CREATING_A_BUNDLE.md)** — Standard layout, Composer/Symfony registration, Webpack Encore setup, and Caddy subdomain routing.
- 📚 **[Full Documentation Index](docs/README.md)** — Links to detailed guides for `StandardTable` (Frontend & Backend), `HoyoverseBundle`, `GryphlineBundle`, and `PerfectworldBundle`.
