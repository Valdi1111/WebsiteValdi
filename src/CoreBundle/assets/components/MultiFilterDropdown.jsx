import React, { useState, useEffect } from "react";
import { Button, Select, Input, InputNumber, DatePicker, Space, Flex, Divider, Tooltip } from "antd";
import { PlusOutlined, DeleteOutlined } from "@ant-design/icons";
import { getAvailableOperators } from "./filterCatalog";

const { RangePicker } = DatePicker;

/**
 * Dropdown component supporting multiple conditions per column with operator selection.
 *
 * @param {Object} props
 * @param {Object} props.col Column configuration object
 * @param {Array} props.selectedKeys Ant Design filter keys container (rules array stored at index 0)
 * @param {Function} props.setSelectedKeys Ant Design updater function for filter keys
 * @param {Function} props.confirm Ant Design confirmation callback to trigger table reload
 * @param {Function} props.clearFilters Ant Design filter reset callback
 * @param {Function} props.close Ant Design dropdown close callback
 */
export default function MultiFilterDropdown({
                                                col,
                                                selectedKeys,
                                                setSelectedKeys,
                                                confirm,
                                                clearFilters,
                                                close,
                                            }) {
    // Resolve available operators according to column filterType and optional whitelist
    const availableOperators = getAvailableOperators(col.filterType, col.filterOperators);
    const defaultOperator = availableOperators[0]?.value || "exact";

    // Factory helper to instantiate a blank filter rule
    const createBlankRule = () => ({ operator: defaultOperator, value: null, valMax: null });

    // Derive initial rules array from selectedKeys, handling range inputs when operator is 'between'
    const getInitialRules = () => {
        if (Array.isArray(selectedKeys[0]) && selectedKeys[0].length > 0) {
            return selectedKeys[0].map((r) => {
                if (r.operator === "between" && Array.isArray(r.value)) {
                    return { operator: r.operator, value: r.value[0], valMax: r.value[1] };
                }
                return { operator: r.operator, value: r.value, valMax: null };
            });
        }
        return [createBlankRule()];
    };

    const [rules, setRules] = useState(getInitialRules);

    // Synchronize local rule state when external filter keys change (e.g. from Clear All or tag removal)
    useEffect(() => {
        setRules(getInitialRules());
    }, [JSON.stringify(selectedKeys[0])]);

    // Updates a specific property of a rule by its index
    const updateRule = (index, patch) => {
        setRules((prev) => {
            const next = [...prev];
            next[index] = { ...next[index], ...patch };
            return next;
        });
    };

    // Appends a new blank condition row to the filter rules
    const addRule = () => {
        setRules((prev) => [...prev, createBlankRule()]);
    };

    /**
     * Handles deletion or reset of a condition row.
     * When only one rule exists, it clears its values and resets the operator to default.
     * Otherwise, it removes the row entirely from the list.
     */
    const handleRemoveOrResetRule = (index) => {
        if (rules.length === 1) {
            setRules([createBlankRule()]);
            return;
        }
        setRules((prev) => prev.filter((_, i) => i !== index));
    };

    /**
     * Filters valid rules, serializes between ranges, and submits the conditions.
     * If all rules are empty, it confirms filter clearing and closes the dropdown.
     */
    const handleApply = () => {
        const validRules = rules
            .filter((r) => {
                if (["empty", "notEmpty"].includes(r.operator)) return true;
                if (r.operator === "between") return r.value !== null && r.valMax !== null;
                return r.value !== null && r.value !== "" && r.value !== undefined;
            })
            .map((r) => {
                if (r.operator === "between") {
                    return { operator: r.operator, value: [r.value, r.valMax] };
                }
                return { operator: r.operator, value: r.value };
            });

        if (validRules.length === 0) {
            // When all conditions are emptied, trigger a full reset and close dropdown
            if (clearFilters) {
                clearFilters({ confirm: true, closeDropdown: true });
            } else {
                setSelectedKeys([]);
                confirm({ closeDropdown: true });
            }
        } else {
            setSelectedKeys([validRules]);
            confirm({ closeDropdown: true });
        }
    };

    // Resets rules to default blank state and triggers Ant Design filter reset
    const handleReset = () => {
        setRules([createBlankRule()]);
        if (clearFilters) {
            clearFilters({ confirm: true, closeDropdown: true });
        } else {
            setSelectedKeys([]);
            confirm({ closeDropdown: true });
        }
    };

    return (
        <div style={{ padding: 12, minWidth: 300 }} onKeyDown={(e) => e.stopPropagation()}>
            <Flex vertical gap="small">
                {rules.map((rule, idx) => (
                    <Flex key={idx} gap="small" align="center">
                        <Select
                            size="small"
                            style={{ width: 120 }}
                            value={rule.operator}
                            onChange={(op) => updateRule(idx, { operator: op, value: null, valMax: null })}
                            options={availableOperators}
                        />

                        {/* Render specialized input based on column filterType */}
                        {!["empty", "notEmpty"].includes(rule.operator) && (
                            <>
                                {col.filterType === "number" && (
                                    rule.operator === "between" ? (
                                        <Space size={2}>
                                            <InputNumber
                                                size="small"
                                                placeholder="Min"
                                                value={rule.value}
                                                onChange={(v) => updateRule(idx, { value: v })}
                                                style={{ width: 68 }}
                                            />
                                            <InputNumber
                                                size="small"
                                                placeholder="Max"
                                                value={rule.valMax}
                                                onChange={(v) => updateRule(idx, { valMax: v })}
                                                style={{ width: 68 }}
                                            />
                                        </Space>
                                    ) : (
                                        <InputNumber
                                            size="small"
                                            placeholder="Value"
                                            value={rule.value}
                                            onChange={(v) => updateRule(idx, { value: v })}
                                            style={{ width: 120 }}
                                        />
                                    )
                                )}

                                {col.filterType === "text" && (
                                    <Input
                                        size="small"
                                        placeholder="Value..."
                                        value={rule.value || ""}
                                        onChange={(e) => updateRule(idx, { value: e.target.value })}
                                        onPressEnter={handleApply}
                                        style={{ width: 130 }}
                                    />
                                )}

                                {col.filterType === "date" && (
                                    rule.operator === "dateRange" ? (
                                        <RangePicker
                                            size="small"
                                            onChange={(_, dateStrings) =>
                                                updateRule(idx, { value: dateStrings[0] ? dateStrings : null })
                                            }
                                            style={{ width: 190 }}
                                        />
                                    ) : (
                                        <DatePicker
                                            size="small"
                                            onChange={(_, dateString) =>
                                                updateRule(idx, { value: dateString || null })
                                            }
                                            style={{ width: 130 }}
                                        />
                                    )
                                )}
                            </>
                        )}

                        {/* Delete or reset action button */}
                        <Tooltip title={rules.length === 1 ? "Clear rule" : "Delete rule"}>
                            <Button
                                size="small"
                                type="text"
                                danger
                                icon={<DeleteOutlined />}
                                onClick={() => handleRemoveOrResetRule(idx)}
                            />
                        </Tooltip>
                    </Flex>
                ))}

                <Button
                    type="dashed"
                    size="small"
                    icon={<PlusOutlined />}
                    onClick={addRule}
                    style={{ width: "100%", marginTop: 4 }}
                >
                    Add condition
                </Button>

                <Divider style={{ margin: "8px 0" }} />

                <Flex justify="space-between">
                    <Button size="small" type="link" onClick={handleReset}>
                        Reset
                    </Button>
                    <Space>
                        <Button size="small" onClick={() => close()}>
                            Close
                        </Button>
                        <Button size="small" type="primary" onClick={handleApply}>
                            Apply
                        </Button>
                    </Space>
                </Flex>
            </Flex>
        </div>
    );
}