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
- **Profile & Current User:** `/profile` page (same on every subdomain, `core` Encore entry) showing the logged-in user's email (read-only) and roles (display name, grouped by bundle, assigned vs inherited through the role hierarchy, via `GET /api/me/roles`), and `GET /api/me` returning `id`, `email` and the reachable `roles`; `AppRoot` loads it once into `CurrentUserContext` (`useCurrentUser()`), and the `PageLayout` header dropdown shows the email and links to the profile page.
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
