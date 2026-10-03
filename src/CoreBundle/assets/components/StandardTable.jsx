import React, { useState, useEffect, useMemo } from "react";
import {
    Table,
    Button,
    Popover,
    Checkbox,
    Flex,
    Tooltip,
    Space,
    Tag,
    Card,
    Typography,
    Grid,
} from "antd";
import {
    ReloadOutlined,
    SettingOutlined,
    FilterFilled,
    CloseCircleOutlined,
} from "@ant-design/icons";
import { formatDateFromIso, formatDateTimeFromIso } from "@CoreBundle/format-utils";
import MultiFilterDropdown from "./MultiFilterDropdown";
import { FILTER_OPERATORS } from "./filterCatalog";

const { Text } = Typography;
const { useBreakpoint } = Grid;

/**
 * Resolves a raw filter value into its human-readable display label.
 * Checks col.filters and col.tagCatalog / col.tagColorMap / col.valueEnum before falling back to formatted string.
 *
 * @param {any} val Raw filter scalar value (e.g. "plan_to_watch")
 * @param {Object} [col] Column configuration object
 * @returns {string} Human-friendly label (e.g. "Plan To Watch")
 */
function resolveHumanLabel(val, col) {
    if (!col) return String(val);

    // 1. Look up in col.tagCatalog or col.tagColorMap / col.valueEnum
    const catalog = col.tagCatalog || col.tagColorMap || col.valueEnum;
    if (catalog && catalog[val]) {
        const item = catalog[val];
        if (typeof item === "object" && (item.text || item.label)) {
            return String(item.text || item.label);
        }
    }

    // 2. Look up in col.filters (standard enum filter array: [{ text, value }])
    if (Array.isArray(col.filters)) {
        const found = col.filters.find((f) => String(f.value) === String(val));
        if (found) {
            const rawText = found.originalText ?? found.text;
            if (typeof rawText === "string" || typeof rawText === "number") {
                return String(rawText);
            }
            if (React.isValidElement(rawText)) {
                return rawText.props?.children || String(val);
            }
        }
    }

    // 3. Fallback: replace underscores with spaces and capitalize words
    if (typeof val === "string") {
        return val.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
    }

    return String(val);
}

/**
 * Returns a readable text summary of an active filter rule for badge/ribbon tags.
 *
 * @param {Object} rule Condition object containing { operator, value }
 * @param {Object} [col] The column configuration object
 * @returns {string} Human-friendly string description
 */
function getRuleDescription(rule, col) {
    if (rule.operator === "empty") return "is empty";
    if (rule.operator === "notEmpty") return "is not empty";

    // Handle 'in' operator used by standard AntD checkbox dropdowns (Enums & Tags)
    if (rule.operator === "in") {
        const rawItems = Array.isArray(rule.value) ? rule.value : [rule.value];
        const labels = rawItems.map((v) => resolveHumanLabel(v, col));

        if (labels.length <= 2) {
            return labels.join(", ");
        }
        return `${labels.slice(0, 2).join(", ")} +${labels.length - 2}`;
    }

    const catalog = FILTER_OPERATORS[col?.filterType] || [];
    const opItem = catalog.find((o) => o.value === rule.operator);
    const opLabel = opItem ? opItem.label : rule.operator;

    if (Array.isArray(rule.value)) {
        const formattedRange = rule.value.map((v) => resolveHumanLabel(v, col));
        return `${opLabel} [${formattedRange.join(" - ")}]`;
    }

    return `${opLabel} "${resolveHumanLabel(rule.value, col)}"`;
}

/**
 * Standard table component supporting controlled filtering, dynamic column visibility,
 * mobile responsive typography, unified filter icons, diary-styled count badges,
 * and customizable tag variants with icons in both cells and filter menus.
 */
export default function StandardTable({
                                          columns: initialColumns = [],
                                          fetchData,
                                          rowKey = "id",
                                          cardStyle = {},
                                          tableStyle = {},
                                          onRow,
                                          title,
                                          subtitle,
                                          extraToolbarActions = null,
                                      }) {
    const screens = useBreakpoint();
    const isMobile = !screens.sm;

    const [data, setData] = useState([]);
    const [total, setTotal] = useState(0); // Current filtered count
    const [unfilteredTotal, setUnfilteredTotal] = useState(0); // Global table total
    const [loading, setLoading] = useState(false);

    const [pagination, setPagination] = useState({ current: 1, pageSize: 10 });
    const [filters, setFilters] = useState({});

    // Initialize sorter state by extracting the defaultSortOrder declared on initialColumns
    const [sorter, setSorter] = useState(() => {
        const defaultCol = initialColumns.find((c) => Boolean(c.defaultSortOrder));
        if (defaultCol) {
            return {
                field: defaultCol.dataIndex,
                order: defaultCol.defaultSortOrder,
            };
        }
        return {};
    });

    // Column visibility map: { [dataIndex]: boolean }
    const [columnVisibility, setColumnVisibility] = useState(() => {
        const init = {};
        initialColumns.forEach((c) => {
            init[c.dataIndex] = !c.hidden;
        });
        return init;
    });

    // Fetches table rows and total count from backend endpoint
    const loadData = async (pag = pagination, filt = filters, sort = sorter) => {
        setLoading(true);
        try {
            const res = await fetchData({
                pagination: { current: pag.current, pageSize: pag.pageSize },
                filters: filt,
                sorter: sort?.field ? { field: sort.field, order: sort.order } : undefined,
            });
            setData(res.data.rows || []);
            setTotal(res.data.count ?? 0);
            // Fall back to count if backend does not yet return total_count
            setUnfilteredTotal(res.data.total_count ?? res.data.count ?? 0);
        } finally {
            setLoading(false);
        }
    };

    // Re-fetch data whenever pagination, sorting, or serialized filters change
    useEffect(() => {
        loadData(pagination, filters, sorter);
    }, [
        pagination.current,
        pagination.pageSize,
        JSON.stringify(filters),
        sorter?.field,
        sorter?.order,
    ]);

    /**
     * Handles table change events emitted by Ant Design (pagination, filters, sorter).
     * Normalizes both multi-condition arrays and standard enum checkbox selections.
     */
    const handleTableChange = (newPagination, newFilters, newSorter) => {
        const activeSorter = Array.isArray(newSorter) ? newSorter[0] : newSorter;
        const formattedFilters = {};

        Object.entries(newFilters || {}).forEach(([key, val]) => {
            if (!val || val.length === 0) return;

            // MultiFilterDropdown output: [[ { operator, value }, ... ]]
            if (Array.isArray(val[0])) {
                formattedFilters[key] = val[0];
            }
            // Standard Ant Design checkbox dropdown output (Enum filters): ['reading', 'completed']
            else {
                formattedFilters[key] = [{ operator: "in", value: val }];
            }
        });

        setPagination(newPagination);
        setFilters(formattedFilters);
        setSorter(activeSorter?.field ? { field: activeSorter.field, order: activeSorter.order } : {});
    };

    // Removes a single filter condition by field name and rule index
    const handleRemoveCondition = (field, ruleIndex) => {
        setFilters((prev) => {
            const currentRules = prev[field];
            if (!currentRules) return prev;

            const updatedRules = currentRules.filter((_, idx) => idx !== ruleIndex);
            const next = { ...prev };
            if (updatedRules.length === 0) {
                delete next[field];
            } else {
                next[field] = updatedRules;
            }
            return next;
        });
        setPagination((prev) => ({ ...prev, current: 1 }));
    };

    // Clears all active filters across every column
    const handleClearAllFilters = () => {
        setFilters({});
        setPagination((prev) => ({ ...prev, current: 1 }));
    };

    const hasActiveFilters = Object.keys(filters).length > 0;

    /**
     * Processes columns for Ant Design:
     * - Filters out unchecked columns and overrides native `hidden: false` to ensure re-enabled columns render.
     * - Injects controlled `filteredValue` so Ant Design table retains active filter state across pages.
     * - Injects controlled `sortOrder` so default and dynamic sorters light up table arrows properly.
     * - Prevents column header text letter-wrapping on mobile using `whiteSpace: "nowrap"`.
     * - Unifies filter icons across custom types and native enums with `<FilterFilled />`.
     * - Unifies tag formatting supporting Ant Design's native variant prop and explicit icon support.
     */
    const processedColumns = useMemo(() => {
        const ANTD_TAG_COLORS = [
            "blue", "purple", "cyan", "green", "magenta",
            "pink", "red", "orange", "yellow", "volcano", "geekblue", "gold"
        ];

        const resolveColorByHash = (str) => {
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                hash = str.charCodeAt(i) + ((hash << 5) - hash);
            }
            return ANTD_TAG_COLORS[Math.abs(hash) % ANTD_TAG_COLORS.length];
        };

        return initialColumns
            .filter((col) => columnVisibility[col.dataIndex] === true)
            .map((col) => {
                // Explicitly clear any initial `hidden: true` prop so Ant Design does not suppress rendering
                let processed = { ...col, hidden: false };
                const activeColFilters = filters[col.dataIndex];

                const catalog = col.tagCatalog || col.tagColorMap || col.valueEnum || {};
                const defaultVariant = col.variant || col.tagVariant;

                // Helper to resolve tag properties (text, color, icon, variant) for a given value
                const resolveTagProps = (valKey, customFallbackText = null) => {
                    const mapped = catalog[valKey];
                    let text = customFallbackText;
                    let color = null;
                    let icon = null;
                    let variant = defaultVariant;

                    if (typeof mapped === "object" && mapped !== null) {
                        text = mapped.text ?? mapped.label ?? text;
                        color = mapped.color ?? null;
                        icon = mapped.icon ?? null;
                        if (mapped.variant) variant = mapped.variant;
                    } else if (typeof mapped === "string") {
                        color = mapped;
                    }

                    // Look up human-readable label in col.filters if text was not resolved from catalog
                    if (!text && Array.isArray(col.filters)) {
                        const filterMatch = col.filters.find((f) => String(f.value) === String(valKey));
                        if (filterMatch) {
                            text = typeof filterMatch.text === "string" ? filterMatch.text : null;
                        }
                    }

                    if (!text) {
                        text = valKey;
                    }

                    if (!color) {
                        if (col.tagColor) {
                            color = col.tagColor;
                        } else if (col.tagRandomColor) {
                            color = resolveColorByHash(valKey);
                        } else {
                            color = "default";
                        }
                    }

                    return { text, color, icon, variant };
                };

                // Only generate tags in filter options when filterType is explicitly "tags"
                if (col.filterType === "tags") {
                    const rawList = col.filters || Object.entries(catalog).map(([key, item]) => ({
                        value: item?.value ?? key,
                        text: typeof item === "object" ? (item?.text ?? item?.label ?? key) : key,
                    }));

                    processed.filters = rawList.map((f) => {
                        const originalLabel = typeof f.text === "string" ? f.text : String(f.value);
                        const tagProps = resolveTagProps(f.value, originalLabel);
                        return {
                            ...f,
                            originalText: originalLabel,
                            text: (
                                <Tag
                                    color={tagProps.color}
                                    icon={tagProps.icon}
                                    variant={tagProps.variant}
                                    style={{ marginInlineEnd: 0 }}
                                >
                                    {tagProps.text}
                                </Tag>
                            ),
                        };
                    });
                }

                // Fully controlled sortOrder for Ant Design
                if (col.sorter) {
                    processed.sortOrder = sorter?.field === col.dataIndex ? sorter.order : null;
                }

                // Fully controlled filteredValue mapping for Ant Design
                if (["text", "number", "date"].includes(col.filterType)) {
                    processed.filteredValue = activeColFilters && activeColFilters.length > 0 ? [activeColFilters] : null;
                } else if (processed.filters) {
                    const inRule = activeColFilters?.find((r) => r.operator === "in");
                    processed.filteredValue = inRule && Array.isArray(inRule.value) ? inRule.value : null;
                }

                // Header title with active rules badge and nowrap constraint
                const originalTitle = col.title;
                processed.title = (
                    <Flex vertical gap={2} style={{ lineHeight: 1.2, whiteSpace: "nowrap" }}>
                        <span>{originalTitle}</span>
                        {activeColFilters && activeColFilters.length > 0 && (
                            <Tag
                                color="blue"
                                style={{
                                    fontSize: 10,
                                    marginRight: 0,
                                    padding: "0 4px",
                                    lineHeight: "16px",
                                    alignSelf: "flex-start",
                                    whiteSpace: "nowrap",
                                }}
                            >
                                {activeColFilters.length === 1
                                    ? getRuleDescription(activeColFilters[0], col)
                                    : `${activeColFilters.length} rules`}
                            </Tag>
                        )}
                    </Flex>
                );

                // Unified filter icon to match native Ant Design enum appearance
                processed.filterIcon = (filtered) => (
                    <FilterFilled style={{ color: filtered ? "#1677ff" : undefined }} />
                );

                // Bind custom multi-filter dropdown
                if (["text", "number", "date"].includes(col.filterType)) {
                    processed.filterDropdown = (props) => <MultiFilterDropdown col={col} {...props} />;
                }

                // Default value formatters
                if (!col.render) {
                    if (col.valueType === "datetime") {
                        processed.render = (val) => (val ? <span>{formatDateTimeFromIso(val)}</span> : "-");
                    } else if (col.valueType === "date") {
                        processed.render = (val) => (val ? <span>{formatDateFromIso(val)}</span> : "-");
                    } else if (col.valueType === "tags") {
                        processed.render = (rawVal) => {
                            if (rawVal === null || rawVal === undefined || rawVal === "") return "-";

                            // 1. Normalize input to an array of raw items
                            let items = rawVal;
                            if (typeof rawVal === "string") {
                                try {
                                    const parsed = JSON.parse(rawVal);
                                    items = Array.isArray(parsed) ? parsed : rawVal.split(",");
                                } catch {
                                    items = rawVal.split(",");
                                }
                            } else if (!Array.isArray(rawVal)) {
                                items = [rawVal];
                            }

                            if (!Array.isArray(items) || items.length === 0) return "-";

                            // 2. Resolve label and color for each item
                            const normalizedTags = items
                                .map((item) => {
                                    if (item === null || item === undefined || item === "") return null;

                                    // If item is already an object { text, color }
                                    if (typeof item === "object") {
                                        const text = item.text ?? item.label ?? JSON.stringify(item);
                                        const color = item.color || col.tagColor || (col.tagRandomColor ? resolveColorByHash(String(text)) : "default");
                                        const icon = item.icon || null;
                                        const variant = item.variant || defaultVariant;
                                        return { text, color, icon, variant };
                                    }

                                    const valKey = typeof item === "string" ? item.trim() : String(item);
                                    if (!valKey) return null;

                                    return resolveTagProps(valKey);
                                })
                                .filter(Boolean);

                            if (normalizedTags.length === 0) return "-";

                            return (
                                <Space size={[6, 6]} wrap>
                                    {normalizedTags.map((tag, idx) => (
                                        <Tag
                                            key={`${tag.text}_${idx}`}
                                            color={tag.color}
                                            icon={tag.icon}
                                            variant={tag.variant}
                                            style={{ marginInlineEnd: 0 }}
                                        >
                                            {tag.text}
                                        </Tag>
                                    ))}
                                </Space>
                            );
                        };
                    }
                }
                return processed;
            });
    }, [initialColumns, columnVisibility, filters, sorter]);

    // Checkbox items for the column visibility popover
    const columnSettingsContent = (
        <Flex vertical gap="small" style={{ maxHeight: 320, overflowY: "auto", minWidth: 170 }}>
            {initialColumns.map((col) => (
                <Checkbox
                    key={col.dataIndex}
                    checked={columnVisibility[col.dataIndex] === true}
                    onChange={(e) => {
                        setColumnVisibility((prev) => ({
                            ...prev,
                            [col.dataIndex]: e.target.checked,
                        }));
                    }}
                >
                    {col.title}
                </Checkbox>
            ))}
        </Flex>
    );

    // Compute filtering percentage
    const filterPercent = unfilteredTotal > 0 ? Math.round((total / unfilteredTotal) * 100) : 0;

    return (
        <Card
            styles={{
                header: {
                    height: "auto",
                    padding: isMobile ? "12px 14px" : "16px 20px",
                    whiteSpace: "normal",
                },
                body: {
                    padding: isMobile ? "12px 8px" : "16px 20px",
                },
            }}
            style={{ width: "100%", ...cardStyle }}
            title={
                <Flex
                    justify="space-between"
                    align={isMobile ? "stretch" : "center"}
                    vertical={isMobile}
                    gap={12}
                    style={{ width: "100%", whiteSpace: "normal" }}
                >
                    {/* Title, Subtitle, and Counts Badges */}
                    <div style={{ flex: 1, minWidth: 0 }}>
                        <Space size={8} wrap align="center">
                            {title && (
                                typeof title === "string" ? (
                                    <Text strong style={{ fontSize: isMobile ? 15 : 16 }}>
                                        {title}
                                    </Text>
                                ) : (
                                    title
                                )
                            )}

                            {/* Count badges */}
                            {hasActiveFilters ? (
                                <Tag color="cyan">
                                    Filtered: {total.toLocaleString()} / {unfilteredTotal.toLocaleString()} ({filterPercent}%)
                                </Tag>
                            ) : (
                                <Tag color="blue">
                                    Total: {unfilteredTotal.toLocaleString()}
                                </Tag>
                            )}
                        </Space>

                        {subtitle && (
                            <div style={{ marginTop: 2 }}>
                                <Text
                                    type="secondary"
                                    style={{
                                        fontSize: 12,
                                        whiteSpace: "normal",
                                        wordBreak: "break-word",
                                        display: "block",
                                        lineHeight: 1.4,
                                    }}
                                >
                                    {subtitle}
                                </Text>
                            </div>
                        )}
                    </div>

                    {/* Toolbar action buttons */}
                    <Space wrap size="small" style={{ alignSelf: isMobile ? "flex-end" : "center", flexShrink: 0 }}>
                        {extraToolbarActions}
                        <Tooltip title="Reload">
                            <Button icon={<ReloadOutlined />} onClick={() => loadData()} />
                        </Tooltip>
                        <Popover
                            content={columnSettingsContent}
                            title="Display Columns"
                            trigger="click"
                            placement="bottomRight"
                        >
                            <Tooltip title="Column Settings">
                                <Button icon={<SettingOutlined />} />
                            </Tooltip>
                        </Popover>
                    </Space>
                </Flex>
            }
        >
            {/* Active Filters Summary Ribbon */}
            {hasActiveFilters && (
                <div style={{ marginBottom: 14 }}>
                    <Flex gap="small" align="center" wrap="wrap">
                        <Text type="secondary" style={{ fontSize: 12 }}>
                            Active Filters:
                        </Text>
                        {Object.entries(filters).flatMap(([field, rules]) => {
                            const colDef = initialColumns.find((c) => c.dataIndex === field);
                            const colTitle = colDef ? colDef.title : field;

                            return rules.map((rule, idx) => (
                                <Tag
                                    key={`${field}_${idx}`}
                                    color="blue"
                                    closable
                                    onClose={() => handleRemoveCondition(field, idx)}
                                >
                                    <strong>{colTitle}</strong>: {getRuleDescription(rule, colDef)}
                                </Tag>
                            ));
                        })}
                        <Button
                            type="link"
                            size="small"
                            icon={<CloseCircleOutlined />}
                            onClick={handleClearAllFilters}
                            style={{ padding: 0 }}
                        >
                            Clear all
                        </Button>
                    </Flex>
                </div>
            )}

            {/* Main Table configured with middle density */}
            <Table
                size="middle"
                columns={processedColumns}
                rowKey={rowKey}
                dataSource={data}
                pagination={{
                    ...pagination,
                    total,
                    responsive: true,
                    simple: isMobile,
                    showSizeChanger: !isMobile,
                    pageSizeOptions: [10, 20, 50, 100],
                    showTotal: isMobile
                        ? undefined
                        : (totalCount, range) => `${range[0]}-${range[1]} of ${totalCount} items`,
                }}
                loading={loading}
                onChange={handleTableChange}
                scroll={{ x: "max-content" }}
                onRow={onRow}
                style={tableStyle}
            />
        </Card>
    );
}