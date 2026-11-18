# Project Documentation

## Core Standard Table Architecture

- 🎨 **[Frontend Standard Table Guide](../src/CoreBundle/assets/components/standard-table/README.md)**  
  Comprehensive reference for Ant Design 6.x table components (`RemoteTable`, `LocalTable`, `BaseTable`), declarative column options (`valueType`, `filterType`, tag/badge catalogs, dynamic tooltips), and multi-condition filtering.

- ⚙️ **[Backend Standard Table Guide](../src/CoreBundle/src/Model/StandardTable/README.md)**  
  Backend DQL and Doctrine 3.x architecture (`TableRepositoryTrait`, `TableConfiguration`, `TableJoin`), execution paths (scalar projection Path B vs entity hydration Path A with `OffsetPaginator`), N+1 mitigation, composite primary key counting, and query parameter bindings.

## Game Integration Bundles

- 🌌 **[HoYoverse Bundle Guide](../src/HoyoverseBundle/README.md)**  
  Complete reference for `HoyoverseBundle` (Genshin Impact, Honkai: Star Rail, Zenless Zone Zero, Honkai Impact 3rd, Tears of Themis): direct email/password login with GeeTest Aigis & Email OTP, cookie management & auto-refresh, daily check-in, automatic promo code redemption, real-time Game Notes & Endgame reminders, monthly Diary currency sync, and Pulls Tracker (automatic `stoken_v2` & PowerShell script import).

- 🛰️ **[Gryphline Bundle Guide](../src/GryphlineBundle/README.md)**  
  Complete reference for `GryphlineBundle` (Arknights: Endfield): direct email/password login with GeeTest v4, `ACCOUNT_TOKEN` OAuth 3-step exchange & `HMAC-SHA256` request signing, SKPort daily check-in, Stamina/Dailies/Weeklies reminders, and automatic Headhunting Pulls sync via `u8_token`.
