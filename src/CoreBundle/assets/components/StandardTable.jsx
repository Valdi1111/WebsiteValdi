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
 * Returns a readable text summary of an active filter rule for badge/ribbon tags.
 *
 * @param {Object} rule Condition object containing { operator, value }
 * @param {string} colType The column's filterType ('text', 'number', 'date')
 * @returns {string} Human-friendly string description
 */
function getRuleDescription(rule, colType) {
    if (rule.operator === "empty") return "is empty";
    if (rule.operator === "notEmpty") return "is not empty";

    const catalog = FILTER_OPERATORS[colType] || [];
    const opItem = catalog.find((o) => o.value === rule.operator);
    const opLabel = opItem ? opItem.label : rule.operator;

    if (Array.isArray(rule.value)) {
        return `${opLabel} [${rule.value.join(" - ")}]`;
    }
    return `${opLabel} "${rule.value}"`;
}

/**
 * Standard table component supporting controlled filtering, dynamic column visibility,
 * mobile responsive typography, and unified filter icons.
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
    const [total, setTotal] = useState(0);
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
            setTotal(res.data.count || 0);
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
     */
    const processedColumns = useMemo(() => {
        return initialColumns
            .filter((col) => columnVisibility[col.dataIndex] === true)
            .map((col) => {
                // Explicitly clear any initial `hidden: true` prop so Ant Design does not suppress rendering
                let processed = { ...col, hidden: false };
                const activeColFilters = filters[col.dataIndex];

                // Fully controlled sortOrder for Ant Design
                if (col.sorter) {
                    processed.sortOrder = sorter?.field === col.dataIndex ? sorter.order : null;
                }

                // Fully controlled filteredValue mapping for Ant Design
                if (["text", "number", "date"].includes(col.filterType)) {
                    processed.filteredValue = activeColFilters && activeColFilters.length > 0 ? [activeColFilters] : null;
                } else if (col.filters) {
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
                                    ? getRuleDescription(activeColFilters[0], col.filterType)
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

                            // Ant Design preset color list for hash generation
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

                            // 2. Resolve label and color for each item
                            const colorMap = col.tagColorMap || col.valueEnum || {};

                            const normalizedTags = items
                                .map((item) => {
                                    if (item === null || item === undefined || item === "") return null;

                                    // If item is already an object { text, color }
                                    if (typeof item === "object") {
                                        return {
                                            text: item.text ?? item.label ?? JSON.stringify(item),
                                            color: item.color || col.tagColor || (col.randomColor ? resolveColorByHash(String(item.text)) : "default"),
                                        };
                                    }

                                    const valKey = typeof item === "string" ? item.trim() : String(item);
                                    if (!valKey) return null;

                                    // Look up in colorMap / valueEnum
                                    const mapped = colorMap[valKey];
                                    let text = valKey;
                                    let color = null;

                                    if (typeof mapped === "object" && mapped !== null) {
                                        text = mapped.text ?? mapped.label ?? valKey;
                                        color = mapped.color ?? null;
                                    } else if (typeof mapped === "string") {
                                        color = mapped;
                                    }

                                    // Check col.filters for human-readable labels if no text found in colorMap
                                    if (text === valKey && Array.isArray(col.filters)) {
                                        const filterMatch = col.filters.find((f) => String(f.value) === valKey);
                                        if (filterMatch) text = filterMatch.text;
                                    }

                                    // Color resolution order:
                                    // 1. colorMap / valueEnum
                                    // 2. col.tagColor (static color for all tags in this column)
                                    // 3. col.randomColor (deterministic hash)
                                    // 4. "default"
                                    if (!color) {
                                        if (col.tagColor) {
                                            color = col.tagColor;
                                        } else if (col.randomColor) {
                                            color = resolveColorByHash(valKey);
                                        } else {
                                            color = "default";
                                        }
                                    }

                                    return { text, color };
                                })
                                .filter(Boolean);

                            if (normalizedTags.length === 0) return "-";

                            return (
                                <Space size={[6, 6]} wrap>
                                    {normalizedTags.map((tag, idx) => (
                                        <Tag key={`${tag.text}_${idx}`} color={tag.color} style={{ marginInlineEnd: 0 }}>
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

    return (
        <Card
            styles={{
                header: {
                    height: "auto",
                    padding: isMobile ? "12px 14px" : "16px 20px",
                    whiteSpace: "normal", // Overrides AntD Card native nowrap to allow subtitle text-wrapping
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
                    {/* Header title & subtitle container; minWidth: 0 permits flex items to break lines properly */}
                    <div style={{ flex: 1, minWidth: 0 }}>
                        {title && (
                            typeof title === "string" ? (
                                <Text strong style={{ fontSize: isMobile ? 15 : 16, display: "block" }}>
                                    {title}
                                </Text>
                            ) : (
                                title
                            )
                        )}
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
                                    <strong>{colTitle}</strong>: {getRuleDescription(rule, colDef?.filterType)}
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