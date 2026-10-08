import React, { useState, useEffect } from "react";
import { Table, Card, Grid } from "antd";
import TableToolbar from "@CoreBundle/components/standard-table/TableToolbar";
import TableFilterRibbon from "@CoreBundle/components/standard-table/TableFilterRibbon";
import TableMobileFilterDrawer from "@CoreBundle/components/standard-table/TableMobileFilterDrawer";
import useProcessedColumns from "@CoreBundle/components/standard-table/useProcessedColumns";

const { useBreakpoint } = Grid;

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

    // Tracks which column filter drawer is currently active on mobile
    const [mobileFilterCol, setMobileFilterCol] = useState(null);

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

    // Helper handlers to apply or clear filters from the mobile Drawer
    const handleApplyMobileFilters = (field, newRuleList) => {
        setFilters((prev) => {
            const next = { ...prev };
            if (!newRuleList || newRuleList.length === 0) {
                delete next[field];
            } else {
                next[field] = newRuleList;
            }
            return next;
        });
        setPagination((prev) => ({ ...prev, current: 1 }));
        setMobileFilterCol(null);
    };

    const handleClearMobileFilters = (field) => {
        setFilters((prev) => {
            const next = { ...prev };
            delete next[field];
            return next;
        });
        setPagination((prev) => ({ ...prev, current: 1 }));
        setMobileFilterCol(null);
    };

    const handleToggleColumnVisibility = (dataIndex, checked) => {
        setColumnVisibility((prev) => ({
            ...prev,
            [dataIndex]: checked,
        }));
    };

    const hasActiveFilters = Object.keys(filters).length > 0;
    const filterPercent = unfilteredTotal > 0 ? Math.round((total / unfilteredTotal) * 100) : 0;

    // Decorate columns via dedicated custom hook
    const processedColumns = useProcessedColumns({
        initialColumns,
        columnVisibility,
        filters,
        sorter,
        isMobile,
        onOpenMobileFilter: (col) => setMobileFilterCol(col),
    });

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
                <TableToolbar
                    title={title}
                    subtitle={subtitle}
                    isMobile={isMobile}
                    hasActiveFilters={hasActiveFilters}
                    total={total}
                    unfilteredTotal={unfilteredTotal}
                    filterPercent={filterPercent}
                    extraToolbarActions={extraToolbarActions}
                    onReload={() => loadData()}
                    columns={initialColumns}
                    columnVisibility={columnVisibility}
                    onToggleColumnVisibility={handleToggleColumnVisibility}
                />
            }
        >
            {/* Active Filters Summary Ribbon */}
            <TableFilterRibbon
                filters={filters}
                columns={initialColumns}
                onRemoveCondition={handleRemoveCondition}
                onClearAll={handleClearAllFilters}
            />

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

            {/* Mobile Drawer for Column Filters */}
            {isMobile && (
                <TableMobileFilterDrawer
                    col={mobileFilterCol}
                    open={Boolean(mobileFilterCol)}
                    onClose={() => setMobileFilterCol(null)}
                    filters={filters}
                    onApply={handleApplyMobileFilters}
                    onClear={handleClearMobileFilters}
                />
            )}
        </Card>
    );
}
