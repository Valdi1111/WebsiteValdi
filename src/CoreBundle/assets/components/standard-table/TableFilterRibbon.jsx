import React from "react";
import { Flex, Tag, Typography, Button } from "antd";
import { CloseCircleOutlined } from "@ant-design/icons";
import { getRuleDescription } from "@CoreBundle/components/standard-table/tableFilterUtils";

const { Text } = Typography;

/**
 * Filter summary ribbon rendered above the table to display active conditions as closable tags.
 *
 * @param {Object} props
 * @param {Object} props.filters Current active filters map
 * @param {Array<Object>} props.columns Initial column configurations for resolving column titles
 * @param {Function} props.onRemoveCondition Callback invoked when closing an individual condition tag
 * @param {Function} props.onClearAll Callback invoked to clear all active conditions
 */
export default function TableFilterRibbon({
                                              filters,
                                              columns,
                                              onRemoveCondition,
                                              onClearAll,
                                          }) {
    const hasActiveFilters = Object.keys(filters).length > 0;
    if (!hasActiveFilters) return null;

    return (
        <div style={{ marginBottom: 14 }}>
            <Flex gap="small" align="center" wrap="wrap">
                <Text type="secondary" style={{ fontSize: 12 }}>
                    Active Filters:
                </Text>
                {Object.entries(filters).flatMap(([field, rules]) => {
                    const colDef = columns.find((c) => c.dataIndex === field);
                    const colTitle = colDef ? colDef.title : field;

                    return rules.map((rule, idx) => (
                        <Tag
                            key={`${field}_${idx}`}
                            color="blue"
                            closable
                            onClose={() => onRemoveCondition(field, idx)}
                        >
                            <strong>{colTitle}</strong>: {getRuleDescription(rule, colDef)}
                        </Tag>
                    ));
                })}
                <Button
                    type="link"
                    size="small"
                    icon={<CloseCircleOutlined />}
                    onClick={onClearAll}
                    style={{ padding: 0 }}
                >
                    Clear all
                </Button>
            </Flex>
        </div>
    );
}
