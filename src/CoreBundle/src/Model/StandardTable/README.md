# Backend Standard Table Architecture & Developer Guide

> 💡 **Companion Guide:** For Ant Design 6.x UI components, column options (`valueType`, `filterType`), visual catalogs, and client-side filtering, see the **[Frontend Standard Table Guide](../../../assets/components/standard-table/README.md)**.

## 1. Overview & Architecture

The **Standard Table Backend Architecture** (`App\CoreBundle\Model\StandardTable`) is a robust, declarative query orchestration engine built for **Symfony 8.x** and **Doctrine ORM 3.x**. It is designed to consume parameters emitted by the Ant Design frontend table components (`RemoteTable`) and translate them into safe, high-performance DQL queries with zero boilerplate.

### Architecture Highlights

```
┌────────────────────────────────────────────────────────────────────────┐
│                   Frontend HTTP Client (RemoteTable)                   │
│   GET /api/{tracker}/season-folders/table?pagination[current]=1...     │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│               Symfony Controller (Automatic Deserialization)           │
│   #[MapQueryString] TableParameters $params                            │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                      TableConfiguration Value Object                   │
│   - rootEntityClass & rootAlias     - fieldMappings                    │
│   - joins (TableJoin instances)     - fetchJoins                       │
│   - hydrateObjects (Path A vs B)    - rowTransformer & queryModifier   │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│               Repository Layer (TableRepositoryTrait)                  │
│                                                                        │
│   ├── getTableUnfilteredCount()  ──► COUNT(DISTINCT PK) (Baseline)     │
│   ├── getTableCount()            ──► COUNT(DISTINCT PK) + Filters      │
│   └── getTableRows()             ──► Path A (Entities + Paginator)     │
│                                  ──► Path B (Flat Scalar Projection)   │
└────────────────────────────────────────────────────────────────────────┘
```

### Core Design Principles

1. **Security by Default**: All filters and sorters are strictly parameterized. Column names are sanitized against a whitelist (`fieldMappings`) or mapped safely via camelCase resolution.
2. **Elimination of N+1 Queries**: Supports both single-query scalar projections (`Path B`) and Doctrine 3.x `OffsetPaginator` with eager fetch-joins (`Path A`).
3. **Composite Primary Key Portability**: Distinct counts automatically detect single and composite entity identifiers, constructing portable `COUNT(DISTINCT CONCAT(...))` expressions when needed.
4. **Decoupled Business Logic**: Query scoping (e.g., multi-tenancy, tracker filters) is enforced server-side via `queryModifier`, completely isolated from frontend parameters.

---

## 2. Component Reference

### `TableParameters` (Request DTO)

`TableParameters` captures and validates pagination, sorting, and multi-condition filtering parameters sent by the frontend table. It uses Symfony Serializer attributes (`#[SerializedPath]`) to bind deep JSON-like query parameters automatically:

```php
namespace App\CoreBundle\Model\StandardTable;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Attribute\SerializedPath;
use Symfony\Component\Validator\Constraints as Assert;

class TableParameters
{
    #[Assert\Positive]
    #[SerializedPath('[pagination][pageSize]')]
    private int $pageSize = 10;

    #[Assert\Positive]
    #[SerializedPath('[pagination][current]')]
    private int $current = 1;

    #[Assert\PositiveOrZero]
    #[SerializedPath('[pagination][total]')]
    private int $total = 0;

    #[SerializedPath('[sorter][field]')]
    private ?string $sorterField = null;

    #[SerializedPath('[sorter][order]')]
    private ?string $sorterOrder = null;

    #[SerializedName('filters')]
    private array $filters = [];

    // Getters and fluent setters...
}
```

#### Supported Request Query Format

```http
GET /api/downloads/table?pagination[current]=2&pagination[pageSize]=25&sorter[field]=started&sorter[order]=descend&filters[state][0][operator]=in&filters[state][0][value][]=downloading&filters[state][0][value][]=error_downloading
```

---

### `TableConfiguration` (Metadata & Pipeline Config)

`TableConfiguration` is an immutable specification object that controls how DQL is built, how entities are joined, whether to hydrate objects or scalars, and how results are post-processed.

```php
$config = new TableConfiguration(
    rootEntityClass: SeasonFolder::class,
    rootAlias: 'e',
    fieldMappings: [
        'id'             => 'e.id',
        'tracker'        => 'e.tracker',
        'folder'         => 'e.folder',
        'episode_offset' => 'e.episodeOffset',
        'title'          => 'a.title',
    ],
    joins: [
        TableJoin::left(
            join: ListAnime::class,
            alias: 'a',
            condition: 'a.id = e.id AND a.tracker = e.tracker'
        ),
    ],
    fetchJoins: [],
    hydrateObjects: false,
    rowTransformer: null,
    queryModifier: fn(QueryBuilder $qb, string $alias) => $qb
        ->andWhere("$alias.tracker = :tracker")
        ->setParameter('tracker', $tracker),
);
```

#### Parameter Breakdown

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `rootEntityClass` | `string` | *(Required)* | The FQCN of the entity (e.g. `SeasonFolder::class`). Used to inspect entity metadata and primary keys. |
| `rootAlias` | `string` | `'e'` | The root alias used throughout the DQL query. |
| `fieldMappings` | `array<string, string>` | `[]` | Maps frontend `dataIndex` names to DQL properties (e.g. `'title' => 'a.title'`). |
| `joins` | `array<string\|TableJoin>` | `[]` | Array of `TableJoin` instances or simple `['alias' => 'relation']` strings. |
| `fetchJoins` | `string[]` | `[]` | List of joined aliases to include in the `SELECT` clause when `hydrateObjects` is `true` (e.g. `['attempts']`). |
| `hydrateObjects` | `bool` | `false` | When `false` (`Path B`), runs flat scalar projection. When `true` (`Path A`), hydrates full entity instances. |
| `rowTransformer` | `?callable` | `null` | Post-processing callback: `function(array $row, ?object $entity): array`. |
| `queryModifier` | `?callable` | `null` | Callback to apply fixed WHERE clauses or parameters: `function(QueryBuilder $qb, string $alias): void`. |

#### Automatic DQL Path Resolution (`resolveDqlPath`)

When a filter or sorter is applied to a field not explicitly declared in `fieldMappings`, `resolveDqlPath` automatically converts `snake_case` identifiers to `camelCase` on the root alias:

* `'folder'` $\rightarrow$ `'e.folder'`
* `'episode_offset'` $\rightarrow$ `'e.episodeOffset'`
* `'created_at'` $\rightarrow$ `'e.createdAt'`

---

### `TableJoin` (Type-Safe Relation Definition)

`TableJoin` encapsulates join types (`LEFT` / `INNER`), target entities/associations, custom join conditions (`WITH` / `ON`), and indexing clauses.

#### Class Specification

```php
namespace App\CoreBundle\Model\StandardTable;

use Doctrine\ORM\Query\Expr\Join;

class TableJoin
{
    public function __construct(
        private readonly string  $join,
        private readonly string  $alias,
        private readonly string  $type = Join::LEFT_JOIN,
        private readonly ?string $conditionType = null,
        private readonly ?string $condition = null,
        private readonly ?string $indexBy = null,
    ) {}

    public static function left(
        string  $join,
        string  $alias,
        ?string $condition = null,
        string  $conditionType = Join::WITH,
        ?string $indexBy = null
    ): self;

    public static function inner(
        string  $join,
        string  $alias,
        ?string $condition = null,
        string  $conditionType = Join::WITH,
        ?string $indexBy = null
    ): self;
}
```

#### Join Scenarios & Usage Examples

##### 1. Standard ORM Association Join
```php
// LEFT JOIN e.user u
TableJoin::left('e.user', 'u');
```

##### 2. Ad-Hoc / Virtual Entity Join with Custom Condition
Allows joining entities that do not share a formal database foreign key mapping:
```php
// LEFT JOIN App\AnimeBundle\Entity\ListAnime a WITH a.id = e.id AND a.tracker = e.tracker
TableJoin::left(
    join: ListAnime::class,
    alias: 'a',
    condition: 'a.id = e.id AND a.tracker = e.tracker',
    conditionType: Join::WITH
);
```

##### 3. Strict Inner Join with Indexing
```php
// INNER JOIN e.metadata m INDEX BY m.key
TableJoin::inner(
    join: 'e.metadata',
    alias: 'm',
    indexBy: 'm.key'
);
```

---

## 3. Query Execution Paths: Path A vs Path B

The repository trait provides two execution strategies for `getTableRows()`. Selecting the right path ensures the best balance between execution speed and entity lifecycle flexibility.

```
                         ┌─────────────────────────────┐
                         │   config->isHydrateObjects? │
                         └──────────────┬──────────────┘
                                        │
                    YES ┌───────────────┴───────────────┐ NO
                        ▼                               ▼
        ┌───────────────────────────────┐   ┌───────────────────────────────┐
        │     Path A: Hydrate Objects   │   │  Path B: Flat Scalar (Default)│
        ├───────────────────────────────┤   ├───────────────────────────────┤
        │ • Hydrates entity instances   │   │ • DQL: SELECT e.a AS a...     │
        │ • Fetches relations via join  │   │ • getArrayResult()            │
        │ • Uses OffsetPaginator        │   │ • No entity instantiation     │
        │ • Entity passed to transformer│   │ • Minimal memory footprint    │
        │ • Best for complex aggregates │   │ • Best for read-heavy tables  │
        └───────────────────────────────┘   └───────────────────────────────┘
```

### Path B: Flat Scalar Projection (Default, High Performance)

* **Configuration**: `hydrateObjects: false` (default).
* **Mechanism**: Compiles `fieldMappings` into `SELECT dqlPath AS dataIndex`. The query returns raw scalar values via `getArrayResult()`.
* **When to use**: 90% of tables (e.g. `ListAnime`, `ListManga`, `SeasonFolder`). Provides maximum speed, minimal memory usage, and single-query execution.

```php
$config = new TableConfiguration(
    rootEntityClass: SeasonFolder::class,
    fieldMappings: [
        'id'     => 'e.id',
        'folder' => 'e.folder',
        'title'  => 'a.title',
    ],
    joins: [
        TableJoin::left(ListAnime::class, 'a', 'a.id = e.id AND a.tracker = e.tracker'),
    ],
    hydrateObjects: false,
);
```

Generated DQL:
```sql
SELECT e.id AS id, e.folder AS folder, a.title AS title
FROM App\AnimeBundle\Entity\SeasonFolder e
LEFT JOIN App\AnimeBundle\Entity\ListAnime a WITH a.id = e.id AND a.tracker = e.tracker
WHERE e.tracker = :tracker
ORDER BY e.id DESC
```

---

### Path A: Full Entity Hydration with Doctrine 3.x Paginator

* **Configuration**: `hydrateObjects: true`.
* **Mechanism**: Selects the root entity and any aliases listed in `fetchJoins`. Uses Doctrine 3.x's `OffsetPaginator` with a `Window` object to correctly manage pagination across one-to-many joined collections without row duplication.
* **Property Extraction**: Properties are automatically read from the root entity using getters (`get*`, `is*`, `has*`) matching the `fieldMappings`.
* **When to use**: When row display requires calling entity helper methods, accessing sub-collections, or inspecting relations (e.g., download attempts, security voters).

```php
$config = new TableConfiguration(
    rootEntityClass: EpisodeDownload::class,
    fieldMappings: [
        'id'          => 'e.id',
        'episode_url' => 'e.episodeUrl',
        'state'       => 'e.state',
    ],
    joins: [
        TableJoin::left('e.episodeDownloadAttempts', 'a'),
    ],
    fetchJoins: ['a'],
    hydrateObjects: true,
    rowTransformer: function (array $row, EpisodeDownload $entity): array {
        $lastAttempt = $entity->getLastAttempt();
        $row['last_error'] = $lastAttempt?->getErrorMessage();
        $row['attempts_count'] = $entity->getEpisodeDownloadAttempts()->count();
        return $row;
    }
);
```

---

## 4. Multi-Condition Filter Operators Reference

`TableRepositoryTrait` translates filter rules sent by frontend columns into type-safe parameterized expressions. Each parameter key is salted with a unique random hex string (`paramKey . '_' . bin2hex(random_bytes(4))`) to prevent parameter collisions when multiple rules target the same column.

| Operator | Frontend Type | DQL / SQL Expression | Parameter Format & Notes |
| :--- | :--- | :--- | :--- |
| `like` | `text` | `LOWER(target) LIKE :p` | `%:value%` (lowercased via `mb_strtolower`) |
| `notLike` | `text` | `LOWER(target) NOT LIKE :p` | `%:value%` (lowercased via `mb_strtolower`) |
| `startsWith` | `text` | `LOWER(target) LIKE :p` | `:value%` |
| `endsWith` | `text` | `LOWER(target) LIKE :p` | `%:value` |
| `exact`, `eq` | `text`, `number` | `target = :p` | Exact raw scalar match |
| `neq` | `text`, `number` | `target != :p` | Not equal raw scalar match |
| `in` | `tags`, `choice` | `target IN (:p)` | Array of scalars (`['watching', 'completed']`) |
| `gt` | `number` | `target > :p` | Numeric comparison |
| `gte` | `number` | `target >= :p` | Numeric comparison |
| `lt` | `number` | `target < :p` | Numeric comparison |
| `lte` | `number` | `target <= :p` | Numeric comparison |
| `between` | `number` | `target BETWEEN :pMin AND :pMax` | Array of 2 elements: `[min, max]` |
| `dateExact` | `date` | `target >= :pStart AND target <= :pEnd` | Formats day boundaries: `'YYYY-MM-DD 00:00:00'` to `'23:59:59'` |
| `dateRange` | `date` | `target >= :pFrom AND target <= :pTo` | Array of 2 date strings: `[start, end]` |
| `fromDate` | `date` | `target >= :p` | Formats start of day: `'YYYY-MM-DD 00:00:00'` |
| `toDate` | `date` | `target <= :p` | Formats end of day: `'YYYY-MM-DD 23:59:59'` |
| `null`, `empty` | all | `target IS NULL OR target = ''` | No parameter required |
| `notNull`, `notEmpty` | all | `target IS NOT NULL AND target != ''` | No parameter required |

---

## 5. Composite Primary Key & Distinct Counting

A common issue in Doctrine table pagination is calculating accurate total counts when entities use composite primary keys or joins that duplicate rows.

`TableRepositoryTrait` solves this natively in `buildCountDistinctExpression`:

```php
protected function buildCountDistinctExpression(QueryBuilder $qb, TableConfiguration $config): string
{
    $alias = $config->getRootAlias();
    $expr = $qb->expr();

    $metadata = $qb->getEntityManager()->getClassMetadata($config->getRootEntityClass());
    $idFields = $metadata->getIdentifierFieldNames();

    // 1. Single Primary Key (e.g. 'id' or 'uuid')
    if (count($idFields) === 1) {
        return (string) $expr->countDistinct("$alias.{$idFields[0]}");
    }

    // 2. Composite Primary Key (e.g. ['id', 'tracker'])
    // DQL standard does not support COUNT(DISTINCT a, b).
    // Builds: COUNT(DISTINCT CONCAT(e.id, '_', e.tracker))
    $concatArgs = [];
    foreach ($idFields as $index => $field) {
        if ($index > 0) {
            $concatArgs[] = "'_'";
        }
        $concatArgs[] = "$alias.$field";
    }

    return (string) $expr->countDistinct($expr->concat(...$concatArgs));
}
```

### Benefits
* Works transparently on both standard entities (`Credential::$id`) and multi-tenant composite entities (`SeasonFolder::$id`, `SeasonFolder::$tracker`).
* Produces valid, portable DQL across SQLite, PostgreSQL, and MySQL.

---

## 6. Implementation Recipes

### Recipe 1: High-Performance Scalar Table with Virtual Join

Use when displaying records from a primary entity combined with data from an associated entity without N+1 queries.

```php
namespace App\AnimeBundle\Controller;

use App\AnimeBundle\Entity\SeasonFolder;
use App\AnimeBundle\Entity\ListAnime;
use App\AnimeBundle\Repository\SeasonFolderRepository;
use App\CoreBundle\Model\StandardTable\TableConfiguration;
use App\CoreBundle\Model\StandardTable\TableJoin;
use App\CoreBundle\Model\StandardTable\TableParameters;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

class SeasonFolderController extends AbstractController
{
    #[Route('/api/{tracker}/season-folders/table', name: 'api_season_folders_table', methods: ['GET'])]
    public function table(
        string                 $tracker,
        SeasonFolderRepository $repository,
        #[MapQueryString]
        TableParameters        $params
    ): Response {
        $config = new TableConfiguration(
            rootEntityClass: SeasonFolder::class,
            rootAlias: 'e',
            fieldMappings: [
                'id'             => 'e.id',
                'tracker'        => 'e.tracker',
                'folder'         => 'e.folder',
                'episode_offset' => 'e.episodeOffset',
                'title'          => 'a.title', // Sourced from joined ListAnime
            ],
            joins: [
                TableJoin::left(
                    join: ListAnime::class,
                    alias: 'a',
                    condition: 'a.id = e.id AND a.tracker = e.tracker'
                ),
            ],
            queryModifier: fn($qb, $alias) => $qb
                ->andWhere("$alias.tracker = :fixedTracker")
                ->setParameter('fixedTracker', $tracker),
        );

        return $this->json([
            'rows'        => $repository->getTableRows($params, $config),
            'count'       => $repository->getTableCount($params, $config),
            'total_count' => $repository->getTableUnfilteredCount($config),
        ]);
    }
}
```

---

### Recipe 2: Entity Hydration with Eager Fetch-Joins and Row Transformer

Use when rows require complex calculation from related entity collections.

```php
namespace App\AnimeBundle\Controller;

use App\AnimeBundle\Entity\EpisodeDownload;
use App\AnimeBundle\Repository\EpisodeDownloadRepository;
use App\CoreBundle\Model\StandardTable\TableConfiguration;
use App\CoreBundle\Model\StandardTable\TableJoin;
use App\CoreBundle\Model\StandardTable\TableParameters;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

class EpisodeDownloadController extends AbstractController
{
    #[Route('/api/downloads/table', name: 'api_downloads_table', methods: ['GET'])]
    public function table(
        EpisodeDownloadRepository $repository,
        #[MapQueryString]
        TableParameters           $params
    ): Response {
        $config = new TableConfiguration(
            rootEntityClass: EpisodeDownload::class,
            rootAlias: 'e',
            fieldMappings: [
                'id'          => 'e.id',
                'provider'    => 'e.provider',
                'tracker'     => 'e.tracker',
                'episode_url' => 'e.episodeUrl',
                'folder'      => 'e.folder',
                'episode'     => 'e.episode',
                'started'     => 'e.started',
                'completed'   => 'e.completed',
                'state'       => 'e.state',
            ],
            joins: [
                TableJoin::left('e.episodeDownloadAttempts', 'a'),
            ],
            fetchJoins: ['a'],
            hydrateObjects: true,
            rowTransformer: function (array $row, EpisodeDownload $entity): array {
                $lastAttempt = $entity->getLastAttempt();
                $row['last_error'] = $lastAttempt?->getErrorMessage();
                $row['attempts_count'] = $entity->getEpisodeDownloadAttempts()->count();
                return $row;
            }
        );

        return $this->json([
            'rows'        => $repository->getTableRows($params, $config),
            'count'       => $repository->getTableCount($params, $config),
            'total_count' => $repository->getTableUnfilteredCount($config),
        ]);
    }
}
```

---

### Recipe 3: Implementing `TableRepositoryInterface` in Repositories

Enabling standard table support in any Doctrine repository requires only implementing `TableRepositoryInterface` and using `TableRepositoryTrait`:

```php
namespace App\AnimeBundle\Repository;

use App\AnimeBundle\Entity\SeasonFolder;
use App\CoreBundle\Model\StandardTable\TableRepositoryInterface;
use App\CoreBundle\Model\StandardTable\TableRepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeasonFolder>
 */
class SeasonFolderRepository extends ServiceEntityRepository implements TableRepositoryInterface
{
    use TableRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeasonFolder::class);
    }
}
```

---

## 7. Performance & Best Practices Checklist

1. **Prefer Path B for Read Views**: Unless you must inspect entity relation graphs or invoke business methods on objects, leave `hydrateObjects: false`. Scalar projection reduces memory overhead by up to 80% on large page sizes.
2. **Always Pair Fetch Joins with `hydrateObjects: true`**: When using `fetchJoins`, always set `hydrateObjects: true` so the query routes through `OffsetPaginator(fetchJoinCollection: true)`. This prevents skewed pagination offsets caused by one-to-many cardinality.
3. **Use Scoped Parameters in `queryModifier`**: Never trust frontend filters for tenant boundaries or security constraints. Apply tenant, user, or tracker scopes inside `queryModifier`.
4. **Index Joined Columns**: Ensure composite join targets (e.g. `(id, tracker)`) have corresponding composite indexes declared in Doctrine entity mapping.
