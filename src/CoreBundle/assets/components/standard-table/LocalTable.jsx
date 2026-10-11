import React, { useState, useMemo } from "react";
import BaseTable from "@CoreBundle/components/standard-table/BaseTable";
import { filterRecordsLocally, sortRecordsLocally } from "@CoreBundle/components/standard-table/localTableUtils";

/**
 * LocalTable component managing in-memory filtering, sorting, and pagination
 * on client-side datasets while sharing the unified BaseTable presentation.
 */
export default function LocalTable({
                                       columns: initialColumns = [],
                                       dataSource = [],
                                       rowKey = "id",
                                       loading = false,
                                       pagination: initialPagination = { current: 1, pageSize: 10 },
                                       styles = {},
                                       title,
                                       subtitle,
                                       extraToolbarActions = null,
                                       fillHeight = false,
                                       compactHeaderOnMobile = false,
                                       collapseActionsOnMobile = true,
                                       onReload,
                                       onRow,
                                       components,
                                       rowClassName,
                                       scroll,
                                       sticky,
                                       size = "middle",
                                   }) {
    const isPaginationEnabled = initialPagination !== false;

    const [pagination, setPagination] = useState(() => {
        if (!isPaginationEnabled) return false;
        return {
            current: initialPagination.current || 1,
            pageSize: initialPagination.pageSize || 10,
        };
    });

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

    // 1. Apply in-memory filtering
    const filteredData = useMemo(() => {
        return filterRecordsLocally(dataSource, filters, initialColumns);
    }, [dataSource, filters, initialColumns]);

    // 2. Apply in-memory sorting
    const sortedData = useMemo(() => {
        return sortRecordsLocally(filteredData, sorter, initialColumns);
    }, [filteredData, sorter, initialColumns]);

    // 3. Slice records for the current page if pagination is enabled
    const paginatedData = useMemo(() => {
        if (!isPaginationEnabled || !pagination) {
            return sortedData;
        }
        const { current, pageSize } = pagination;
        const start = (current - 1) * pageSize;
        return sortedData.slice(start, start + pageSize);
    }, [sortedData, pagination, isPaginationEnabled]);

    const total = filteredData.length;
    const unfilteredTotal = dataSource.length;

    /**
     * Handles table change events emitted by Ant Design (pagination, filters, sorter).
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

        if (isPaginationEnabled) {
            setPagination((prev) => ({
                ...prev,
                current: newPagination.current,
                pageSize: newPagination.pageSize,
            }));
        }

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
        if (isPaginationEnabled) {
            setPagination((prev) => ({ ...prev, current: 1 }));
        }
    };

    // Clears all active filters across every column
    const handleClearAllFilters = () => {
        setFilters({});
        if (isPaginationEnabled) {
            setPagination((prev) => ({ ...prev, current: 1 }));
        }
    };

    // Mobile filter drawer handlers
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
        if (isPaginationEnabled) {
            setPagination((prev) => ({ ...prev, current: 1 }));
        }
    };

    const handleClearMobileFilters = (field) => {
        setFilters((prev) => {
            const next = { ...prev };
            delete next[field];
            return next;
        });
        if (isPaginationEnabled) {
            setPagination((prev) => ({ ...prev, current: 1 }));
        }
    };

    return (
        <BaseTable
            columns={initialColumns}
            dataSource={paginatedData}
            total={total}
            unfilteredTotal={unfilteredTotal}
            loading={loading}
            pagination={pagination}
            filters={filters}
            sorter={sorter}
            rowKey={rowKey}
            styles={styles}
            title={title}
            subtitle={subtitle}
            extraToolbarActions={extraToolbarActions}
            fillHeight={fillHeight}
            compactHeaderOnMobile={compactHeaderOnMobile}
            collapseActionsOnMobile={collapseActionsOnMobile}
            onReload={onReload}
            onChange={handleTableChange}
            onRemoveCondition={handleRemoveCondition}
            onClearAllFilters={handleClearAllFilters}
            onApplyMobileFilters={handleApplyMobileFilters}
            onClearMobileFilters={handleClearMobileFilters}
            onRow={onRow}
            components={components}
            rowClassName={rowClassName}
            scroll={scroll}
            sticky={sticky}
            size={size}
        />
    );
}
