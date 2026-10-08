import React from "react";
import { Drawer, Flex, Checkbox, Divider, Button } from "antd";
import MultiFilterDropdown from "@CoreBundle/components/standard-table/MultiFilterDropdown";

/**
 * Bottom-sheet drawer providing responsive filter editing for mobile screens.
 *
 * @param {Object} props
 * @param {Object|null} props.col Currently selected column object to filter
 * @param {boolean} props.open Whether the drawer is visible
 * @param {Function} props.onClose Callback invoked when closing the drawer
 * @param {Object} props.filters Active filter rules state map
 * @param {Function} props.onApply Callback invoked to apply updated filter rules for a column
 * @param {Function} props.onClear Callback invoked to clear all filter rules for a column
 * @param {Object|null} [props.targetRuleFocus] Object containing { index, token } to focus and select
 */
export default function TableMobileFilterDrawer({
                                                    col,
                                                    open,
                                                    onClose,
                                                    filters,
                                                    onApply,
                                                    onClear,
                                                    targetRuleFocus = null,
                                                }) {
    if (!col) return null;

    const isAdvancedFilter = ["text", "number", "date"].includes(col.filterType);
    const isEnumOrTagFilter = !col.filterType || col.filterType === "tags";
    const currentRules = filters[col.dataIndex];

    return (
        <Drawer
            title={`Filter: ${col.title}`}
            placement="bottom"
            open={open}
            onClose={onClose}
            destroyOnClose
            styles={{
                wrapper: {
                    height: "auto",
                },
                body: {
                    padding: 16,
                },
            }}
        >
            {/* Advanced multi-condition filter component */}
            {isAdvancedFilter && (
                <MultiFilterDropdown
                    col={col}
                    isMobile={true}
                    targetRuleFocus={targetRuleFocus}
                    selectedKeys={currentRules ? [currentRules] : []}
                    setSelectedKeys={(keys) => onApply(col.dataIndex, keys[0])}
                    confirm={onClose}
                    clearFilters={() => onClear(col.dataIndex)}
                    close={onClose}
                />
            )}

            {/* Standard Enum / Tags checkbox list rendered inside mobile Drawer */}
            {isEnumOrTagFilter && (
                <Flex vertical gap="middle">
                    <div style={{ maxHeight: "50vh", overflowY: "auto", paddingRight: 4 }}>
                        <Checkbox.Group
                            style={{ display: "flex", flexDirection: "column", gap: 8 }}
                            value={currentRules?.find((r) => r.operator === "in")?.value || []}
                            onChange={(checkedValues) => {
                                if (checkedValues.length === 0) {
                                    onClear(col.dataIndex);
                                } else {
                                    onApply(col.dataIndex, [{ operator: "in", value: checkedValues }]);
                                }
                            }}
                        >
                            {(col.filters || []).map((f) => (
                                <Checkbox key={f.value} value={f.value}>
                                    {f.text}
                                </Checkbox>
                            ))}
                        </Checkbox.Group>
                    </div>
                    <Divider style={{ margin: "8px 0" }} />
                    <Flex justify="space-between">
                        <Button type="link" onClick={() => onClear(col.dataIndex)}>
                            Reset
                        </Button>
                        <Button type="primary" onClick={onClose}>
                            Done
                        </Button>
                    </Flex>
                </Flex>
            )}
        </Drawer>
    );
}
