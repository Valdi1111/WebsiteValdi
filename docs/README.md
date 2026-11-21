# Project Documentation

## Infrastructure, Setup & Development

- 🖥️ **[Server Setup, Stack, Deploy & Shared Folder](SERVER_SETUP_AND_DEPLOY.md)**  
  Stack architecture (Caddy + Mercure, PHP 8.5, Node/Playwright, Supervisor), first-time installation, automated updates/deployment, and shared folder configuration (`condivisi`, POSIX ACLs, Samba).

- 🐳 **[Docker Development Setup](DOCKER_DEV_SETUP.md)**  
  Optional local environment (Apache + PHP 8.5-FPM, MySQL, Mercure, Webpack): certificate, hosts entry, `.env.local`, build, migrations, first user, tests.

- 🧩 **[Creating a New Bundle](CREATING_A_BUNDLE.md)**  
  Standard bundle directory layout, `AbstractBundle` class, multi-domain routing, PSR-4 autoloading, Webpack Encore, and Caddy subdomain setup.

## Core Standard Table Architecture

- 🎨 **[Frontend Standard Table Guide](../src/CoreBundle/assets/components/standard-table/README.md)**  
  Comprehensive reference for Ant Design 6.x table components (`RemoteTable`, `LocalTable`, `BaseTable`), declarative column options (`valueType`, `filterType`, tag/badge catalogs, dynamic tooltips), and multi-condition filtering.

- ⚙️ **[Backend Standard Table Guide](../src/CoreBundle/src/Model/StandardTable/README.md)**  
  Backend DQL and Doctrine 3.x architecture (`TableRepositoryTrait`, `TableConfiguration`, `TableJoin`), execution paths (scalar projection Path B vs entity hydration Path A with `OffsetPaginator`), N+1 mitigation, composite primary key counting, and query parameter bindings.

## Core Encrypted Columns

- 🔐 **[Encrypted Columns Guide](../src/CoreBundle/src/Service/Encryption/README.md)**\
  `encrypted_text` Doctrine type (AES-256-GCM, `enc:v1:` format, `DATABASE_ENCRYPTION_KEY`), behaviour with and without a key, encrypting a column in a bundle, the `core:database:encrypt` conversion command (`--dry-run`, `--decrypt`), and what is not encrypted.

## Game Integration Bundles

- 🎮 **[Games Bundle Guide](../src/GamesBundle/README.md)**  
  `GamesBundle`: shared game models and interfaces, pull tracking and icons, GeeTest/auth helpers, scheduled task configuration, and the unified `games.<domain>` frontend with one tab per provider.

- 🌌 **[HoYoverse Bundle Guide](../src/HoyoverseBundle/README.md)**  
  Complete reference for `HoyoverseBundle` (Genshin Impact, Honkai: Star Rail, Zenless Zone Zero, Honkai Impact 3rd, Tears of Themis): direct email/password login with GeeTest Aigis & Email OTP, cookie management & auto-refresh, daily check-in, automatic promo code redemption, real-time Game Notes & Endgame reminders, monthly Diary currency sync, and Pulls Tracker (automatic `stoken_v2` & PowerShell script import).

- 🛰️ **[Gryphline Bundle Guide](../src/GryphlineBundle/README.md)**  
  Complete reference for `GryphlineBundle` (Arknights: Endfield): direct email/password login with GeeTest v4, `ACCOUNT_TOKEN` OAuth 3-step exchange & `HMAC-SHA256` request signing, SKPort daily check-in, Stamina/Dailies/Weeklies reminders, and automatic Headhunting Pulls sync via `u8_token`.
- 🧭 **[Perfect World Bundle Guide](../src/PerfectworldBundle/README.md)**  
  Reference for `PerfectworldBundle` (Neverness to Everness): Perfect World Games email + code or token login, profile sync from the role list, multi-file nte-exporter imports (old-export repair, identity and upsert rules, board/Arc pity), and the NTE_Assets item catalog with icons.

- 🖼️ **[Pull Item Icons & Image Caching](PULL_ITEM_ICONS.md)**  
  Shared `GamesBundle` pipeline (`PullItemIconResolverInterface`, `PullItemIconResolverManager`, `PullItemIconBinaryLoader`, `LiipImagineBundle` `games_pull_item_icon` WebP cache) and external CDN providers (`NanokaIconResolver`, `AkeDataIconResolver`).
