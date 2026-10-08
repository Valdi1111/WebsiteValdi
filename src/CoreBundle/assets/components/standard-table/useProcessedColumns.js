import React, { useMemo } from "react";
import { Flex, Tag, Space } from "antd";
import { FilterFilled } from "@ant-design/icons";
import { formatDateFromIso, formatDateTimeFromIso } from "@CoreBundle/format-utils";
import MultiFilterDropdown from "@CoreBundle/components/standard-table/MultiFilterDropdown";
import { getRuleDescription } from "@CoreBundle/components/standard-table/tableFilterUtils";

const ANTD_TAG_COLORS = [
    "blue", "purple", "cyan", "green", "magenta",
    "pink", "red", "orange", "yellow", "volcano", "geekblue", "gold"
];

/**
 * Resolves a stable color name by hashing the given string value.
 *
 * @param {string} str Target string
 * @returns {string} Ant Design preset color name
 */
function resolveColorByHash(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = str.charCodeAt(i) + ((hash << 5) - hash);
    }
    return ANTD_TAG_COLORS[Math.abs(hash) % ANTD_TAG_COLORS.length];
}

/**
 * Custom hook to prepare columns configuration for Ant Design Table.
 *
 * Handles:
 * - Column visibility filtering and rendering overrides.
 * - Synchronizing controlled sortOrder and filteredValue.
 * - Mobile filter drawer delegation via filterIcon click.
 * - Tag rendering and color mapping for cell contents and filter menus.
 *
 * @param {Object} params
 * @param {Array<Object>} params.initialColumns Raw column definitions
 * @param {Object} params.columnVisibility Visibility state map
 * @param {Object} params.filters Active filters state map
 * @param {Object} params.sorter Active sorter state
 * @param {boolean} params.isMobile Whether current screen is mobile viewport
 * @param {Function} params.onOpenMobileFilter Callback to trigger the mobile filter drawer
 * @returns {Array<Object>} Decorated columns ready for Ant Design Table
 */
export default function useProcessedColumns({
                                                initialColumns,
                                                columnVisibility,
                                                filters,
                                                sorter,
                                                isMobile,
                                                onOpenMobileFilter,
                                            }) {
    return useMemo(() => {
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

                const hasFiltersConfigured = Boolean(
                    ["text", "number", "date"].includes(col.filterType) || processed.filters
                );

                // Unified filter icon to match native Ant Design enum appearance
                processed.filterIcon = (filtered) => (
                    <FilterFilled
                        style={{ color: filtered ? "#1677ff" : undefined }}
                        onClick={(e) => {
                            if (isMobile && hasFiltersConfigured) {
                                e.stopPropagation();
                                onOpenMobileFilter(col);
                            }
                        }}
                    />
                );

                // Configure filter dropdown based on desktop vs mobile screen
                if (isMobile) {
                    // On mobile, suppress the native popover and open external drawer instead
                    if (hasFiltersConfigured) {
                        processed.filterDropdown = () => <div style={{ display: "none" }} />;
                    }
                } else {
                    // Bind custom multi-filter dropdown for desktop
                    if (["text", "number", "date"].includes(col.filterType)) {
                        processed.filterDropdown = (props) => <MultiFilterDropdown col={col} {...props} />;
                    }
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
    }, [initialColumns, columnVisibility, filters, sorter, isMobile, onOpenMobileFilter]);
}
