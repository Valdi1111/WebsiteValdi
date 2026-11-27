# Pull Item Icons & Image Caching Architecture

This guide documents the shared **Pull Item Icon** resolution and caching pipeline implemented in `GamesBundle` and consumed by `HoyoverseBundle`, `GryphlineBundle` and `PerfectworldBundle`: the round thumbnails of the pulls tracker (`icon_url`) and their click-to-zoom preview with the item's full artwork (`preview_url`).

---

## 1. Architecture Overview

Pull records stored in the database (`HoyoversePull`, `GryphlinePull`) do not persist remote CDN image URLs. Instead, the backend generates deterministic local **LiipImagine** URLs at read time and resolves/caches the external images lazily on first request:

```text
API Request (/pulls/overview or /pulls)
  └─ PullItemIconUrlBuilder::enrichPullRows($gameService, $rows)
       ├─ PullItemIconPathCodec::encode($gameBiz, PullItemIconQuery)   (one path per row, shared by both URLs)
       ├─ CacheManager::getBrowserPath($path, 'games_pull_item_icon')     → icon_url
       └─ CacheManager::getBrowserPath($path, 'games_pull_item_preview')  → preview_url
            └─ returns "/media/cache/resolve/<filter>/..." (or direct cached path)

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

Click on a thumbnail (antd Image preview, downloads preview_url only when opened)
  └─ Cache miss (/media/cache/resolve/games_pull_item_preview/...)
       └─ PullItemPreviewBinaryLoader::find($path)  [loader: games.pull_item_preview_loader]
            ├─ PullItemIconResolverManager::resolvePreviewCandidateUrlsByGameBiz(...)
            │    └─ Artwork URLs of the resolvers implementing PullItemPreviewResolverInterface
            ├─ Downloads the artwork; only when there is none (no URL, or every URL answered 404/410)
            │    falls back to the icon candidates. A timeout/5xx throws NotLoadableException (retried later)
            └─ LiipImagine trims the transparent borders (filter "trim"), resizes to at most 1024 px (WebP, quality 85)
                 └─ Writes public/media/cache/games_pull_item_preview/...
```

### Design Principles
- **Game-agnostic resolvers (`PullItemIconResolverInterface`)**: Each resolver represents an **external CDN/API provider** (not a game). It declares which game services it supports along with a priority weight, and receives the target `$gameService` as an argument.
- **Multi-provider priority & fallback**: Multiple resolvers can declare support for the same game service with different priorities. `PullItemIconResolverManager` orders them by descending priority, and `PullItemIconBinaryLoader` tries each candidate URL until a valid image (`HTTP 200`, `image/*`) is downloaded.
- **Optional artwork (`PullItemPreviewResolverInterface`)**: a resolver that also knows the full artwork of an item (gacha splash, portrait, weapon art) implements this extension of the icon interface. Items without artwork preview their icon source.
- **Never cache a fallback by accident**: LiipImagine keeps whatever a data loader returns for good, so the preview falls back to the icon only on a definitive absence of artwork; transient failures leave the preview unresolved.
- **No external network calls during API list/overview requests**: Generating URLs is purely local string encoding; CDN catalog fetches and image downloads only happen on a LiipImagine cache miss.

---

## 2. `GamesBundle` Components

| Component | Path | Responsibility |
| :--- | :--- | :--- |
| **`PullItemIconQuery`** | `src/GamesBundle/src/Model/Game/PullItemIconQuery.php` | Immutable lookup DTO (`itemId`, `name`, `itemType`, `rarity`) with `fromPullRow()` helper. |
| **`PullItemIconResolverInterface`** | `src/GamesBundle/src/Model/Game/PullItemIconResolverInterface.php` | Contract for external icon providers (`getSupportedGameServices()`, `resolveUrl()`). |
| **`PullItemPreviewResolverInterface`** | `src/GamesBundle/src/Model/Game/PullItemPreviewResolverInterface.php` | Optional extension for providers that know the full artwork (`resolvePreviewUrl()`, null when the item has none). |
| **`GamesBundle::loadExtension()`** | `src/GamesBundle/GamesBundle.php` | Registers autoconfiguration tag `games.pull_item_icon_resolver` on `PullItemIconResolverInterface`. |
| **`PullItemIconResolverManager`** | `src/GamesBundle/src/Service/PullItemIconResolverManager.php` | Collects tagged resolvers via `#[AutowireIterator('games.pull_item_icon_resolver')]`, indexes them by game service FQCN and `gameBiz`, sorts by descending priority, and produces candidate URL lists (icons: `resolveCandidateUrlsByGameBiz()`, artwork: `resolvePreviewCandidateUrlsByGameBiz()`). |
| **`PullItemIconPathCodec`** | `src/GamesBundle/src/Service/PullItemIconPathCodec.php` | Encodes/decodes URL-safe relative paths without exposing raw external URLs (`{gameBiz}/id/{type}/{itemId}.webp` or `{gameBiz}/name/{type}/{base64url(name)}.webp`). |
| **`PullItemIconUrlBuilder`** | `src/GamesBundle/src/Service/PullItemIconUrlBuilder.php` | Builds browser URLs via `CacheManager` and provides `enrichPullRows()` for API controllers (`icon_url` + `preview_url`; both null for filler rows and for bonus entries without `item_id`). |
| **`AbstractPullItemImageLoader`** | `src/GamesBundle/src/Service/AbstractPullItemImageLoader.php` | Base LiipImagine data loader: decodes the path and downloads the first candidate answering with an image (`Binary`), telling whether every candidate was missing (404/410). |
| **`PullItemIconBinaryLoader`** | `src/GamesBundle/src/Service/PullItemIconBinaryLoader.php` | Data loader `games.pull_item_icon_loader`: the icon candidates (or `NotLoadableException`). |
| **`PullItemPreviewBinaryLoader`** | `src/GamesBundle/src/Service/PullItemPreviewBinaryLoader.php` | Data loader `games.pull_item_preview_loader`: the artwork candidates, then the icon ones only when the item has no artwork. |
| **`TrimFilterLoader`** (CoreBundle) | `src/CoreBundle/src/Service/TrimFilterLoader.php` | LiipImagine filter `trim` (option `threshold`, the opacity up to which a pixel counts as transparent, default 0.05): removes the transparent borders, including the nearly invisible haze many artworks spread over their canvas (imagick driver, images with an alpha channel only). |
| **`PullIconResolversCommand`** | `src/GamesBundle/src/Command/PullIconResolversCommand.php` | CLI diagnostic tool (`app:pulls:icon-resolvers`) to inspect the resolved icon and artwork URLs and test CDN responses. |

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
  - Filter set **`games_pull_item_preview`**:
    - `data_loader: games.pull_item_preview_loader`
    - `format: webp`
    - `quality: 85`
    - `trim: { threshold: 0.05 }`, then `thumbnail: { size: [1024, 1024], mode: inset, allow_upscale: false }`
  - Cached files are stored under `public/media/cache/games_pull_item_icon/` and `public/media/cache/games_pull_item_preview/`.
- `src/GamesBundle/config/packages/framework.yaml`:
  - Scoped HTTP client **`games.pull_icon_fetch.client`** (`scope: 'https://.*'`, `timeout: 8`, `retry_failed.max_retries: 1`).

---

## 3. Concrete External Providers

| Provider | Bundle | Supported Game Services (`priority`) | Strategy & Upstream CDN |
| :--- | :--- | :--- | :--- |
| **`NanokaIconResolver`** | `HoyoverseBundle` | `ZenlessZoneZeroService::class => 100`<br>`HonkaiStarRailService::class => 100`<br>`GenshinImpactService::class => 100` | **ZZZ**: `manifest.json` (`zzz.live`) + `zzz/{ver}/{character\|weapon\|bangboo}.json` (`IconRole*` → `IconRoleCircle*` for agents) → `https://static.nanoka.cc/assets/zzz/{icon}.webp`<br>**HSR**: `https://static.nanoka.cc/assets/hsr/avatarroundicon/{itemId}.webp` (characters) & `lightconemediumicon/{itemId}.webp` (light cones)<br>**Genshin**: `manifest.json` (`gi.live`) + `gi/{ver}/{character\|weapon}.json` normalized English name lookup → `https://static.nanoka.cc/assets/gi/{icon}.webp` |
| **`AkeDataIconResolver`** | `GryphlineBundle` | `ArknightsEndfieldService::class => 100` | **Endfield**: `manifest.json` (`latest.tableCfgPath`) + `asset-sync-index.json` + `ItemTable.json` → `charremoteicon/icon_{charId}` (operators) and `itemicon/{iconId}` with `gachaweapon/` fallback (weapons) → `https://data.akedata.wiki/public/images/{matchedPath}` |
| **`NteAssetsIconResolver`** | `PerfectworldBundle` | `NevernessToEvernessService::class => 100` | **Neverness to Everness**: compact catalog built by `perfectworld:nte-catalog:update` from the Waifus-Grace/NTE_Assets tables (`ItemIcon.AssetPathName` → PNG path) → `https://raw.githubusercontent.com/Waifus-Grace/NTE_Assets/{sha}/{iconPath}` |

### Full artwork (`preview_url`)

All three providers implement `PullItemPreviewResolverInterface`. Previews show the source below, trimmed and at most 1024 px; the items marked "icon" have no artwork and preview their icon source.

| Game | Artwork | Without artwork (icon) |
| :--- | :--- | :--- |
| **Genshin Impact** | characters `UI_AvatarIcon_X` → `gi/UI_Gacha_AvatarImg_X` (wish splash, 2048×1024); weapons of the five standard classes `UI_EquipIcon_X` → `gi/UI_Gacha_EquipIcon_X` (512×1024) | other weapon icon classes and templated `_{0}` icons; `Claymore_LoliFriend` (404) |
| **Honkai: Star Rail** | characters `hsr/avatardrawcard/{id}` (2048×2048), light cones `hsr/lightconemaxfigures/{id}` (904×1260) | — |
| **Zenless Zone Zero** | agents `IconRoleCircle{n}` → `zzz/IconRole{n}` (portrait) | W-Engines (400×400 icon is the largest), Bangboos |
| **Arknights: Endfield** | operators `characterportrait/{charId}` (2048×2048), weapons `gachaweapon/{iconId}` (1024×2048), only for files listed in `asset-sync-index.json` (catalog key `akedata_endfield_catalog_v2`) | 2 operators, 4 weapons |
| **Neverness to Everness** | catalog `preview_path`: the `DataTable/Gacha/GachaIllustrate.json` image when it differs from the icon (character gacha splash or outfit art, 1024×1024; some cosmetics), else for an Arc `UI_Icon/Fork/1024/{name}` | items without illustration; 3 Arcs without a 1024 file (404). Needs a catalog rebuilt by `perfectworld:nte-catalog:update` |

### Catalog Caching & Resilience
Both providers use dedicated Symfony filesystem cache pools (`hoyoverse.item_icons.cache` and `gryphline.item_icons.cache`, 24-hour TTL):
- **Stampede protection**: `CacheInterface::get()` locks concurrent catalog builds when the browser opens many parallel image requests on first visit.
- **Compact indices**: Only normalized lookup maps (e.g. `[id => iconPath]` / `[normalizedName => icon]`) are cached rather than full upstream JSON payloads.
- **Short negative backoff**: Failed upstream catalog requests are cached for `300s` (`5m`) so an external outage does not stall every thumbnail request on the page.

---

## 4. Backend & Frontend Integration

### Backend (Repositories & Controllers)
- The pity calculators ([`HoyoversePullStatsCalculator`](../src/HoyoverseBundle/src/Service/Pull/HoyoversePullStatsCalculator.php), [`GryphlinePullStatsCalculator`](../src/GryphlineBundle/src/Service/Pull/GryphlinePullStatsCalculator.php), [`PerfectworldPullStatsCalculator`](../src/PerfectworldBundle/src/Service/Pull/PerfectworldPullStatsCalculator.php)) include `'item_id'` in each `recent_s_pulls` entry.
- The `ApiGameProfilesController` classes enrich `recent_s_pulls` (in `/pulls/overview`) and paginated `items` (in `/pulls`) via `PullItemIconUrlBuilder::enrichPullRows($gameService, ...)`, appending `'icon_url'` and `'preview_url'` (also to `oldest_s`, `pools[].featured` and the manual pull suggestions). Filler rows (`origin = filler`, the "?" placeholders of missing pulls) and bonus entries without an item (Endfield Headhunting Dossier) get both URLs `null` without a resolver lookup.

### Frontend (React / Ant Design)
All item images go through **`PullItemImage`** (`src/GamesBundle/assets/components/pulls/PullItemImage.jsx`, with the shared `getInitials()`): a round image with a silent monogram fallback (no icon or `onError`), or the "?" placeholder of unknown pulls. When the image is shown it is an antd `Image` whose preview opens `preview_url` (else `icon_url`) on click, downloaded only when opened; the default hover cover is disabled (`preview.cover: false`).
- **`PullsRecentBadge`** (`PullsBannerCard.jsx`): `52×52` inner circle, with the pity border and the UP / free / pity badges around it.
- **`PullItemThumbnail`** (`PullsBannerDetail.jsx`): `32×32`, rows of the pulls table and featured items of the banners table.
- **`SuggestionLabel`** (`ManualSPullModal.jsx`): `24×24`, without preview (a click selects the option).
- Images inside a `Tooltip` use `usePreviewTooltip()`, which keeps the tooltip closed while the preview is open and not stuck open after it closes (a tap on touch devices opens both).

The game profile avatar (square, `profile.icon_url`, no preview) is the separate `GameProfileAvatar` component (`game-profile/GameProfileAvatar.jsx`).

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
5. If the provider also has the full artwork of the items, implement `PullItemPreviewResolverInterface` instead (it extends the icon interface) and return `null` from `resolvePreviewUrl()` for the items without one: their preview shows the icon source.

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
- **Clear cached pull item thumbnails / previews** (will regenerate on next request):
  ```bash
  php bin/console liip:imagine:cache:remove --filter=games_pull_item_icon
  php bin/console liip:imagine:cache:remove --filter=games_pull_item_preview
  ```
