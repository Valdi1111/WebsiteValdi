# Standard Table Component System & Developer Guide (Ant Design 6.x)

> 💡 **Companion Guide:** For backend API specifications, DQL mapping, Doctrine 3.x pagination, and server-side filter evaluation, see the **[Backend Standard Table Guide](../../../src/Model/StandardTable/README.md)**.

## 1. Overview & Architecture

The **Standard Table System** is a unified, modular table suite built on top of [Ant Design 6.x](https://ant.design/components/table). It decouples presentational rendering from data management, providing consistent UI behavior, advanced multi-condition filtering, column visibility toggles, text search highlighting, and responsive mobile adaptations.

### Component Hierarchy

```
┌────────────────────────────────────────────────────────┐
│               Data Orchestration Layer                 │
│         [RemoteTable]     or     [LocalTable]          │
└───────────────────────────┬────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────┐
│                   BaseTable (Card Shell)               │
│  ┌──────────────────────────────────────────────────┐  │
│  │ TableToolbar (Title, Badges, Reload, Settings)   │  │
│  ├──────────────────────────────────────────────────┤  │
│  │ TableFilterRibbon (Active rule chips & edit)     │  │
│  ├──────────────────────────────────────────────────┤  │
│  │ Ant Design <Table> (Decorated via Hook)          │  │
│  ├──────────────────────────────────────────────────┤  │
│  │ TableMobileFilterDrawer (Responsive bottom sheet)│  │
│  └──────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────┘
```

### Ant Design 6.x Modernization Highlights

* **React 18 & 19 Native**: Full compatibility with concurrent rendering, strict mode lifecycles, and no legacy `findDOMNode` dependencies.
* **Pure CSS Variables (`cssVar`) Engine**: Ant Design 6.x styles components using CSS variables by default. Dynamic tokens queried via `theme.useToken()` in `MultiFilterDropdown` and `useProcessedColumns` seamlessly react to dark/light theme shifts without costly CSS-in-JS re-evaluations.
* **Semantic Structure Configuration**: Deep DOM customization is managed cleanly via the `styles` and `classNames` props on `Card`, `Table`, `Modal`, and `Drawer`.
* **Deprecation-Free Dropdown Controls**: Filter dropdown open states are controlled via `filterDropdownProps: { open, onOpenChange }` rather than deprecated top-level flags.

### Core Responsibilities

| Component / Hook | Role & Responsibility |
| --- | --- |
| **`BaseTable`** | Pure presentational container. Renders the surrounding Ant Design `Card`, headers, filter summary ribbon, responsive mobile drawer, and underlying `Table`. It is completely agnostic of how data is queried. |
| **`RemoteTable`** | State orchestrator for server-side endpoints. Manages server pagination `(current, pageSize)`, serializes multi-condition filter structures for REST/DQL APIs, and handles sorting. |
| **`LocalTable`** | State orchestrator for in-memory datasets. Applies client-side filtering via `localTableUtils`, client-side sorting, and client-side pagination slicing without API calls. |
| **`useProcessedColumns`** | Column decoration engine. Injects controlled sorting/filtering state, binds custom dropdowns (`MultiFilterDropdown`), handles automatic date/tag formatting, text search highlighting, and tooltip wrappers. |
| **`MultiFilterDropdown`** | Advanced desktop filter popover. Allows chaining multiple conditions per column with customizable operators (`like`, `eq`, `between`, `dateRange`, etc.), input types, and keyboard navigation. |
| **`TableFilterRibbon`** | Summary ribbon rendered above the table rows. Displays active filter rules as interactive Ant Design tags with click-to-focus and close-to-remove actions. |
| **`TableToolbar`** | Top toolbar featuring table titles, subtitles, filtered/unfiltered record counters, custom action buttons, reload trigger, and column visibility checkboxes. |
| **`TableMobileFilterDrawer`** | Responsive bottom sheet drawer replacing popover dropdowns on screens narrower than `sm` (`< 576px`). |

---

## 2. Column Configuration Reference

The column definition object extends the standard Ant Design `TableColumnType` with declarative attributes for filtering, value formatting, tag styling, and tooltips.

### Core Properties

| Property | Type | Default | Description |
| --- | --- | --- | --- |
| `dataIndex` | `string` | *(Required)* | Record key path representing the field value. |
| `title` | `ReactNode` | *(Required)* | Header label. Automatically enhanced with an active rules count badge when filtered. |
| `key` | `string` | `col.dataIndex` | Unique React key identifier. |
| `hidden` | `boolean` | `false` | When `true`, column is hidden by default but can be toggled on via the Column Settings popover. |
| `sorter` | `boolean \| Function` | `false` | Enables sorting. In `RemoteTable`, set to `true` to delegate to backend. In `LocalTable`, set to `true` for standard comparison or pass a custom comparator `(a, b) => number`. |
| `defaultSortOrder` | `'ascend' \| 'descend'` | `undefined` | Initial sort direction on mount. |
| `align` | `'left' \| 'center' \| 'right'` | `'left'` | Column text alignment. |
| `width` | `number \| string` | `undefined` | Explicit column width (e.g. `120`, `"15%"`). |
| `fixed` | `'left' \| 'right' \| boolean` | `false` | Fixes the column horizontally during table scrolling. |
| `ellipsis` | `boolean` | `false` | Truncates overflowing cell content with an ellipsis. |

### Value Formatting (`valueType`)

The `valueType` prop automatically injects preconfigured cell formatters if no custom `render` function is provided.

| `valueType` | Input Types | Formatting Behavior |
| --- | --- | --- |
| `'datetime'` | ISO 8601 string, Unix timestamp | Formatted using `formatDateTimeFromIso(val)` (e.g., `2026-10-10 21:46:23`). Renders `"-"` if empty. |
| `'date'` | ISO 8601 string, Unix timestamp | Formatted using `formatDateFromIso(val)` (e.g., `2026-10-10`). Renders `"-"` if empty. |
| `'tags'` | String, array of strings, JSON array | Renders values as Ant Design `<Tag>` components. Normalizes inputs, maps labels and colors against `tagCatalog` / `tagColorMap`, and renders inside an auto-wrapping `<Space>`. |
| `undefined` | Primitive scalar | Renders the raw value directly, with automatic search highlighting if text search is active. |

---

### Filtering System (`filterType` & `filterOperators`)

The system supports two distinct filtering paradigms based on `filterType`:

#### 1. Advanced Multi-Condition Filtering (`text`, `number`, `date`)

Renders `MultiFilterDropdown` on desktop and the mobile drawer on small screens. Supports adding multiple conditions per column.

```javascript
{
    title: "Episode Count",
    dataIndex: "num_episodes",
    filterType: "number",
    filterOperators: ["eq", "gt", "lt", "between"], // Optional whitelist
}
```

##### Supported Filter Types and Operators:

| `filterType` | Available Operators | Input UI |
| --- | --- | --- |
| `'text'` | `like`, `notLike`, `startsWith`, `endsWith`, `exact`, `neq`, `empty`, `notEmpty` | Ant Design `Input` |
| `'number'` | `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `between` | Ant Design `InputNumber` (dual inputs for `between`) |
| `'date'` | `dateRange`, `dateExact`, `fromDate`, `toDate` | Ant Design `DatePicker` / `RangePicker` |

> **Operator Whitelist (`filterOperators`)**: By passing an array of operator keys (e.g. `["eq", "gt", "lt"]`), you can restrict the options available in the operator dropdown.

#### 2. Enum & Tag Checkbox Filtering (`tags` or omitted)

When `filterType: "tags"` or `filters` are supplied without an advanced `filterType`, the column renders standard Ant Design checkbox list filtering.

```javascript
{
    title: "Status",
    dataIndex: "status",
    valueType: "tags",
    filterType: "tags",
    tagCatalog: STATUS_CATALOG,
}
```

When `tagCatalog` is provided alongside `filterType: "tags"`, the column's filter menu is **automatically populated** with labeled and color-coded tags without needing to manually define `col.filters`.

---

### Tag Catalog & Color Customization

When using `valueType: "tags"` or `filterType: "tags"`, configure visual catalogs via `tagCatalog` (aliases: `tagColorMap`, `valueEnum`).

#### Catalog Object Structure:

```javascript
const STATUS_CATALOG = {
    watching: {
        text: "Watching",              // Human-readable label
        color: "processing",           // AntD preset or hex color (#1677ff, green, cyan)
        icon: <SyncOutlined spin />,   // Optional AntD icon component
        variant: "filled",             // Optional AntD 6 variant: 'outlined' | 'filled'
    },
    completed: {
        text: "Completed",
        color: "success",
        icon: <CheckCircleOutlined />,
    },
    dropped: {
        text: "Dropped",
        color: "error",
    },
};
```

#### Additional Tag Styling Properties:

| Property | Type | Description |
| --- | --- | --- |
| `tagColor` | `string` | Applies a uniform Ant Design preset or hex color to all tags in the column (e.g. `tagColor: "cyan"`). |
| `tagRandomColor` | `boolean` | When `true`, automatically assigns a deterministic color hashed from the tag's string value. |
| `variant` / `tagVariant` | `'outlined' \| 'filled'` | Global tag variant applied to tags in this column. |

---

### Tooltip Integration (`tooltipDataIndex` & `tooltip`)

Wrap any cell's output (whether generated by `valueType: "tags"`, a date formatter, or a custom `render` function) inside an Ant Design `<Tooltip>` without writing repetitive boilerplate:

| Property | Type | Description |
| --- | --- | --- |
| `tooltipDataIndex` | `string` | Extracts a field from the row `record`. If truthy, wraps the cell output in a `<Tooltip title={record[col.tooltipDataIndex]}>`. |
| `tooltip` | `(text, record) => ReactNode` | Dynamic callback function. Returns tooltip content or `null`/`undefined` to omit. |

#### Example: Error Tooltip on Status Tag

```javascript
{
    title: "State",
    dataIndex: "state",
    valueType: "tags",
    filterType: "tags",
    tagCatalog: STATUS_CATALOG,
    tooltipDataIndex: "last_error", // If record.last_error exists, hovers will show the error message
}
```

---

### Automatic Search Text Highlighting

When a user filters a column with `filterType: "text"` using partial matching operators (`like`, `startsWith`, `endsWith`, `exact`, `notLike`), `useProcessedColumns` automatically wraps cell matches in a `<Highlighter>` tag using Ant Design 6.x token background highlights (`token.controlItemBgActiveHover`).

* Works on default scalar text rendering.
* Works on **custom `render` functions** returning strings or numbers without extra configuration.

---

## 3. RemoteTable Specification & Example

`RemoteTable` handles remote pagination, server-side sorting, and multi-condition filter queries.

### Component Props

| Prop | Type | Default | Description |
| --- | --- | --- | --- |
| `fetchData` | `(params) => Promise<AxiosResponse>` | *(Required)* | Async function receiving `{ pagination, filters, sorter }` and returning `{ data: { rows, count, total_count } }`. |
| `columns` | `Array<ColumnConfig>` | `[]` | Declarative column configuration array. |
| `title` | `ReactNode` | `undefined` | Header title string or component. |
| `subtitle` | `ReactNode` | `undefined` | Header description. |
| `extraToolbarActions` | `ReactNode` | `null` | Elements appended to the header toolbar (buttons, selectors). |
| `rowKey` | `string \| Function` | `"id"` | Primary key identifier for rows. |
| `fillHeight` | `boolean` | `false` | When `true`, card and table expand to 100% height of parent container with scrollable body. |
| `compactHeaderOnMobile` | `boolean` | `false` | Flattens the header on mobile viewports for dense layouts. |
| `size` | `'small' \| 'middle' \| 'large'` | `'middle'` | Ant Design table size. |
| `onRow` | `(record) => Object` | `undefined` | Ant Design `onRow` callback (e.g. click listeners). |

### Complete `RemoteTable` Implementation

```jsx
import React, { useState } from "react";
import { Button, message } from "antd";
import { SyncOutlined, CheckCircleOutlined, CloseCircleOutlined, CloudSyncOutlined } from "@ant-design/icons";
import RemoteTable from "@CoreBundle/components/standard-table/RemoteTable";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { useTracker } from "@AnimeBundle/components/TrackerContext";
import TrackerSelector from "@AnimeBundle/components/TrackerSelector";

const STATUS_CATALOG = {
    watching: { text: "Watching", color: "processing", icon: <SyncOutlined spin /> },
    completed: { text: "Completed", color: "success", icon: <CheckCircleOutlined /> },
    dropped: { text: "Dropped", color: "error", icon: <CloseCircleOutlined /> },
};

const MEDIA_TYPES = {
    tv: { text: "TV" },
    movie: { text: "Movie" },
    ova: { text: "OVA" },
};

export default function AnimeRemoteLibrary() {
    const { tracker } = useTracker();
    const api = useBackendApi();
    const [selectedAnime, setSelectedAnime] = useState(null);

    const columns = [
        {
            title: "ID",
            dataIndex: "id",
            width: 90,
            fixed: "left",
            sorter: true,
            defaultSortOrder: "descend",
            filterType: "number",
            filterOperators: ["eq", "gt", "lt"],
        },
        {
            title: "Title",
            dataIndex: "title",
            sorter: true,
            filterType: "text",
            filterOperators: ["like", "startsWith", "exact"],
        },
        {
            title: "English Title",
            dataIndex: "title_en",
            filterType: "text",
            hidden: true, // Hidden by default, togglable from Column Settings
        },
        {
            title: "Episodes",
            dataIndex: "num_episodes",
            width: 110,
            sorter: true,
            filterType: "number",
        },
        {
            title: "Type",
            dataIndex: "media_type",
            width: 100,
            valueType: "tags",
            filterType: "tags",
            tagColor: "cyan",
            tagCatalog: MEDIA_TYPES,
        },
        {
            title: "Status",
            dataIndex: "status",
            width: 140,
            valueType: "tags",
            filterType: "tags",
            tagCatalog: STATUS_CATALOG,
            tooltipDataIndex: "status_notes", // Displays tooltip if status_notes exists
        },
        {
            title: "Synced At",
            dataIndex: "synced_at",
            width: 170,
            valueType: "datetime",
            sorter: true,
            filterType: "date",
        },
    ];

    const handleSync = async () => {
        await api.withErrorHandling().listAnime().refresh(tracker);
        message.success("Synchronized successfully");
    };

    return (
        <RemoteTable
            key={tracker} // Reset state and reload whenever tracker changes
            title="Anime Library"
            subtitle="Browse and monitor synced series with real-time server pagination"
            columns={columns}
            rowKey="id"
            fillHeight={true}
            fetchData={(params) => api.withErrorHandling().listAnime().table(tracker, params)}
            extraToolbarActions={
                <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                    <TrackerSelector />
                    <Button type="primary" icon={<CloudSyncOutlined />} onClick={handleSync}>
                        Sync Library
                    </Button>
                </div>
            }
            onRow={(record) => ({
                onClick: () => setSelectedAnime(record),
                style: { cursor: "pointer" },
            })}
        />
    );
}
```

---

## 4. LocalTable Specification & Example

`LocalTable` operates on client-side datasets loaded into memory. It handles filtering, multi-condition rule evaluations, sorting, and pagination locally.

### Component Props

| Prop | Type | Default | Description |
| --- | --- | --- | --- |
| `dataSource` | `Array<Object>` | `[]` | *(Required)* Full dataset array to filter, sort, and paginate in-memory. |
| `columns` | `Array<ColumnConfig>` | `[]` | Declarative column configuration array. |
| `pagination` | `Object \| false` | `{ current: 1, pageSize: 10 }` | Local pagination configuration, or `false` to disable. |
| `loading` | `boolean` | `false` | Shows loading spinner over table. |
| `title` | `ReactNode` | `undefined` | Header title string or component. |
| `subtitle` | `ReactNode` | `undefined` | Header subtitle description. |
| `onReload` | `Function` | `undefined` | Callback invoked when clicking the toolbar reload button. |

### Complete `LocalTable` Implementation

```jsx
import React, { useState, useEffect } from "react";
import { Tag } from "antd";
import LocalTable from "@CoreBundle/components/standard-table/LocalTable";

const PRIORITY_CATALOG = {
    high: { text: "High Priority", color: "red" },
    medium: { text: "Medium", color: "orange" },
    low: { text: "Low", color: "blue" },
};

export default function TaskLocalManager() {
    const [tasks, setTasks] = useState([]);
    const [loading, setLoading] = useState(false);

    const loadLocalTasks = () => {
        setLoading(true);
        setTimeout(() => {
            setTasks([
                { id: 1, task: "Configure Webpack", priority: "high", effort_hours: 4.5, deadline: "2026-10-15T10:00:00Z", completed: false },
                { id: 2, task: "Migrate Doctrine Paginator", priority: "medium", effort_hours: 2.0, deadline: "2026-10-12T18:00:00Z", completed: true },
                { id: 3, task: "Design Tracker UI", priority: "low", effort_hours: 6.0, deadline: "2026-10-20T12:00:00Z", completed: false },
                { id: 4, task: "Write Documentation", priority: "medium", effort_hours: 3.5, deadline: "2026-10-11T09:30:00Z", completed: true },
            ]);
            setLoading(false);
        }, 300);
    };

    useEffect(() => {
        loadLocalTasks();
    }, []);

    const columns = [
        {
            title: "Task ID",
            dataIndex: "id",
            width: 90,
            sorter: (a, b) => a.id - b.id,
            filterType: "number",
        },
        {
            title: "Task Description",
            dataIndex: "task",
            sorter: true,
            filterType: "text",
        },
        {
            title: "Priority",
            dataIndex: "priority",
            valueType: "tags",
            filterType: "tags",
            tagCatalog: PRIORITY_CATALOG,
        },
        {
            title: "Estimated Hours",
            dataIndex: "effort_hours",
            sorter: (a, b) => a.effort_hours - b.effort_hours,
            filterType: "number",
            filterOperators: ["eq", "gt", "lt", "between"],
        },
        {
            title: "Deadline",
            dataIndex: "deadline",
            valueType: "datetime",
            sorter: (a, b) => new Date(a.deadline) - new Date(b.deadline),
            filterType: "date",
        },
        {
            title: "State",
            dataIndex: "completed",
            render: (done) => (
                <Tag color={done ? "green" : "volcano"}>
                    {done ? "Resolved" : "Pending"}
                </Tag>
            ),
        },
    ];

    return (
        <LocalTable
            title="Local Task Registry"
            subtitle="Client-side filtered and sorted task backlog"
            columns={columns}
            dataSource={tasks}
            loading={loading}
            onReload={loadLocalTasks}
            pagination={{ pageSize: 5 }}
            fillHeight={false}
        />
    );
}
```

---

## 5. Column Parameters & Combinations Matrix

Below is a cheat sheet showing valid property combinations according to column intent:

| Use Case | Recommended Column Definition |
| --- | --- |
| **Numeric Primary Key** | `{ title: "ID", dataIndex: "id", width: 80, sorter: true, filterType: "number", filterOperators: ["eq", "gt", "lt"] }` |
| **Searchable Text Field** | `{ title: "Name", dataIndex: "name", sorter: true, filterType: "text", ellipsis: true }` |
| **Status Tag with Error Tooltip** | `{ title: "Status", dataIndex: "state", valueType: "tags", filterType: "tags", tagCatalog: STATUS_CATALOG, tooltipDataIndex: "error_log" }` |
| **Dynamic Tooltip Callback** | `{ title: "Code", dataIndex: "code", tooltip: (val, record) => \`Assigned to \${record.user}\` }` |
| **Random Color Hash Tags** | `{ title: "Tags", dataIndex: "tags", valueType: "tags", tagRandomColor: true }` |
| **Date Field with DateRange** | `{ title: "Created At", dataIndex: "created", valueType: "date", sorter: true, filterType: "date", filterOperators: ["dateRange", "dateExact"] }` |
| **Hidden Optional Column** | `{ title: "Internal Hash", dataIndex: "hash", hidden: true, filterType: "text" }` |
| **Fixed Action Column** | `{ title: "Action", dataIndex: "actions", fixed: "right", width: 100, render: (_, record) => <Button ... /> }` |

---

## 6. API Protocol & Parameter Serialization

When `RemoteTable` requests data, it passes a structured query parameter object to `fetchData`:

```json
{
  "pagination": {
    "current": 1,
    "pageSize": 20
  },
  "sorter": {
    "field": "title",
    "order": "ascend"
  },
  "filters": {
    "title": [
      { "operator": "like", "value": "Attack" }
    ],
    "num_episodes": [
      { "operator": "between", "value": [12, 26] }
    ],
    "status": [
      { "operator": "in", "value": ["watching", "completed"] }
    ]
  }
}
```

### Backend DQL / Repository Mapping

The backend `TableParameters` DTO decodes this payload directly. In Symfony:

* **`pagination`** determines `$qb->setFirstResult()` and `$qb->setMaxResults()`, or passes `new Window($offset, $limit)` to Doctrine 3.x `OffsetPaginator`.
* **`sorter`** maps `sorter.field` to configured `fieldMappings` in `TableConfiguration`.
* **`filters`** iterate over each rule array, transforming operators (`like`, `between`, `in`, `eq`) into safe parameterized DQL expressions (`$qb->andWhere(...)`).
