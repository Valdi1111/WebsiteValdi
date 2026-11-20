# Pull Item Icons & Image Caching Architecture

This guide documents the shared **Pull Item Icon** resolution and caching pipeline implemented in `GamesBundle` and consumed by `HoyoverseBundle` and `GryphlineBundle`.

---

## 1. Architecture Overview

Pull records stored in the database (`HoyoversePull`, `GryphlinePull`) do not persist remote CDN image URLs. Instead, the backend generates deterministic local **LiipImagine** URLs at read time and resolves/caches the external images lazily on first request:

```text
API Request (/pulls/overview or /pulls)
  └─ PullItemIconUrlBuilder::enrichPullRows($gameService, $rows)
       ├─ PullItemIconPathCodec::encode($gameBiz, PullItemIconQuery)
       └─ CacheManager::getBrowserPath($path, 'games_pull_item_icon')
            └─ returns "/media/cache/resolve/games_pull_item_icon/..." (or direct cached path)

Browser <img src="..." loading="lazy" />
  ├─ Already cached on disk ──► served directly from public/media/cache/games_pull_item_icon/
  └─ Cache miss (/media/cache/resolve/games_pull_item_icon/...)
       └─ PullItemIconBinaryLoader::find($path)  [loader: games.pull_item_icon_loader]
            ├─ PullItemIconPathCodec::decode($path)
            ├─ PullItemIconResolverManager::resolveCandidateUrlsByGameBiz(...)
            │    └─ Queries registered providers ordered by descending priority
            ├─ Downloads source image via games.pull_icon_fetch.client (cascade fallback)
            └─ LiipImagine converts & resizes to 128×128 WebP (quality: 100)
                 └─ Writes public/media/cache/games_pull_item_icon/...
```

### Design Principles
- **Game-agnostic resolvers (`PullItemIconResolverInterface`)**: Each resolver represents an **external CDN/API provider** (not a game). It declares which game services it supports along with a priority weight, and receives the target `$gameService` as an argument.
- **Multi-provider priority & fallback**: Multiple resolvers can declare support for the same game service with different priorities. `PullItemIconResolverManager` orders them by descending priority, and `PullItemIconBinaryLoader` tries each candidate URL until a valid image (`HTTP 200`, `image/*`) is downloaded.
- **No external network calls during API list/overview requests**: Generating URLs is purely local string encoding; CDN catalog fetches and image downloads only happen on a LiipImagine cache miss.

---

## 2. `GamesBundle` Components

| Component | Path | Responsibility |
| :--- | :--- | :--- |
| **`PullItemIconQuery`** | `src/GamesBundle/src/Model/Game/PullItemIconQuery.php` | Immutable lookup DTO (`itemId`, `name`, `itemType`, `rarity`) with `fromPullRow()` helper. |
| **`PullItemIconResolverInterface`** | `src/GamesBundle/src/Model/Game/PullItemIconResolverInterface.php` | Contract for external icon providers (`getSupportedGameServices()`, `resolveUrl()`). |
| **`GamesBundle::loadExtension()`** | `src/GamesBundle/GamesBundle.php` | Registers autoconfiguration tag `games.pull_item_icon_resolver` on `PullItemIconResolverInterface`. |
| **`PullItemIconResolverManager`** | `src/GamesBundle/src/Service/PullItemIconResolverManager.php` | Collects tagged resolvers via `#[AutowireIterator('games.pull_item_icon_resolver')]`, indexes them by game service FQCN and `gameBiz`, sorts by descending priority, and produces candidate URL lists. |
| **`PullItemIconPathCodec`** | `src/GamesBundle/src/Service/PullItemIconPathCodec.php` | Encodes/decodes URL-safe relative paths without exposing raw external URLs (`{gameBiz}/id/{type}/{itemId}.webp` or `{gameBiz}/name/{type}/{base64url(name)}.webp`). |
| **`PullItemIconUrlBuilder`** | `src/GamesBundle/src/Service/PullItemIconUrlBuilder.php` | Builds browser URLs for `games_pull_item_icon` via `CacheManager` and provides `enrichPullRows()` for API controllers. |
| **`PullItemIconBinaryLoader`** | `src/GamesBundle/src/Service/PullItemIconBinaryLoader.php` | Custom LiipImagine data loader (`games.pull_item_icon_loader`) that decodes paths, downloads the first reachable candidate image, and returns a `Binary` object (or throws `NotLoadableException`). |
| **`PullIconResolversCommand`** | `src/GamesBundle/src/Command/PullIconResolversCommand.php` | CLI diagnostic tool (`app:pulls:icon-resolvers`) to inspect resolved URLs and test CDN responses. |

### Path Encoding Rules (`PullItemIconPathCodec`)
- When `itemId` is present and matches `^[A-Za-z0-9_-]+$` (HSR, ZZZ, Endfield):
  - `{gameBiz}/id/{typeSlug}/{itemId}.webp` (e.g. `hkrpg_global/id/character/1001.webp`)
- When `itemId` is empty (e.g. Genshin Impact HoYoverse gacha logs, which omit `item_id`) and `name` is present:
  - `{gameBiz}/name/{typeSlug}/{base64url(name)}.webp` (e.g. `hk4e_global/name/character/S2VxaW5n.webp`)

### LiipImagine & HTTP Client Configuration
- `src/GamesBundle/config/packages/liip_imagine.yaml`:
  - Filter set **`games_pull_item_icon`**:
    - `data_loader: games.pull_item_icon_loader`
    - `format: webp`
    - `quality: 100`
    - `thumbnail: { size: [128, 128], mode: inset, allow_upscale: false }`
  - Cached files are stored under `public/media/cache/games_pull_item_icon/`.
- `src/GamesBundle/config/packages/framework.yaml`:
  - Scoped HTTP client **`games.pull_icon_fetch.client`** (`scope: 'https://.*'`, `timeout: 8`, `retry_failed.max_retries: 1`).

---

## 3. Concrete External Providers

| Provider | Bundle | Supported Game Services (`priority`) | Strategy & Upstream CDN |
| :--- | :--- | :--- | :--- |
| **`NanokaIconResolver`** | `HoyoverseBundle` | `ZenlessZoneZeroService::class => 100`<br>`HonkaiStarRailService::class => 100`<br>`GenshinImpactService::class => 100` | **ZZZ**: `manifest.json` (`zzz.live`) + `zzz/{ver}/{character\|weapon\|bangboo}.json` (`IconRole*` → `IconRoleCircle*` for agents) → `https://static.nanoka.cc/assets/zzz/{icon}.webp`<br>**HSR**: `https://static.nanoka.cc/assets/hsr/avatarroundicon/{itemId}.webp` (characters) & `lightconemediumicon/{itemId}.webp` (light cones)<br>**Genshin**: `manifest.json` (`gi.live`) + `gi/{ver}/{character\|weapon}.json` normalized English name lookup → `https://static.nanoka.cc/assets/gi/{icon}.webp` |
| **`AkeDataIconResolver`** | `GryphlineBundle` | `EndfieldService::class => 100` | **Endfield**: `manifest.json` (`latest.tableCfgPath`) + `asset-sync-index.json` + `ItemTable.json` → `charremoteicon/icon_{charId}` (operators) and `itemicon/{iconId}` with `gachaweapon/` fallback (weapons) → `https://data.akedata.wiki/public/images/{matchedPath}` |

### Catalog Caching & Resilience
Both providers use dedicated Symfony filesystem cache pools (`hoyoverse.item_icons.cache` and `gryphline.item_icons.cache`, 24-hour TTL):
- **Stampede protection**: `CacheInterface::get()` locks concurrent catalog builds when the browser opens many parallel image requests on first visit.
- **Compact indices**: Only normalized lookup maps (e.g. `[id => iconPath]` / `[normalizedName => icon]`) are cached rather than full upstream JSON payloads.
- **Short negative backoff**: Failed upstream catalog requests are cached for `300s` (`5m`) so an external outage does not stall every thumbnail request on the page.

---

## 4. Backend & Frontend Integration

### Backend (Repositories & Controllers)
- [`HoyoversePullRepository::computeBannerStats()`](../src/HoyoverseBundle/src/Repository/HoyoversePullRepository.php) and [`GryphlinePullRepository::computeBannerStats()`](../src/GryphlineBundle/src/Repository/GryphlinePullRepository.php) include `'item_id'` in each `recent_s_pulls` entry.
- Both `ApiGameProfilesController` classes enrich `recent_s_pulls` (in `/pulls/overview`) and paginated `items` (in `/pulls`) via `PullItemIconUrlBuilder::enrichPullRows($gameService, ...)`, appending `'icon_url'`.

### Frontend (React / Ant Design)
Both `HoyoverseBundle` and `GryphlineBundle` use identical circular icon components with lazy loading and silent monogram fallback on `onError`:
- **`PullsRecentBadge`** (`PullsBannerCard.jsx`): `52×52` circular avatar (`borderRadius: "50%"`) with an inner `overflow: "hidden"` container so the pity pill badge at `bottom: -4, right: -4` is never clipped.
- **`PullItemThumbnail`** (`PullsBannerDetail.jsx`): `32×32` circular avatar (`borderRadius: "50%"`) rendered alongside the item name in the pulls table.

---

## 5. Adding a New Provider or Game

1. Implement `App\GamesBundle\Model\Game\PullItemIconResolverInterface` in any bundle:
   ```php
   public static function getSupportedGameServices(): array
   {
       return [
           MyNewGameService::class => 100,
       ];
   }
   ```
2. Make sure the target game service exposes `public static function getGameBiz(): string`.
3. Register the service in Symfony DI (autoconfiguration automatically applies the `games.pull_item_icon_resolver` tag).
4. If the game needs catalog lookups, configure a dedicated HTTP client and cache pool with `CacheInterface::get()` stampede protection.

---

## 6. Diagnostics & Cache Management

- **List registered resolvers**:
  ```bash
  php bin/console debug:container --tag=games.pull_item_icon_resolver
  ```
- **Test URL resolution and live CDN responses**:
  ```bash
  php bin/console app:pulls:icon-resolvers -g hkrpg_global --id=1001 -t Character --check-http
  php bin/console app:pulls:icon-resolvers -g nap_global --id=1011 -t Agents --check-http
  php bin/console app:pulls:icon-resolvers -g hk4e_global --name="Keqing" -t Character --check-http
  php bin/console app:pulls:icon-resolvers -g endfield --id=chr_0004_pelica -t char --check-http
  ```
- **Clear cached pull item thumbnails** (will regenerate on next page load):
  ```bash
  php bin/console liip:imagine:cache:remove --filter=games_pull_item_icon
  ```
