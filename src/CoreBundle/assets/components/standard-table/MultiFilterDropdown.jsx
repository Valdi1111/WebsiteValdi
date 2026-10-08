import React, { useState, useEffect, useRef } from "react";
import { Button, Select, Input, InputNumber, DatePicker, Space, Flex, Divider, Tooltip, theme } from "antd";
import { PlusOutlined, DeleteOutlined } from "@ant-design/icons";
import { getAvailableOperators } from "@CoreBundle/components/standard-table/filterCatalog";

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
 * @param {boolean} [props.isMobile] Whether the component is rendered inside a mobile Drawer
 * @param {Object|null} [props.targetRuleFocus] Object containing { index, token } to focus and select
 */
export default function MultiFilterDropdown({
                                                col,
                                                selectedKeys = [],
                                                setSelectedKeys,
                                                confirm,
                                                clearFilters,
                                                close,
                                                isMobile = false,
                                                targetRuleFocus = null,
                                            }) {
    // Access Ant Design design tokens for consistent border and background colors
    const { token } = theme.useToken();

    // Container ref to query rule rows and focus relevant inputs
    const containerRef = useRef(null);

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

    // Focus and select text inside the target rule input whenever targetRuleFocus changes
    useEffect(() => {
        if (!targetRuleFocus || targetRuleFocus.index === null || targetRuleFocus.index === undefined) {
            return;
        }

        const targetIndex = targetRuleFocus.index;

        const attemptFocus = () => {
            if (!containerRef.current) return false;

            const rowElem = containerRef.current.querySelector(`[data-rule-index="${targetIndex}"]`);
            if (!rowElem) return false;

            rowElem.scrollIntoView({ block: "nearest", behavior: "smooth" });

            // Target exclusively the input within the value wrapper, bypassing the operator Select
            const valueWrapper = rowElem.querySelector(".filter-rule-value-input");
            if (valueWrapper) {
                const targetInput = valueWrapper.querySelector("input");
                if (targetInput) {
                    targetInput.focus();
                    if (typeof targetInput.select === "function") {
                        targetInput.select();
                    }
                    return true;
                }
            }
            return false;
        };

        // Try immediately via requestAnimationFrame, with a fallback timeout for dropdown transition
        const animFrame = requestAnimationFrame(() => {
            if (!attemptFocus()) {
                const timer = setTimeout(attemptFocus, 100);
                return () => clearTimeout(timer);
            }
        });

        return () => cancelAnimationFrame(animFrame);
    }, [targetRuleFocus]);

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
            } else if (setSelectedKeys) {
                setSelectedKeys([]);
                if (confirm) confirm({ closeDropdown: true });
            }
        } else {
            if (setSelectedKeys) {
                setSelectedKeys([validRules]);
            }
            if (confirm) {
                confirm({ closeDropdown: true });
            }
        }
        if (close) {
            close();
        }
    };

    // Resets rules to default blank state and triggers Ant Design filter reset
    const handleReset = () => {
        setRules([createBlankRule()]);
        if (clearFilters) {
            clearFilters({ confirm: true, closeDropdown: true });
        } else if (setSelectedKeys) {
            setSelectedKeys([]);
            if (confirm) confirm({ closeDropdown: true });
        }
        if (close) {
            close();
        }
    };

    return (
        <div
            ref={containerRef}
            style={{ padding: isMobile ? 0 : 12, minWidth: isMobile ? "100%" : 320 }}
            onKeyDown={(e) => e.stopPropagation()}
        >
            <Flex vertical gap="small">
                {/* Scrollable rules list container bounded to a maximum height */}
                <div
                    style={{
                        maxHeight: isMobile ? "50vh" : 260,
                        overflowY: "auto",
                        overflowX: "hidden",
                        display: "flex",
                        flexDirection: "column",
                        gap: 8,
                        paddingRight: 4,
                    }}
                >
                    {rules.map((rule, idx) => (
                        <div
                            key={idx}
                            data-rule-index={idx}
                            style={{
                                background: token.colorFillAlter || "#fafafa",
                                border: `1px solid ${token.colorBorderSecondary || "#f0f0f0"}`,
                                borderRadius: token.borderRadiusLG || 8,
                                padding: "8px 10px",
                                position: "relative",
                            }}
                        >
                            {/* Single horizontal row containing Operator, Value inputs, and Delete button */}
                            <Flex gap="small" align="center" style={{ width: "100%", flexWrap: "nowrap" }}>
                                <Select
                                    size={isMobile ? "middle" : "small"}
                                    style={{ width: isMobile ? 120 : 120, flexShrink: 0 }}
                                    value={rule.operator}
                                    onChange={(op) => updateRule(idx, { operator: op, value: null, valMax: null })}
                                    options={availableOperators}
                                />

                                {/* Render specialized input based on column filterType */}
                                {!["empty", "notEmpty"].includes(rule.operator) && (
                                    <div className="filter-rule-value-input" style={{ flex: 1, minWidth: 0 }}>
                                        {col.filterType === "number" && (
                                            rule.operator === "between" ? (
                                                <Space size={4} style={{ width: "100%", display: "flex" }}>
                                                    <InputNumber
                                                        size={isMobile ? "middle" : "small"}
                                                        placeholder="Min"
                                                        value={rule.value}
                                                        onChange={(v) => updateRule(idx, { value: v })}
                                                        style={{ width: "100%", minWidth: 0 }}
                                                    />
                                                    <InputNumber
                                                        size={isMobile ? "middle" : "small"}
                                                        placeholder="Max"
                                                        value={rule.valMax}
                                                        onChange={(v) => updateRule(idx, { valMax: v })}
                                                        style={{ width: "100%", minWidth: 0 }}
                                                    />
                                                </Space>
                                            ) : (
                                                <InputNumber
                                                    size={isMobile ? "middle" : "small"}
                                                    placeholder="Value"
                                                    value={rule.value}
                                                    onChange={(v) => updateRule(idx, { value: v })}
                                                    style={{ width: "100%" }}
                                                />
                                            )
                                        )}

                                        {col.filterType === "text" && (
                                            <Input
                                                size={isMobile ? "middle" : "small"}
                                                placeholder="Value..."
                                                value={rule.value || ""}
                                                onChange={(e) => updateRule(idx, { value: e.target.value })}
                                                onPressEnter={handleApply}
                                                style={{ width: "100%" }}
                                            />
                                        )}

                                        {col.filterType === "date" && (
                                            rule.operator === "dateRange" ? (
                                                <RangePicker
                                                    size={isMobile ? "middle" : "small"}
                                                    onChange={(_, dateStrings) =>
                                                        updateRule(idx, { value: dateStrings[0] ? dateStrings : null })
                                                    }
                                                    style={{ width: "100%" }}
                                                />
                                            ) : (
                                                <DatePicker
                                                    size={isMobile ? "middle" : "small"}
                                                    onChange={(_, dateString) =>
                                                        updateRule(idx, { value: dateString || null })
                                                    }
                                                    style={{ width: "100%" }}
                                                />
                                            )
                                        )}
                                    </div>
                                )}

                                {/* Delete or reset action button */}
                                <Tooltip title={rules.length === 1 ? "Clear rule" : "Delete rule"}>
                                    <Button
                                        size={isMobile ? "middle" : "small"}
                                        type="text"
                                        danger
                                        icon={<DeleteOutlined />}
                                        onClick={() => handleRemoveOrResetRule(idx)}
                                        style={{ flexShrink: 0 }}
                                    />
                                </Tooltip>
                            </Flex>
                        </div>
                    ))}
                </div>

                <Button
                    type="dashed"
                    size={isMobile ? "middle" : "small"}
                    icon={<PlusOutlined />}
                    onClick={addRule}
                    style={{ width: "100%", marginTop: 4 }}
                >
                    Add condition
                </Button>

                <Divider style={{ margin: "8px 0" }} />

                <Flex justify="space-between" align="center">
                    <Button size={isMobile ? "middle" : "small"} type="link" onClick={handleReset} style={{ paddingLeft: 0 }}>
                        Reset
                    </Button>
                    <Space>
                        <Button size={isMobile ? "middle" : "small"} onClick={() => close && close()}>
                            Close
                        </Button>
                        <Button size={isMobile ? "middle" : "small"} type="primary" onClick={handleApply}>
                            Apply
                        </Button>
                    </Space>
                </Flex>
            </Flex>
        </div>
    );
}
