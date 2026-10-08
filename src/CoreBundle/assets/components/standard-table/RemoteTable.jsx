import React, { useState, useEffect } from "react";
import BaseTable from "@CoreBundle/components/standard-table/BaseTable";

/**
 * Remote table component fetching data from backend APIs with server-side pagination,
 * sorting, and multi-condition filtering.
 */
export default function RemoteTable({
                                        columns: initialColumns = [],
                                        fetchData,
                                        rowKey = "id",
                                        styles = {},
                                        title,
                                        subtitle,
                                        extraToolbarActions = null,
                                        fillHeight = false,
                                        onRow,
                                        components,
                                        rowClassName,
                                        scroll,
                                        sticky,
                                        size = "middle",
                                    }) {
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
        setPagination((prev) => ({ ...prev, current: 1 }));
    };

    const handleClearMobileFilters = (field) => {
        setFilters((prev) => {
            const next = { ...prev };
            delete next[field];
            return next;
        });
        setPagination((prev) => ({ ...prev, current: 1 }));
    };

    return (
        <BaseTable
            columns={initialColumns}
            dataSource={data}
            total={total}
            unfilteredTotal={unfilteredTotal}
            loading={loading}
            pagination={pagination}
            filters={filters}
            sorter={sorter}
            rowKey={rowKey}
            styles={{
                tablePaginationStyle: {
                    marginBottom: 0,
                    ...styles.tablePaginationStyle,
                },
                ...styles
            }}
            title={title}
            subtitle={subtitle}
            extraToolbarActions={extraToolbarActions}
            fillHeight={fillHeight}
            onReload={() => loadData()}
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
