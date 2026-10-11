import React, { useState } from "react";
import { Table, Card, Grid } from "antd";
import TableToolbar from "@CoreBundle/components/standard-table/TableToolbar";
import TableFilterRibbon from "@CoreBundle/components/standard-table/TableFilterRibbon";
import TableMobileFilterDrawer from "@CoreBundle/components/standard-table/TableMobileFilterDrawer";
import useProcessedColumns from "@CoreBundle/components/standard-table/useProcessedColumns";

const { useBreakpoint } = Grid;

/**
 * Base presentational table component.
 * Encapsulates Ant Design Table, toolbar actions, filter ribbon, column visibility,
 * and mobile filter drawer without enforcing how data is fetched or filtered.
 */
export default function BaseTable({
                                      columns: initialColumns = [],
                                      dataSource = [],
                                      total = 0,
                                      unfilteredTotal = 0,
                                      loading = false,
                                      pagination = false,
                                      filters = {},
                                      sorter = {},
                                      rowKey = "id",
                                      styles = {},
                                      title,
                                      subtitle,
                                      extraToolbarActions = null,
                                      onReload,
                                      onChange,
                                      onRemoveCondition,
                                      onClearAllFilters,
                                      onApplyMobileFilters,
                                      onClearMobileFilters,
                                      // Whether the card should expand and lock to 100% height of the parent container
                                      fillHeight = false,
                                      compactHeaderOnMobile = false,
                                      collapseActionsOnMobile = true,
                                      // Custom Ant Design Table passthrough props
                                      onRow,
                                      components,
                                      rowClassName,
                                      scroll = { x: "max-content" },
                                      sticky = false,
                                      size = "middle",
                                  }) {
    const screens = useBreakpoint();
    const isMobile = !screens.sm;

    // Tracks which column filter drawer is currently active on mobile
    const [mobileFilterCol, setMobileFilterCol] = useState(null);

    // Active column opened via ribbon or table header on desktop
    const [activeOpenColumn, setActiveOpenColumn] = useState(null);

    // Track the target rule index and a unique trigger token to guarantee re-focusing on repeat clicks
    const [targetRuleFocus, setTargetRuleFocus] = useState(null);

    // Column visibility map: { [dataIndex]: boolean }
    const [columnVisibility, setColumnVisibility] = useState(() => {
        const init = {};
        initialColumns.forEach((c) => {
            init[c.dataIndex] = !c.hidden;
        });
        return init;
    });

    const handleToggleColumnVisibility = (dataIndex, checked) => {
        setColumnVisibility((prev) => ({
            ...prev,
            [dataIndex]: checked,
        }));
    };

    // Handler invoked when clicking an active filter tag on the ribbon
    const handleEditCondition = (colDef, ruleIndex) => {
        if (!colDef) return;

        // Force a fresh focus token even if clicking the exact same rule index consecutively
        setTargetRuleFocus({ index: ruleIndex, token: Date.now() });

        if (isMobile) {
            setMobileFilterCol(colDef);
        } else {
            setActiveOpenColumn(colDef.dataIndex);
        }
    };

    const handleFilterDropdownOpenChange = (dataIndex, visible) => {
        if (visible) {
            setActiveOpenColumn(dataIndex);
        } else if (activeOpenColumn === dataIndex) {
            setActiveOpenColumn(null);
            setTargetRuleFocus(null);
        }
    };

    const hasActiveFilters = Object.keys(filters).length > 0;
    const filterPercent = unfilteredTotal > 0 ? Math.round((total / unfilteredTotal) * 100) : 0;

    // Decorate columns with sorting, badges, mobile drawer triggers and text highlighting
    const processedColumns = useProcessedColumns({
        initialColumns,
        columnVisibility,
        filters,
        sorter,
        isMobile,
        onOpenMobileFilter: (col) => {
            setTargetRuleFocus(null);
            setMobileFilterCol(col);
        },
        activeOpenColumn,
        onFilterDropdownOpenChange: handleFilterDropdownOpenChange,
        targetRuleFocus,
    });

    return (
        <Card
            styles={{
                header: {
                    height: "auto",
                    padding: isMobile
                        ? (compactHeaderOnMobile ? "8px 10px" : "12px 14px")
                        : "14px 16px",
                    whiteSpace: "normal",
                    flexShrink: 0,
                    ...styles.cardHeaderStyle,
                },
                body: {
                    padding: isMobile ? "12px 8px" : "16px 20px",
                    ...(fillHeight
                        ? {
                            flex: 1,
                            minHeight: 0,
                            display: "flex",
                            flexDirection: "column",
                            overflow: "hidden",
                        }
                        : {}),
                    ...styles.cardBodyStyle,
                },
            }}
            style={{
                width: "100%",
                ...(fillHeight
                    ? {
                        height: "100%",
                        display: "flex",
                        flexDirection: "column",
                        minHeight: 0,
                    }
                    : {}),
                boxSizing: "border-box",
                ...styles.cardStyle,
            }}
            title={
                <TableToolbar
                    title={title}
                    subtitle={subtitle}
                    isMobile={isMobile}
                    hasActiveFilters={hasActiveFilters}
                    total={total}
                    unfilteredTotal={unfilteredTotal}
                    filterPercent={filterPercent}
                    extraToolbarActions={extraToolbarActions}
                    onReload={onReload}
                    columns={initialColumns}
                    columnVisibility={columnVisibility}
                    onToggleColumnVisibility={handleToggleColumnVisibility}
                    compactHeaderOnMobile={compactHeaderOnMobile}
                    collapseActionsOnMobile={collapseActionsOnMobile}
                />
            }
        >
            {/* Active Filters Summary Ribbon with padded wrapper if card body padding is zero */}
            {hasActiveFilters && (
                <TableFilterRibbon
                    filters={filters}
                    columns={initialColumns}
                    onRemoveCondition={onRemoveCondition}
                    onClearAll={onClearAllFilters}
                    onEditCondition={handleEditCondition}
                    style={styles.tableFilterRibbonStyle}
                />
            )}

            {/* Ant Design Table container */}
            <div
                style={
                    fillHeight
                        ? { flex: 1, minHeight: 0, overflowY: "auto", overflowX: "hidden" }
                        : {}
                }
            >
                <Table
                    size={size}
                    columns={processedColumns}
                    rowKey={rowKey}
                    dataSource={dataSource}
                    pagination={
                        pagination === false
                            ? false
                            : {
                                ...pagination,
                                total,
                                responsive: true,
                                simple: isMobile,
                                showSizeChanger: !isMobile,
                                pageSizeOptions: [10, 20, 50, 100],
                                showTotal: isMobile
                                    ? undefined
                                    : (totalCount, range) => `${range[0]}-${range[1]} of ${totalCount} items`,
                                styles: {
                                    ...styles.tablePaginationInfoStyle
                                },
                                style: {
                                    ...styles.tablePaginationStyle
                                },
                            }
                    }
                    loading={loading}
                    onChange={onChange}
                    scroll={scroll}
                    sticky={sticky}
                    onRow={onRow}
                    components={components}
                    rowClassName={rowClassName}
                    style={styles.tableStyle}
                />
            </div>

            {/* Mobile Drawer for Column Filters */}
            {isMobile && (
                <TableMobileFilterDrawer
                    col={mobileFilterCol}
                    open={Boolean(mobileFilterCol)}
                    onClose={() => {
                        setMobileFilterCol(null);
                        setTargetRuleFocus(null);
                    }}
                    filters={filters}
                    targetRuleFocus={targetRuleFocus}
                    onApply={(field, rules) => {
                        onApplyMobileFilters?.(field, rules);
                        setMobileFilterCol(null);
                        setTargetRuleFocus(null);
                    }}
                    onClear={(field) => {
                        onClearMobileFilters?.(field);
                        setMobileFilterCol(null);
                        setTargetRuleFocus(null);
                    }}
                />
            )}
        </Card>
    );
}
